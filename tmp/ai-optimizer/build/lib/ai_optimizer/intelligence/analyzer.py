"""Project analysis producing project-map, dependencies and project-info metadata."""

from __future__ import annotations

import json
import os
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from ai_optimizer.core.config import ProjectConfig, ProjectPaths
from ai_optimizer.core.files import ScanResult, scan_project
from ai_optimizer.core.languages import detect_language, is_programming_language
from ai_optimizer.intelligence import manifests as mf

SCHEMA_VERSION = 1
OUTPUT_FILES = ("project-map.json", "dependencies.json", "project-info.json")

IMPORTANT_FILES = frozenset(
    {
        "pyproject.toml", "setup.py", "setup.cfg", "requirements.txt", "Pipfile",
        "poetry.lock", "uv.lock", "package.json", "package-lock.json", "yarn.lock",
        "pnpm-lock.yaml", "tsconfig.json", "Makefile", "Dockerfile", "docker-compose.yml",
        "README.md", "AGENTS.md", "CLAUDE.md", "CONTRIBUTING.md", "LICENSE", "tox.ini",
        ".pre-commit-config.yaml", "go.mod", "Cargo.toml", "pom.xml", "build.gradle",
        "Gemfile", "composer.json",
    }
)  # fmt: skip
DIRECTORY_ROLES = {
    "src": "source", "lib": "source", "app": "source", "source": "source",
    "tests": "tests", "test": "tests", "spec": "tests", "__tests__": "tests",
    "docs": "documentation", "doc": "documentation",
    "scripts": "scripts", "bin": "scripts", "tools": "scripts",
    "examples": "examples", "example": "examples",
    "config": "configuration", "conf": "configuration",
    "migrations": "database", "static": "assets", "public": "assets", "assets": "assets",
}  # fmt: skip
EXPANDED_DIRS = frozenset({"src", "lib", "app", "packages"})


@dataclass(frozen=True, slots=True)
class ProjectData:
    """The three generated metadata documents."""

    project_map: dict[str, Any]
    dependencies: dict[str, Any]
    project_info: dict[str, Any]

    def documents(self) -> dict[str, dict[str, Any]]:
        return {
            "project-map.json": self.project_map,
            "dependencies.json": self.dependencies,
            "project-info.json": self.project_info,
        }


def _language_stats(scan: ScanResult) -> dict[str, dict[str, int]]:
    stats: dict[str, dict[str, int]] = {}
    for info in scan.files:
        language = detect_language(info.path)
        if is_programming_language(language) and language is not None:
            entry = stats.setdefault(language, {"files": 0, "bytes": 0})
            entry["files"] += 1
            entry["bytes"] += info.size
    return stats


def _directories(scan: ScanResult) -> list[dict[str, Any]]:
    counts: dict[str, int] = {}
    for info in scan.files:
        parts = info.path.split("/")[:-1]
        if not parts:
            continue
        keys = [parts[0]]
        if parts[0] in EXPANDED_DIRS and len(parts) > 1:
            keys.append("/".join(parts[:2]))
        for key in keys:
            counts[key] = counts.get(key, 0) + 1
    return [
        {
            "path": path,
            "files": counts[path],
            "role": DIRECTORY_ROLES.get(path.split("/")[-1], "other"),
        }
        for path in sorted(counts)
    ]


def _important_files(scan: ScanResult) -> list[str]:
    found = []
    for info in scan.files:
        name = info.path
        if (
            "/" not in name and (name in IMPORTANT_FILES or name.startswith("requirements"))
        ) or name.startswith(".github/workflows/"):
            found.append(name)
    return sorted(found)


def _package_managers(files: set[str], pyproject: dict[str, Any]) -> list[str]:
    managers: set[str] = set()
    tools = pyproject.get("tool", {})
    if "uv.lock" in files or "uv" in tools:
        managers.add("uv")
    if "poetry.lock" in files or "poetry" in tools:
        managers.add("poetry")
    if "Pipfile" in files:
        managers.add("pipenv")
    has_requirements = any(f.startswith("requirements") and "/" not in f for f in files)
    if has_requirements or ("pyproject.toml" in files and not managers):
        managers.add("pip")
    if "package.json" in files:
        if "yarn.lock" in files:
            managers.add("yarn")
        elif "pnpm-lock.yaml" in files:
            managers.add("pnpm")
        else:
            managers.add("npm")
    if "go.mod" in files:
        managers.add("go")
    if "Cargo.toml" in files:
        managers.add("cargo")
    return sorted(managers)


def _frameworks(dependencies: list[mf.Dependency]) -> list[dict[str, str]]:
    found: dict[tuple[str, str], dict[str, str]] = {}
    for dep in dependencies:
        table = mf.PYTHON_FRAMEWORKS if dep["ecosystem"] == "python" else mf.JS_FRAMEWORKS
        match = table.get(dep["name"])
        if match:
            found[(match[0], dep["ecosystem"])] = {
                "name": match[0],
                "category": match[1],
                "ecosystem": dep["ecosystem"],
                "evidence": dep["name"],
            }
    return [found[key] for key in sorted(found)]


def collect_project_data(root: Path, config: ProjectConfig) -> ProjectData:
    """Analyze the project without modifying it. Output is deterministic."""
    root = root.resolve()
    scan = scan_project(root, config.index)
    files = {info.path for info in scan.files}
    warnings: list[str] = []
    dependencies: list[mf.Dependency] = []
    pyproject: dict[str, Any] = {}
    package_json: dict[str, Any] = {}

    if "pyproject.toml" in files:
        try:
            pyproject = mf.load_toml(root / "pyproject.toml")
            dependencies += mf.pyproject_dependencies(pyproject)
        except ValueError as exc:
            warnings.append(f"Could not parse {exc}")
    for name in sorted(f for f in files if f.startswith("requirements") and f.endswith(".txt")):
        try:
            dependencies += mf.requirements_dependencies(root / name, name)
        except ValueError as exc:
            warnings.append(f"Could not parse {exc}")
    if "package.json" in files:
        try:
            package_json = mf.load_json(root / "package.json")
            dependencies += mf.package_json_dependencies(package_json)
        except ValueError as exc:
            warnings.append(f"Could not parse {exc}")

    dependencies.sort(key=lambda d: (d["ecosystem"], d["scope"], d["name"], d["source"]))
    managers = _package_managers(files, pyproject)
    js_runner = next((m for m in ("yarn", "pnpm", "npm") if m in managers), "npm")

    commands: list[mf.DevCommand] = []
    python_names = {d["name"] for d in dependencies if d["ecosystem"] == "python"}
    if pyproject:
        commands += mf.python_tool_commands(pyproject, python_names)
    if package_json:
        commands += mf.package_json_commands(package_json, js_runner)
    if "Makefile" in files:
        commands += mf.makefile_commands(root / "Makefile")

    languages = _language_stats(scan)
    ranked = sorted(languages, key=lambda lang: (-languages[lang]["files"], lang))
    name = (
        config.project.name
        or str(pyproject.get("project", {}).get("name") or "")
        or str(package_json.get("name") or "")
        or root.name
    )
    important = _important_files(scan)

    project_map = {
        "schema_version": SCHEMA_VERSION,
        "name": name,
        "file_count": len(scan.files),
        "total_bytes": sum(info.size for info in scan.files),
        "languages": languages,
        "directories": _directories(scan),
        "important_files": important,
        "skipped": dict(sorted(scan.skipped.items())),
    }
    dependency_doc = {
        "schema_version": SCHEMA_VERSION,
        "count": len(dependencies),
        "dependencies": dependencies,
    }
    project_info = {
        "schema_version": SCHEMA_VERSION,
        "name": name,
        "primary_language": ranked[0] if ranked else None,
        "languages": ranked,
        "package_managers": managers,
        "frameworks": _frameworks(dependencies),
        "dev_commands": commands,
        "important_files": important,
        "warnings": warnings,
    }
    return ProjectData(project_map, dependency_doc, project_info)


def serialize(document: dict[str, Any]) -> str:
    """Canonical JSON serialization used for all generated metadata."""
    return json.dumps(document, indent=2, sort_keys=True, ensure_ascii=False) + "\n"


def write_project_data(paths: ProjectPaths, data: ProjectData) -> list[str]:
    """Write metadata documents atomically into the cache directory."""
    paths.cache_dir.mkdir(parents=True, exist_ok=True)
    written = []
    for filename, document in data.documents().items():
        target = paths.cache_dir / filename
        temp = target.with_suffix(".json.tmp")
        temp.write_text(serialize(document), encoding="utf-8")
        os.replace(temp, target)
        written.append(filename)
    return written


def load_project_info(paths: ProjectPaths) -> dict[str, Any] | None:
    """Load generated project-info.json if present and valid."""
    return _load_document(paths, "project-info.json")


def load_dependencies(paths: ProjectPaths) -> dict[str, Any] | None:
    """Load generated dependencies.json if present and valid."""
    return _load_document(paths, "dependencies.json")


def _load_document(paths: ProjectPaths, filename: str) -> dict[str, Any] | None:
    try:
        data = json.loads((paths.cache_dir / filename).read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return None
    return data if isinstance(data, dict) and data.get("schema_version") == SCHEMA_VERSION else None
