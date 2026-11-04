"""Service layer: every capability as a plain function returning JSON-serializable data.

The CLI (and later the MCP server) call these functions and contain no business logic
of their own.
"""

from __future__ import annotations

import subprocess
from pathlib import Path
from typing import Any

import yaml

from ai_optimizer.cache.db import open_existing
from ai_optimizer.cache.indexer import last_changed_files, pending_changes, update_index
from ai_optimizer.cache.status import cache_status as _cache_status
from ai_optimizer.cache.status import clear_cache
from ai_optimizer.context.package import PackageInputs, build_package
from ai_optimizer.context.profiles import (
    BUILTIN_PROFILES,
    Profile,
    get_profile,
    load_profile_files,
    load_profiles,
)
from ai_optimizer.context.search import bound_hits, search_index
from ai_optimizer.context.terms import extract_terms
from ai_optimizer.core.config import (
    CONFIG_DIR_NAME,
    ProjectConfig,
    ProjectPaths,
    default_config_yaml,
    load_config,
)
from ai_optimizer.core.errors import CacheError, ConfigError, PermissionDeniedError, TrustError
from ai_optimizer.core.files import is_within
from ai_optimizer.core.log import get_logger
from ai_optimizer.core.project import Project, find_project_root
from ai_optimizer.intelligence.analyzer import (
    collect_project_data,
    load_dependencies,
    load_project_info,
    write_project_data,
)
from ai_optimizer.metrics.estimate import estimate_tokens
from ai_optimizer.metrics.store import record_event, summarize
from ai_optimizer.tasks import trust as trust_store
from ai_optimizer.tasks.executor import execute_task, prepare_task, write_report
from ai_optimizer.tasks.models import TaskDefinition, get_task, load_tasks
from ai_optimizer.tasks.permissions import DEFAULT_ALLOWED_NAMES, load_policy

log = get_logger("services")

GITIGNORE_CONTENT = "cache/\nreports/\n"
VALIDATE_ORDER = ("lint", "format", "typecheck", "test")
GIT_TIMEOUT_SECONDS = 10


# --- initialization and configuration ---------------------------------------------------


def init_project(root: Path | None = None, *, force: bool = False) -> dict[str, Any]:
    """Create ``.ai-optimizer/`` with default configuration and example files."""
    root = (root or find_project_root(Path.cwd())).resolve()
    paths = ProjectPaths(root)
    created: list[str] = []
    existing: list[str] = []

    def write(file: Path, content: str, overwrite: bool = False) -> None:
        relative = file.relative_to(root).as_posix()
        if file.exists() and not overwrite:
            existing.append(relative)
            return
        file.parent.mkdir(parents=True, exist_ok=True)
        file.write_text(content, encoding="utf-8")
        created.append(relative)

    write(paths.config_file, default_config_yaml(root.name), overwrite=force)
    write(paths.base / ".gitignore", GITIGNORE_CONTENT)
    paths.tasks_dir.mkdir(parents=True, exist_ok=True)
    paths.profiles_dir.mkdir(parents=True, exist_ok=True)

    config = load_config(paths)
    notes: list[str] = []
    validate_task = _detected_validate_task(paths, config)
    if validate_task is None:
        notes.append(
            "No lint, type-check or test tools were detected, so no 'validate' task was created. "
            f"Add one in {CONFIG_DIR_NAME}/tasks/."
        )
    else:
        write(paths.tasks_dir / "validate.yaml", yaml.safe_dump(validate_task, sort_keys=False))
    notes.append("Review the generated tasks, then run `aiopt trust` before `aiopt task run`.")
    return {"root": str(root), "created": created, "existing": existing, "notes": notes}


def _detected_validate_task(paths: ProjectPaths, config: ProjectConfig) -> dict[str, Any] | None:
    data = collect_project_data(paths.root, config)
    by_kind: dict[str, list[str]] = {}
    for command in data.project_info["dev_commands"]:
        argv = command["argv"]
        if argv[0] in DEFAULT_ALLOWED_NAMES and command["kind"] in VALIDATE_ORDER:
            by_kind.setdefault(command["kind"], argv)
    steps = [
        {"name": kind, "command": by_kind[kind], "timeout_seconds": 600}
        for kind in VALIDATE_ORDER
        if kind in by_kind
    ]
    if not steps:
        return None
    return {"name": "validate", "description": "Lint, format check, type check and tests.",
            "steps": steps}  # fmt: skip


def validate_config(project: Project) -> dict[str, Any]:
    """Validate configuration, tasks and profiles (raises ConfigError on the first problem)."""
    tasks = load_tasks(project.paths)
    profiles = load_profile_files(project.paths)
    return {
        "valid": True,
        "config_file": project.paths.config_file.exists(),
        "tasks": sorted(tasks),
        "profiles": sorted(profiles),
    }


# --- analysis ----------------------------------------------------------------------------


def analyze_project(project: Project) -> dict[str, Any]:
    """Analyze the project and write metadata into the cache directory."""
    data = collect_project_data(project.root, project.config)
    written = write_project_data(project.paths, data)
    return {
        "written": [f"{CONFIG_DIR_NAME}/cache/{name}" for name in written],
        "project": data.project_info,
        "files": data.project_map["file_count"],
    }


def project_info(project: Project) -> dict[str, Any]:
    """Return generated project info, analyzing first if it does not exist yet."""
    info = load_project_info(project.paths)
    if info is None:
        info = analyze_project(project)["project"]
    return info


# --- cache -------------------------------------------------------------------------------


def cache_update(project: Project, *, full: bool = False) -> dict[str, Any]:
    """Incrementally update the index and refresh derived metadata."""
    result = update_index(project.paths, project.config, full=full)
    metadata_missing = load_project_info(project.paths) is None
    refreshed = result.has_changes or metadata_missing
    if refreshed:
        write_project_data(project.paths, collect_project_data(project.root, project.config))
    record_event(
        project.paths,
        "cache_update",
        {
            "rehashed": result.rehashed,
            "hash_skipped": result.hash_skipped,
            "indexed_total": result.indexed_total,
            "changes": len(result.added) + len(result.changed) + len(result.removed),
            "duration_seconds": result.duration_seconds,
        },
    )
    return {**result.to_dict(), "metadata_refreshed": refreshed}


def cache_status(project: Project) -> dict[str, Any]:
    return _cache_status(project.paths, project.config)


def cache_clear(project: Project) -> dict[str, Any]:
    return {"cleared": clear_cache(project.paths)}


def ensure_cache(project: Project) -> list[str]:
    """Make sure the cache is usable according to ``cache.auto_refresh``; return warnings."""
    mode = project.config.cache.auto_refresh
    warnings: list[str] = []
    try:
        pending = pending_changes(project.paths, project.config)
        broken = False
    except CacheError:
        pending = None
        broken = True
    missing = not project.paths.db_file.is_file()
    if missing or broken:
        if mode != "refresh":
            raise CacheError("Cache is missing or unusable. Run `aiopt cache update`.")
        cache_update(project)
    elif pending is not None and pending.total:
        if mode == "refresh":
            cache_update(project)
        elif mode == "warn":
            warnings.append(
                f"Cache is stale ({pending.total} pending changes). Run `aiopt cache update`."
            )
    return warnings


# --- context -----------------------------------------------------------------------------


def search_context(project: Project, query: str, limit: int | None = None) -> dict[str, Any]:
    """Keyword search over the index with bounded, ranked results."""
    if limit is None:
        limit = project.config.context.default_limit
    if not 1 <= limit <= 100:
        raise ConfigError("--limit must be between 1 and 100.")
    terms = extract_terms(query)
    if not terms:
        raise ConfigError("The query has no searchable terms.")
    warnings = ensure_cache(project)
    conn = open_existing(project.paths.db_file)
    try:
        hits = search_index(conn, terms, candidates=limit * 5)
    finally:
        conn.close()
    kept, omitted = bound_hits(hits, limit, project.config.context.max_output_bytes)
    record_event(project.paths, "context_search", {"results": len(kept), "terms": len(terms)})
    return {
        "query": query,
        "terms": terms,
        "results": [hit.to_dict() for hit in kept],
        "omitted": omitted,
        "warnings": warnings,
    }


def build_context(
    project: Project,
    *,
    task: str | None = None,
    profile_name: str | None = None,
    max_tokens: int | None = None,
    fmt: str = "md",
    output: Path | None = None,
) -> dict[str, Any]:
    """Build a context package for a task and/or profile."""
    profile = get_profile(project.paths, profile_name) if profile_name else None
    output_file = _resolve_output(project, output)
    warnings = ensure_cache(project)
    info = load_project_info(project.paths) or analyze_project(project)["project"]
    deps_doc = load_dependencies(project.paths) or {"dependencies": []}
    tasks = [
        {"name": t.name, "description": t.description} for t in load_tasks(project.paths).values()
    ]
    conn = open_existing(project.paths.db_file)
    try:
        inputs = PackageInputs(
            project_info=info,
            dependencies=deps_doc["dependencies"],
            tasks=tasks,
            changed_files=_changed_files(project, profile, conn, warnings),
        )
        package, text = build_package(
            conn, project.paths, project.config, inputs,
            task=task, profile=profile, max_tokens=max_tokens, fmt=fmt,
        )  # fmt: skip
    finally:
        conn.close()
    package["warnings"] = [*warnings, *package["warnings"]]
    if output_file is not None:
        output_file.parent.mkdir(parents=True, exist_ok=True)
        output_file.write_text(text, encoding="utf-8")
    record_event(
        project.paths,
        "context_build",
        {
            "bytes": len(text.encode("utf-8")),
            "estimated_tokens": estimate_tokens(text),
            "files": len(package["files"]),
            "profile": package["profile"],
        },
    )
    return {
        "text": text,
        "package": package,
        "output_file": None if output_file is None else str(output_file),
    }


def _resolve_output(project: Project, output: Path | None) -> Path | None:
    if output is None:
        return None
    target = output if output.is_absolute() else project.root / output
    if not is_within(project.root, target.parent) or not is_within(project.root, target):
        raise PermissionDeniedError("--output must be inside the project directory.")
    return target.resolve()


def _changed_files(
    project: Project, profile: Profile | None, conn: Any, warnings: list[str]
) -> list[str]:
    if profile is None or not profile.changed_only:
        return []
    if profile.changed_since is None:
        return last_changed_files(conn)
    changed: set[str] = set()
    commands = (
        ["git", "diff", "--name-only", profile.changed_since, "--"],
        ["git", "ls-files", "--others", "--exclude-standard"],
    )
    for argv in commands:
        try:
            completed = subprocess.run(  # noqa: S603 - fixed argv, ref validated by the profile
                argv, cwd=project.root, capture_output=True, text=True,
                timeout=GIT_TIMEOUT_SECONDS, check=True, stdin=subprocess.DEVNULL,
            )  # fmt: skip
        except (OSError, subprocess.SubprocessError) as exc:
            warnings.append(f"Could not determine changed files with git: {exc}")
            return last_changed_files(conn)
        changed.update(line for line in completed.stdout.splitlines() if line)
    return sorted(changed)


def list_profiles(project: Project) -> dict[str, Any]:
    profiles = load_profiles(project.paths)
    project_files = load_profile_files(project.paths)
    return {
        "profiles": [
            {
                "name": name,
                "description": profiles[name].description,
                "source": "project" if name in project_files else "builtin",
                "overrides_builtin": name in project_files and name in BUILTIN_PROFILES,
            }
            for name in sorted(profiles)
        ]
    }


def show_profile(project: Project, name: str) -> dict[str, Any]:
    return get_profile(project.paths, name).model_dump()


# --- tasks -------------------------------------------------------------------------------


def list_tasks(project: Project) -> dict[str, Any]:
    tasks = load_tasks(project.paths)
    return {
        "trusted": trust_store.is_trusted(project.paths),
        "tasks": [
            {
                "name": t.name,
                "description": t.description,
                "steps": len(t.steps),
                "destructive": t.destructive,
            }
            for t in tasks.values()
        ],
    }


def show_task(project: Project, name: str) -> dict[str, Any]:
    return get_task(project.paths, name).model_dump()


def run_task(project: Project, name: str, *, allow_destructive: bool = False) -> dict[str, Any]:
    """Run a task: requires trust, validates every step first, then executes."""
    if not trust_store.is_trusted(project.paths):
        raise TrustError(
            "This project is not trusted (or its configuration changed since it was trusted). "
            "Review .ai-optimizer/ and run `aiopt trust`."
        )
    task = get_task(project.paths, name)
    policy = load_policy(project.config.tasks.allowed_commands)
    prepared = prepare_task(
        project.paths, project.config, task, policy, allow_destructive=allow_destructive
    )
    report = execute_task(task, prepared, policy, project.config)
    report["report_files"] = write_report(project.paths, report)
    record_event(
        project.paths,
        "task_run",
        {"task": name, "status": report["status"], "duration_seconds": report["duration_seconds"]},
    )
    return report


def trust_summary(project: Project) -> dict[str, Any]:
    """Describe what trusting this project would permit (shown before confirmation)."""
    tasks: dict[str, TaskDefinition] = load_tasks(project.paths)
    load_profile_files(project.paths)
    return {
        "root": str(project.root),
        "tasks": [
            {
                "name": task.name,
                "destructive": task.destructive,
                "commands": [" ".join(step.command) for step in task.ordered_steps()],
            }
            for task in tasks.values()
        ],
        "already_trusted": trust_store.is_trusted(project.paths),
    }


def trust_project(project: Project) -> dict[str, Any]:
    """Record trust for the project's current configuration."""
    load_tasks(project.paths)
    return {"trusted": True, "fingerprint": trust_store.trust_project(project.paths)}


def revoke_trust(project: Project) -> dict[str, Any]:
    return {"revoked": trust_store.revoke_trust(project.paths)}


# --- metrics -----------------------------------------------------------------------------


def metrics_report(project: Project) -> dict[str, Any]:
    return summarize(project.paths)
