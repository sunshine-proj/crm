"""Parsers for dependency manifests and development command detection.

All parsing is read-only: detected commands are reported, never executed.
"""

from __future__ import annotations

import json
import re
import tomllib
from pathlib import Path
from typing import Any

Dependency = dict[str, str]
DevCommand = dict[str, Any]

_REQUIREMENT = re.compile(r"^\s*([A-Za-z0-9][A-Za-z0-9._-]*)\s*(?:\[[^\]]*\])?\s*(.*)$")
_MAKE_TARGET = re.compile(r"^([A-Za-z][A-Za-z0-9_-]*)\s*:(?![=])", re.MULTILINE)
_DEV_GROUPS = frozenset({"dev", "test", "tests", "lint", "typing", "docs", "doc"})

PYTHON_FRAMEWORKS = {
    "django": ("Django", "web"),
    "flask": ("Flask", "web"),
    "fastapi": ("FastAPI", "web"),
    "starlette": ("Starlette", "web"),
    "tornado": ("Tornado", "web"),
    "aiohttp": ("aiohttp", "web"),
    "sanic": ("Sanic", "web"),
    "pyramid": ("Pyramid", "web"),
    "typer": ("Typer", "cli"),
    "click": ("Click", "cli"),
    "sqlalchemy": ("SQLAlchemy", "orm"),
    "celery": ("Celery", "tasks"),
    "pytest": ("pytest", "testing"),
}
JS_FRAMEWORKS = {
    "react": ("React", "frontend"),
    "next": ("Next.js", "frontend"),
    "vue": ("Vue", "frontend"),
    "nuxt": ("Nuxt", "frontend"),
    "svelte": ("Svelte", "frontend"),
    "@angular/core": ("Angular", "frontend"),
    "express": ("Express", "web"),
    "fastify": ("Fastify", "web"),
    "@nestjs/core": ("NestJS", "web"),
    "jest": ("Jest", "testing"),
    "vitest": ("Vitest", "testing"),
    "vite": ("Vite", "build"),
    "webpack": ("webpack", "build"),
    "tailwindcss": ("Tailwind CSS", "styling"),
}


def normalize_name(name: str) -> str:
    """Normalize a Python distribution name per PEP 503."""
    return re.sub(r"[-_.]+", "-", name).lower()


def parse_requirement(spec: str) -> tuple[str, str] | None:
    """Parse a PEP 508-style requirement into (name, version specifier)."""
    cleaned = spec.split(";")[0].split("#")[0].strip()
    if not cleaned or cleaned.startswith(("-", ".", "/", "git+", "http")):
        return None
    match = _REQUIREMENT.match(cleaned)
    if match is None:
        return None
    version = match.group(2).strip() or "*"
    return normalize_name(match.group(1)), version


def _dep(name: str, version: str, ecosystem: str, scope: str, source: str) -> Dependency:
    return {
        "name": name,
        "version": version,
        "ecosystem": ecosystem,
        "scope": scope,
        "source": source,
    }


def load_toml(path: Path) -> dict[str, Any]:
    """Read a TOML file; raises ValueError for unreadable or invalid content."""
    try:
        with path.open("rb") as handle:
            return tomllib.load(handle)
    except (OSError, tomllib.TOMLDecodeError) as exc:
        raise ValueError(f"{path.name}: {exc}") from exc


def load_json(path: Path) -> dict[str, Any]:
    """Read a JSON object; raises ValueError for unreadable or invalid content."""
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError, UnicodeDecodeError) as exc:
        raise ValueError(f"{path.name}: {exc}") from exc
    if not isinstance(data, dict):
        raise ValueError(f"{path.name}: expected a JSON object")
    return data


def pyproject_dependencies(data: dict[str, Any]) -> list[Dependency]:
    """Extract dependencies from PEP 621, PEP 735 and Poetry tables."""
    source = "pyproject.toml"
    deps: list[Dependency] = []
    project = data.get("project", {})
    for spec in project.get("dependencies", []):
        parsed = parse_requirement(str(spec))
        if parsed:
            deps.append(_dep(parsed[0], parsed[1], "python", "runtime", source))
    for group, specs in project.get("optional-dependencies", {}).items():
        scope = "dev" if group in _DEV_GROUPS else f"optional:{group}"
        for spec in specs:
            parsed = parse_requirement(str(spec))
            if parsed:
                deps.append(_dep(parsed[0], parsed[1], "python", scope, source))
    for group, specs in data.get("dependency-groups", {}).items():
        scope = "dev" if group in _DEV_GROUPS else f"group:{group}"
        for spec in specs:
            if isinstance(spec, str):
                parsed = parse_requirement(spec)
                if parsed:
                    deps.append(_dep(parsed[0], parsed[1], "python", scope, source))
    poetry = data.get("tool", {}).get("poetry", {})
    groups: list[tuple[str, dict[str, Any]]] = [
        ("runtime", poetry.get("dependencies", {})),
        ("dev", poetry.get("dev-dependencies", {})),
    ]
    for group, table in poetry.get("group", {}).items():
        groups.append(("runtime" if group == "main" else "dev", table.get("dependencies", {})))
    for scope, table in groups:
        for name, value in table.items():
            if name.lower() == "python":
                continue
            version = value if isinstance(value, str) else str(value.get("version", "*"))
            deps.append(_dep(normalize_name(name), version, "python", scope, source))
    return deps


def requirements_dependencies(path: Path, source: str) -> list[Dependency]:
    """Extract dependencies from a requirements-style file."""
    scope = "dev" if re.search(r"dev|test", path.name) else "runtime"
    deps: list[Dependency] = []
    try:
        lines = path.read_text(encoding="utf-8", errors="replace").splitlines()
    except OSError as exc:
        raise ValueError(f"{path.name}: {exc}") from exc
    for line in lines:
        parsed = parse_requirement(line)
        if parsed:
            deps.append(_dep(parsed[0], parsed[1], "python", scope, source))
    return deps


def package_json_dependencies(data: dict[str, Any]) -> list[Dependency]:
    """Extract dependencies from package.json."""
    scopes = {
        "dependencies": "runtime",
        "devDependencies": "dev",
        "peerDependencies": "peer",
        "optionalDependencies": "optional",
    }
    deps: list[Dependency] = []
    for key, scope in scopes.items():
        table = data.get(key, {})
        if isinstance(table, dict):
            for name, version in table.items():
                deps.append(_dep(str(name), str(version), "javascript", scope, "package.json"))
    return deps


def _script_kind(name: str) -> str:
    lowered = name.lower()
    for kind, needles in (
        ("test", ("test", "spec")),
        ("lint", ("lint",)),
        ("format", ("format", "prettier", "fmt")),
        ("typecheck", ("typecheck", "tsc", "types")),
        ("build", ("build", "compile")),
    ):
        if any(needle in lowered for needle in needles):
            return kind
    return "other"


def _command(argv: list[str], kind: str, source: str) -> DevCommand:
    return {"command": " ".join(argv), "argv": argv, "kind": kind, "source": source}


def package_json_commands(data: dict[str, Any], runner: str) -> list[DevCommand]:
    """Report package.json scripts as run commands for the detected package manager."""
    scripts = data.get("scripts", {})
    if not isinstance(scripts, dict):
        return []
    commands: list[DevCommand] = []
    for name in scripts:
        prefix = {"yarn": ["yarn"], "pnpm": ["pnpm", "run"]}.get(runner, ["npm", "run"])
        commands.append(_command([*prefix, str(name)], _script_kind(str(name)), "package.json"))
    return commands


def makefile_commands(path: Path) -> list[DevCommand]:
    """Report Makefile targets as ``make <target>`` commands."""
    try:
        text = path.read_text(encoding="utf-8", errors="replace")
    except OSError:
        return []
    targets = dict.fromkeys(_MAKE_TARGET.findall(text))
    return [_command(["make", target], _script_kind(target), "Makefile") for target in targets]


def python_tool_commands(data: dict[str, Any], dependency_names: set[str]) -> list[DevCommand]:
    """Infer standard Python tool invocations from tool tables and dependencies."""
    tools = data.get("tool", {})
    source = "pyproject.toml"
    commands: list[DevCommand] = []
    if "pytest" in tools or "pytest" in dependency_names:
        commands.append(_command(["pytest"], "test", source))
    if "ruff" in tools or "ruff" in dependency_names:
        commands.append(_command(["ruff", "check", "."], "lint", source))
        commands.append(_command(["ruff", "format", "--check", "."], "format", source))
    if "mypy" in tools or "mypy" in dependency_names:
        configured = isinstance(tools.get("mypy"), dict) and "files" in tools["mypy"]
        commands.append(_command(["mypy"] if configured else ["mypy", "."], "typecheck", source))
    return commands
