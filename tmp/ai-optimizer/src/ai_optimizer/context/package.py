"""Compact context package generation, rendering and budget fitting."""

from __future__ import annotations

import json
import re
import sqlite3
from dataclasses import dataclass, field
from pathlib import PurePosixPath
from typing import Any

from ai_optimizer.context.profiles import Profile
from ai_optimizer.context.search import Hit, bound_hits, search_index
from ai_optimizer.context.terms import MAX_TERMS, extract_terms
from ai_optimizer.core.config import ProjectConfig, ProjectPaths
from ai_optimizer.core.errors import ConfigError
from ai_optimizer.core.files import is_sensitive_name, is_within
from ai_optimizer.core.redact import redact
from ai_optimizer.intelligence.manifests import normalize_name
from ai_optimizer.metrics.estimate import ESTIMATION_METHOD, estimate_tokens

SCHEMA_VERSION = 1
CONVENTION_CHARS = 4000
MAX_DEPENDENCIES = 15
MAX_DOCS = 5
MAX_DETECTED_COMMANDS = 10
BASE_SCORE = 0.1

_PY_IMPORT = re.compile(r"^\s*(?:from|import)\s+([A-Za-z_]\w*)", re.MULTILINE)
_JS_IMPORT = re.compile(r"""(?:from\s+|require\(\s*)['"]([^'"./][^'"]*)['"]""")


@dataclass(slots=True)
class PackageInputs:
    """Project metadata supplied by the caller (keeps this module independent of analysis)."""

    project_info: dict[str, Any] = field(default_factory=dict)
    dependencies: list[dict[str, str]] = field(default_factory=list)
    tasks: list[dict[str, str]] = field(default_factory=list)
    changed_files: list[str] = field(default_factory=list)


def _file_row(conn: sqlite3.Connection, path: str) -> sqlite3.Row | None:
    row: sqlite3.Row | None = conn.execute(
        "SELECT path, kind, language FROM files WHERE path = ? AND status = 'indexed'", (path,)
    ).fetchone()
    return row


def _changed_hits(conn: sqlite3.Connection, changed: list[str]) -> list[Hit]:
    hits: dict[str, Hit] = {}
    stems: list[str] = []
    for path in changed:
        row = _file_row(conn, path)
        if row is None:
            continue
        hits[path] = Hit(path, row["kind"], row["language"], 10.0, "")
        if row["kind"] != "test":
            stems.append(PurePosixPath(path).stem)
    for stem in dict.fromkeys(stems):
        pattern = "%" + stem.replace("\\", "\\\\").replace("%", "\\%").replace("_", "\\_") + "%"
        for row in conn.execute(
            "SELECT path, kind, language FROM files WHERE kind = 'test' AND status = 'indexed' "
            "AND path LIKE ? ESCAPE '\\' ORDER BY path LIMIT 5",
            (pattern,),
        ):
            hits.setdefault(row["path"], Hit(row["path"], row["kind"], row["language"], 5.0, ""))
    doc_terms = [t for stem in dict.fromkeys(stems) for t in extract_terms(stem, 3)][:5]
    docs = [h for h in search_index(conn, doc_terms, candidates=30) if h.kind == "doc"][:3]
    for hit in docs:
        hit.score = 2.0
        hits.setdefault(hit.path, hit)
    return list(hits.values())


def _conventions(
    paths: ProjectPaths, config: ProjectConfig, profile: Profile | None
) -> list[dict[str, str]]:
    names = list(config.context.convention_files)
    if profile:
        names += profile.convention_files
    found: list[dict[str, str]] = []
    for name in dict.fromkeys(names):
        file = paths.root / name
        if not file.is_file() or not is_within(paths.root, file):
            continue
        if is_sensitive_name(file.name, config.index.extra_sensitive):
            continue
        try:
            text = file.read_text(encoding="utf-8", errors="replace")
        except OSError:
            continue
        if len(text) > CONVENTION_CHARS:
            text = text[:CONVENTION_CHARS] + "\n...[truncated]"
        found.append({"source": name, "content": redact(text)})
    return found


def _imports(conn: sqlite3.Connection, paths: list[str]) -> set[str]:
    modules: set[str] = set()
    for path in paths:
        row = conn.execute(
            "SELECT content FROM file_fts WHERE rowid = (SELECT id FROM files WHERE path = ?)",
            (path,),
        ).fetchone()
        if row is None:
            continue
        text = str(row["content"])
        modules.update(normalize_name(m) for m in _PY_IMPORT.findall(text))
        for spec in _JS_IMPORT.findall(text):
            parts = spec.split("/")
            modules.add(normalize_name("/".join(parts[:2] if spec.startswith("@") else parts[:1])))
    return modules


def _relevant_dependencies(
    dependencies: list[dict[str, str]], terms: list[str], imported: set[str]
) -> list[dict[str, str]]:
    wanted = imported | {normalize_name(t) for t in terms}
    chosen: list[dict[str, str]] = []
    seen: set[str] = set()
    for dep in dependencies:
        normalized = normalize_name(dep["name"])
        if normalized in wanted and normalized not in seen:
            seen.add(normalized)
            chosen.append({k: dep[k] for k in ("name", "version", "ecosystem", "scope")})
    return chosen[:MAX_DEPENDENCIES]


def build_package(
    conn: sqlite3.Connection,
    paths: ProjectPaths,
    config: ProjectConfig,
    inputs: PackageInputs,
    *,
    task: str | None,
    profile: Profile | None,
    max_tokens: int | None,
    fmt: str = "md",
) -> tuple[dict[str, Any], str]:
    """Build a package and its rendered text, fitted to the token budget."""
    if not task and profile is None:
        raise ConfigError("Provide --task and/or --profile.")
    if fmt not in {"md", "json"}:
        raise ConfigError("Format must be 'md' or 'json'.")
    warnings: list[str] = []
    terms = extract_terms(task) if task else []
    if profile:
        for keyword in profile.keywords:
            for term in extract_terms(keyword, 1):
                if term not in terms and len(terms) < MAX_TERMS:
                    terms.append(term)
    limit = config.context.default_limit

    hits: list[Hit] = []
    if profile and profile.changed_only:
        hits = _changed_hits(conn, inputs.changed_files)
        if not hits:
            warnings.append(
                "No changed files are recorded. Run `aiopt cache update` after making changes "
                "or set `changed_since` in the profile."
            )
    if terms:
        known = {h.path for h in hits}
        hits += [h for h in search_index(conn, terms, candidates=limit * 20) if h.path not in known]
    elif not hits and not (profile and profile.changed_only):
        raise ConfigError("The task has no searchable terms.")

    weights = profile.kind_weights if profile else {}
    ranked = [h for h in hits if profile is None or profile.accepts(h.path)]
    top = max((h.score for h in ranked), default=0.0)
    for hit in ranked:
        # Scale to 0-1 per query and add a base so weights can reorder weak matches.
        relative = hit.score / top if top > 0 else 0.0
        hit.score = (relative + BASE_SCORE) * weights.get(hit.kind, 1.0)
    ranked.sort(key=lambda h: (-h.score, h.path))

    docs = [h for h in ranked if h.kind == "doc"][:MAX_DOCS]
    files, _ = bound_hits(
        [h for h in ranked if h.kind != "doc"], limit, config.context.max_output_bytes
    )
    info = inputs.project_info
    package: dict[str, Any] = {
        "schema_version": SCHEMA_VERSION,
        "task": task,
        "profile": profile.name if profile else None,
        "terms": terms,
        "project": {
            "name": info.get("name"),
            "languages": info.get("languages", []),
            "frameworks": [f["name"] for f in info.get("frameworks", [])],
            "package_managers": info.get("package_managers", []),
        },
        "files": [h.to_dict() for h in files],
        "documentation": [h.to_dict() for h in docs],
        "conventions": _conventions(paths, config, profile),
        "dependencies": _relevant_dependencies(
            inputs.dependencies, terms, _imports(conn, [h.path for h in files])
        ),
        "validation": {
            "tasks": inputs.tasks,
            "detected_commands": [
                {"command": c["command"], "kind": c["kind"]} for c in info.get("dev_commands", [])
            ][:MAX_DETECTED_COMMANDS],
        },
        "warnings": warnings,
    }
    budget = (
        max_tokens or (profile.max_tokens if profile else None) or config.context.default_max_tokens
    )
    return _fit(package, budget, fmt)


def render(package: dict[str, Any], fmt: str) -> str:
    return render_json(package) if fmt == "json" else render_markdown(package)


def render_json(package: dict[str, Any]) -> str:
    return json.dumps(package, indent=2, sort_keys=True, ensure_ascii=False) + "\n"


def render_markdown(package: dict[str, Any]) -> str:
    """Render a package as Markdown."""
    lines = ["# Context Package", ""]
    if package["task"]:
        lines += ["## Task", package["task"], ""]
    if package["profile"]:
        lines += [f"Profile: `{package['profile']}`", ""]
    project = package["project"]
    lines += ["## Project", f"- Name: {project['name']}"]
    for label, key in (("Languages", "languages"), ("Frameworks", "frameworks"),
                       ("Package managers", "package_managers")):  # fmt: skip
        if project[key]:
            lines.append(f"- {label}: {', '.join(project[key])}")
    lines.append("")
    for title, key in (("Relevant Files", "files"), ("Related Documentation", "documentation")):
        if package[key]:
            lines.append(f"## {title}")
            for hit in package[key]:
                suffix = f" — {hit['excerpt']}" if hit["excerpt"] else ""
                lines.append(f"- `{hit['path']}` ({hit['kind']}, score {hit['score']}){suffix}")
            lines.append("")
    if package["conventions"]:
        lines.append("## Conventions")
        for item in package["conventions"]:
            lines += [f"### {item['source']}", item["content"].rstrip(), ""]
    if package["dependencies"]:
        lines.append("## Relevant Dependencies")
        lines += [f"- {d['name']} {d['version']} ({d['ecosystem']}, {d['scope']})"
                  for d in package["dependencies"]]  # fmt: skip
        lines.append("")
    validation = package["validation"]
    if validation["tasks"] or validation["detected_commands"]:
        lines.append("## Validation Commands")
        lines += [
            f"- task `{t['name']}`: {t['description']}".rstrip(": ") for t in validation["tasks"]
        ]
        lines += [
            f"- detected: `{c['command']}` ({c['kind']})" for c in validation["detected_commands"]
        ]
        lines.append("")
    for warning in package["warnings"]:
        lines.append(f"> Warning: {warning}")
    budget = package.get("budget")
    if budget:
        lines.append("")
        omitted = ", ".join(f"{k}: {v}" for k, v in budget["omitted"].items() if v) or "nothing"
        lines += [
            "## Budget",
            f"- Estimated tokens: {budget['estimated_tokens']} of {budget['max_tokens']} "
            f"({budget['estimation_method']})",
            f"- Omitted to fit budget: {omitted}",
        ]
    return "\n".join(lines).rstrip() + "\n"


def _fit(package: dict[str, Any], max_tokens: int, fmt: str) -> tuple[dict[str, Any], str]:
    """Drop lowest-priority content until the rendered package fits the budget."""
    omitted = {"dependencies": 0, "documentation": 0, "files": 0, "conventions": 0}

    def set_budget(estimated: int) -> None:
        package["budget"] = {
            "max_tokens": max_tokens,
            "estimated_tokens": estimated,
            "estimation_method": ESTIMATION_METHOD,
            "omitted": dict(omitted),
        }

    set_budget(0)
    while estimate_tokens(render(package, fmt)) > max_tokens:
        for key, floor in (("dependencies", 0), ("documentation", 0), ("files", 1)):
            if len(package[key]) > floor:
                package[key].pop()
                omitted[key] += 1
                break
        else:
            if not _shrink_conventions(package, omitted):
                package["warnings"].append(
                    f"Budget of {max_tokens} tokens is too small for the minimum package."
                )
                break
        set_budget(0)
    text = render(package, fmt)
    for _ in range(3):  # converge on the final figure, which itself appears in the output
        set_budget(estimate_tokens(text))
        text = render(package, fmt)
    return package, text


def _shrink_conventions(package: dict[str, Any], omitted: dict[str, int]) -> bool:
    items = package["conventions"]
    if not items:
        return False
    longest = max(items, key=lambda i: len(i["content"]))
    if len(longest["content"]) > 400:
        longest["content"] = longest["content"][: len(longest["content"]) // 2] + "\n...[truncated]"
        return True
    items.pop()
    omitted["conventions"] += 1
    return True
