"""Project analysis: detection, determinism and non-modification of the target."""

from __future__ import annotations

import json
from collections.abc import Callable
from pathlib import Path

from ai_optimizer.core.config import ProjectConfig, ProjectPaths
from ai_optimizer.intelligence.analyzer import (
    OUTPUT_FILES,
    collect_project_data,
    write_project_data,
)

Writer = Callable[[Path, str, str], Path]


def snapshot(root: Path) -> dict[str, str]:
    return {
        p.relative_to(root).as_posix(): p.read_text()
        for p in sorted(root.rglob("*"))
        if p.is_file()
    }


def test_detects_python_project(project_dir: Path) -> None:
    data = collect_project_data(project_dir, ProjectConfig())
    info = data.project_info
    assert info["name"] == "shop"
    assert info["primary_language"] == "python"
    assert info["package_managers"] == ["pip"]
    assert {f["name"] for f in info["frameworks"]} >= {"Flask", "pytest"}
    commands = {c["command"] for c in info["dev_commands"]}
    assert {"pytest", "ruff check ."} <= commands
    names = {d["name"]: d for d in data.dependencies["dependencies"]}
    assert names["flask"]["version"] == ">=3"
    assert names["flask"]["scope"] == "runtime"
    assert names["pytest"]["scope"] == "dev"


def test_detects_javascript_project(tmp_path: Path, write: Writer) -> None:
    write(
        tmp_path,
        "package.json",
        json.dumps(
            {
                "name": "web",
                "dependencies": {"react": "^18"},
                "devDependencies": {"vitest": "^1"},
                "scripts": {"test": "vitest", "build": "vite build", "start": "node ."},
            }
        ),
    )
    write(tmp_path, "yarn.lock", "")
    write(tmp_path, "src/app.tsx", "export const App = () => null;\n")
    info = collect_project_data(tmp_path, ProjectConfig()).project_info
    assert info["package_managers"] == ["yarn"]
    assert info["primary_language"] == "typescript"
    assert {f["name"] for f in info["frameworks"]} == {"React", "Vitest"}
    kinds = {c["command"]: c["kind"] for c in info["dev_commands"]}
    assert kinds["yarn test"] == "test"
    assert kinds["yarn build"] == "build"


def test_makefile_and_requirements(tmp_path: Path, write: Writer) -> None:
    write(tmp_path, "Makefile", "test:\n\tpytest\n\nlint:\n\truff check .\nVAR := 1\n")
    write(
        tmp_path,
        "requirements.txt",
        "# c\nDjango>=4.2\n-r other.txt\nrequests[security]==2.0 ; python_version>'3'\n",
    )
    data = collect_project_data(tmp_path, ProjectConfig())
    commands = {c["command"] for c in data.project_info["dev_commands"]}
    assert {"make test", "make lint"} <= commands
    deps = {d["name"]: d["version"] for d in data.dependencies["dependencies"]}
    assert deps == {"django": ">=4.2", "requests": "==2.0"}


def test_malformed_manifest_becomes_warning_not_crash(tmp_path: Path, write: Writer) -> None:
    write(tmp_path, "pyproject.toml", "this is = = not toml")
    write(tmp_path, "package.json", "{broken")
    info = collect_project_data(tmp_path, ProjectConfig()).project_info
    assert len(info["warnings"]) == 2


def test_analysis_is_deterministic_and_does_not_modify_project(project_dir: Path) -> None:
    paths = ProjectPaths(project_dir)
    before = snapshot(project_dir)
    write_project_data(paths, collect_project_data(project_dir, ProjectConfig()))
    first = {n: (paths.cache_dir / n).read_bytes() for n in OUTPUT_FILES}
    write_project_data(paths, collect_project_data(project_dir, ProjectConfig()))
    second = {n: (paths.cache_dir / n).read_bytes() for n in OUTPUT_FILES}
    assert first == second
    after = snapshot(project_dir)
    new_files = set(after) - set(before)
    assert all(name.startswith(".ai-optimizer/") for name in new_files)
    assert {k: v for k, v in after.items() if k in before} == before
    for name in OUTPUT_FILES:
        assert json.loads(second[name])["schema_version"] == 1
        assert b"\n" in second[name] and second[name].endswith(b"\n")


def test_project_name_prefers_config(project_dir: Path) -> None:
    config = ProjectConfig.model_validate({"project": {"name": "custom"}})
    assert collect_project_data(project_dir, config).project_info["name"] == "custom"
