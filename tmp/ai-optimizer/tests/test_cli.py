"""CLI tests: exit codes, JSON output, error handling and end-to-end workflows."""

from __future__ import annotations

import json
from collections.abc import Callable
from pathlib import Path
from typing import Any

import pytest
from typer.testing import CliRunner, Result

from ai_optimizer import __version__, cli, services
from ai_optimizer.cli import app
from tests.conftest import PYTHON_NAME

Writer = Callable[[Path, str, str], Path]
runner = CliRunner()


@pytest.fixture(autouse=True)
def terminal(monkeypatch: pytest.MonkeyPatch) -> None:
    """`aiopt trust` needs a TTY; CliRunner has none, so pretend one exists."""
    monkeypatch.setattr(cli, "_stdin_is_tty", lambda: True)


def aiopt(project_dir: Path | None, *args: str, input: str | None = None) -> Result:
    prefix = ["--project-dir", str(project_dir)] if project_dir else []
    return runner.invoke(app, [*prefix, *args], input=input)


def as_json(result: Result) -> Any:
    return json.loads(result.stdout)


def add_task(root: Path, write: Writer, name: str, script: str, body: str = "") -> None:
    write(root, f"{name}.py", script)
    write(
        root,
        f".ai-optimizer/tasks/{name}.yaml",
        f"name: {name}\n{body}steps:\n  - {{name: run, command: [{PYTHON_NAME}, {name}.py]}}\n",
    )


# --- global behavior ---------------------------------------------------------------------


def test_version() -> None:
    result = runner.invoke(app, ["--version"])
    assert result.exit_code == 0 and __version__ in result.stdout


def test_help_lists_commands() -> None:
    result = runner.invoke(app, ["--help"])
    assert result.exit_code == 0
    for command in ("init", "analyze", "cache", "context", "task", "trust", "metrics", "profile"):
        assert command in result.stdout


def test_no_args_shows_usage() -> None:
    assert "Usage" in runner.invoke(app, []).output


def test_missing_project_dir_is_a_usage_error(tmp_path: Path) -> None:
    result = aiopt(tmp_path / "nope", "analyze")
    assert result.exit_code == 2
    assert "does not exist" in result.stderr


def test_errors_are_json_in_json_mode(tmp_path: Path) -> None:
    result = runner.invoke(app, ["--json", "--project-dir", str(tmp_path / "nope"), "analyze"])
    assert result.exit_code == 2
    error = as_json(result)["error"]
    assert error["type"] == "ConfigError" and error["exit_code"] == 2


def test_unexpected_errors_exit_1_without_traceback(
    project_dir: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    def boom(*_: object, **__: object) -> None:
        raise RuntimeError("kaboom")

    monkeypatch.setattr(services, "cache_status", boom)
    result = aiopt(project_dir, "cache", "status")
    assert result.exit_code == 1
    assert "Unexpected error: kaboom" in result.stderr
    assert "Traceback" not in result.output


# --- init / config -----------------------------------------------------------------------


def test_init_creates_layout_and_validate_task(project_dir: Path) -> None:
    result = aiopt(project_dir, "init")
    assert result.exit_code == 0, result.output
    base = project_dir / ".ai-optimizer"
    assert (base / "config.yaml").is_file()
    assert (base / "tasks/validate.yaml").is_file()
    assert (base / "profiles").is_dir()
    assert "cache/" in (base / ".gitignore").read_text()
    commands = (base / "tasks/validate.yaml").read_text()
    assert "pytest" in commands and "ruff" in commands


def test_init_is_idempotent_and_keeps_user_edits(project_dir: Path) -> None:
    aiopt(project_dir, "init")
    config = project_dir / ".ai-optimizer/config.yaml"
    config.write_text("project:\n  name: mine\n")
    result = aiopt(project_dir, "--json", "init")
    assert result.exit_code == 0
    assert ".ai-optimizer/config.yaml" in as_json(result)["existing"]
    assert "mine" in config.read_text()
    aiopt(project_dir, "init", "--force")
    assert "mine" not in config.read_text()


def test_init_without_known_tools_creates_no_validate_task(tmp_path: Path, write: Writer) -> None:
    write(tmp_path, "main.go", "package main\n")
    result = aiopt(tmp_path, "init")
    assert result.exit_code == 0
    assert not (tmp_path / ".ai-optimizer/tasks/validate.yaml").exists()
    assert "No lint, type-check or test tools" in result.stdout


def test_config_validate(project_dir: Path, write: Writer) -> None:
    aiopt(project_dir, "init")
    ok = aiopt(project_dir, "config", "validate")
    assert ok.exit_code == 0 and "valid" in ok.stdout
    write(project_dir, ".ai-optimizer/config.yaml", "cache:\n  auto_refresh: bogus\n")
    bad = aiopt(project_dir, "config", "validate")
    assert bad.exit_code == 2
    assert "auto_refresh" in bad.stderr


def test_unknown_config_key_names_the_file(project_dir: Path, write: Writer) -> None:
    write(project_dir, ".ai-optimizer/config.yaml", "indexx: {}\n")
    result = aiopt(project_dir, "analyze")
    assert result.exit_code == 2
    assert "config.yaml" in result.stderr and "indexx" in result.stderr


# --- analysis and cache ------------------------------------------------------------------


def test_analyze_and_project_info(project_dir: Path) -> None:
    result = aiopt(project_dir, "--json", "analyze")
    assert result.exit_code == 0
    payload = as_json(result)
    assert payload["project"]["primary_language"] == "python"
    assert (project_dir / ".ai-optimizer/cache/project-info.json").is_file()
    info = aiopt(project_dir, "project", "info")
    assert info.exit_code == 0 and "Flask" in info.stdout
    assert not (project_dir / ".env.bak").exists()


def test_cache_lifecycle(project_dir: Path, write: Writer) -> None:
    before = aiopt(project_dir, "--json", "cache", "status")
    assert as_json(before)["exists"] is False
    first = as_json(aiopt(project_dir, "--json", "cache", "update"))
    assert len(first["added"]) == 6 and first["rehashed"] == 6
    second = as_json(aiopt(project_dir, "--json", "cache", "update"))
    assert second["rehashed"] == 0 and second["hash_skipped"] == 6
    write(project_dir, "src/shop/new.py", "x = 1\n")
    stale = aiopt(project_dir, "cache", "status")
    assert "STALE" in stale.stdout
    updated = as_json(aiopt(project_dir, "--json", "cache", "update"))
    assert updated["added"] == ["src/shop/new.py"] and updated["rehashed"] == 1
    cleared = aiopt(project_dir, "cache", "clear")
    assert cleared.exit_code == 0 and "cleared" in cleared.stdout.lower()
    assert as_json(aiopt(project_dir, "--json", "cache", "status"))["exists"] is False


def test_corrupt_cache_status_and_recovery(project_dir: Path) -> None:
    aiopt(project_dir, "cache", "update")
    (project_dir / ".ai-optimizer/cache/index.db").write_bytes(b"junk" * 500)
    status = aiopt(project_dir, "cache", "status")
    assert status.exit_code == 0 and "unhealthy" in status.stdout
    rebuilt = aiopt(project_dir, "cache", "update")
    assert rebuilt.exit_code == 0 and "rebuilt" in rebuilt.stdout


# --- context -----------------------------------------------------------------------------


def test_search_output_and_json(project_dir: Path) -> None:
    human = aiopt(project_dir, "context", "search", "password reset")
    assert human.exit_code == 0
    assert "src/shop/auth.py" in human.stdout and "hunter2" not in human.stdout
    result = as_json(
        aiopt(project_dir, "--json", "context", "search", "password reset", "--limit", "1")
    )
    assert [r["path"] for r in result["results"]] == ["src/shop/auth.py"]


def test_search_usage_errors(project_dir: Path) -> None:
    assert aiopt(project_dir, "context", "search", "the of").exit_code == 2
    assert aiopt(project_dir, "context", "search", "cart", "--limit", "500").exit_code == 2
    assert aiopt(project_dir, "context", "search").exit_code == 2


def test_search_does_not_leak_sensitive_files(project_dir: Path) -> None:
    result = aiopt(project_dir, "--json", "context", "search", "hunter2 SECRET_KEY")
    assert result.exit_code == 0
    assert as_json(result)["results"] == []


def test_build_markdown_and_json(project_dir: Path) -> None:
    md = aiopt(project_dir, "context", "build", "--task", "Implement password reset")
    assert md.exit_code == 0 and md.stdout.startswith("# Context Package")
    js = aiopt(project_dir, "context", "build", "--task", "password reset", "--format", "json")
    assert json.loads(js.stdout)["files"][0]["path"] == "src/shop/auth.py"


def test_build_to_output_file(project_dir: Path) -> None:
    result = aiopt(project_dir, "context", "build", "--task", "cart", "--output", "pkg.md")
    assert result.exit_code == 0 and "estimated tokens" in result.stdout
    assert (project_dir / "pkg.md").read_text().startswith("# Context Package")
    denied = aiopt(project_dir, "context", "build", "--task", "cart", "--output", "../evil.md")
    assert denied.exit_code == 4


def test_build_requires_input_and_validates_format(project_dir: Path) -> None:
    assert aiopt(project_dir, "context", "build").exit_code == 2
    assert (
        aiopt(project_dir, "context", "build", "--task", "cart", "--format", "xml").exit_code == 2
    )
    unknown = aiopt(project_dir, "context", "build", "--profile", "nope")
    assert unknown.exit_code == 2 and "Unknown profile" in unknown.stderr


def test_build_with_budget(project_dir: Path) -> None:
    result = as_json(
        aiopt(project_dir, "--json", "context", "build", "--task", "cart", "--max-tokens", "400")
    )
    assert result["budget"]["estimated_tokens"] <= 400
    assert result["budget"]["max_tokens"] == 400


def test_profiles(project_dir: Path, write: Writer) -> None:
    listing = aiopt(project_dir, "profile", "list")
    assert listing.exit_code == 0
    for name in (
        "frontend",
        "backend",
        "database",
        "testing",
        "security",
        "refactoring",
        "code-review",
    ):
        assert name in listing.stdout
    shown = as_json(aiopt(project_dir, "--json", "profile", "show", "backend"))
    assert shown["name"] == "backend"
    write(project_dir, ".ai-optimizer/profiles/broken.yaml", "keywords: 5\n")
    broken = aiopt(project_dir, "profile", "list")
    assert broken.exit_code == 2 and "broken.yaml" in broken.stderr


# --- tasks and trust ---------------------------------------------------------------------


def test_task_run_requires_trust_then_succeeds(
    project_dir: Path, write: Writer, allow_python: None
) -> None:
    add_task(project_dir, write, "hello", "print('hi from task')\n")
    listing = aiopt(project_dir, "task", "list")
    assert "hello" in listing.stdout and "Trusted: no" in listing.stdout
    refused = aiopt(project_dir, "task", "run", "hello")
    assert refused.exit_code == 4 and "aiopt trust" in refused.stderr
    trusted = aiopt(project_dir, "trust", input="y\n")
    assert trusted.exit_code == 0, trusted.output
    assert "python" in trusted.stderr.lower() or "hello.py" in trusted.stderr
    ran = aiopt(project_dir, "task", "run", "hello")
    assert ran.exit_code == 0 and "PASSED" in ran.stdout
    assert any((project_dir / ".ai-optimizer/reports").glob("hello-*.json"))


def test_declining_trust_leaves_project_untrusted(
    project_dir: Path, write: Writer, allow_python: None
) -> None:
    add_task(project_dir, write, "hello", "print('x')\n")
    declined = aiopt(project_dir, "trust", input="n\n")
    assert declined.exit_code == 4
    assert aiopt(project_dir, "task", "run", "hello").exit_code == 4


def test_failed_task_exits_3_and_shows_error_tail(
    project_dir: Path, write: Writer, allow_python: None
) -> None:
    add_task(
        project_dir, write, "bad", "import sys\nprint('it broke', file=sys.stderr)\nsys.exit(2)\n"
    )
    aiopt(project_dir, "trust", input="y\n")
    result = aiopt(project_dir, "task", "run", "bad")
    assert result.exit_code == 3
    assert "FAILED" in result.stdout and "it broke" in result.stdout and "exit 2" in result.stdout


def test_task_run_json_report(project_dir: Path, write: Writer, allow_python: None) -> None:
    add_task(project_dir, write, "hello", "print('hi')\n")
    aiopt(project_dir, "trust", input="y\n")
    report = as_json(aiopt(project_dir, "--json", "task", "run", "hello"))
    assert report["status"] == "passed" and report["steps"][0]["exit_code"] == 0


def test_denied_command_exits_4(project_dir: Path, write: Writer) -> None:
    write(
        project_dir,
        ".ai-optimizer/tasks/evil.yaml",
        "steps:\n  - {name: s, command: [sh, -c, 'echo pwned > pwned.txt']}\n",
    )
    aiopt(project_dir, "trust", input="y\n")
    result = aiopt(project_dir, "task", "run", "evil")
    assert result.exit_code == 4 and "denied" in result.stderr
    assert not (project_dir / "pwned.txt").exists()


def test_invalid_task_exits_2(project_dir: Path, write: Writer) -> None:
    write(
        project_dir,
        ".ai-optimizer/tasks/cycle.yaml",
        "steps:\n  - {name: a, command: [x], depends_on: [b]}\n  - {name: b, command: [x], depends_on: [a]}\n",
    )
    result = aiopt(project_dir, "task", "list")
    assert result.exit_code == 2 and "cycle" in result.stderr


def test_unknown_task_and_show(project_dir: Path, write: Writer, allow_python: None) -> None:
    add_task(project_dir, write, "hello", "print('x')\n")
    assert aiopt(project_dir, "task", "show", "nope").exit_code == 2
    shown = as_json(aiopt(project_dir, "--json", "task", "show", "hello"))
    assert shown["steps"][0]["command"] == [PYTHON_NAME, "hello.py"]


def test_revoke_trust(project_dir: Path, write: Writer, allow_python: None) -> None:
    add_task(project_dir, write, "hello", "print('x')\n")
    aiopt(project_dir, "trust", input="y\n")
    assert aiopt(project_dir, "trust", "--revoke").exit_code == 0
    assert aiopt(project_dir, "task", "run", "hello").exit_code == 4


def test_trust_with_invalid_config_is_refused(project_dir: Path, write: Writer) -> None:
    write(project_dir, ".ai-optimizer/tasks/bad.yaml", "steps: []\n")
    assert aiopt(project_dir, "trust", input="y\n").exit_code == 2


# --- metrics and quiet mode --------------------------------------------------------------


def test_metrics_report(project_dir: Path, write: Writer, allow_python: None) -> None:
    add_task(project_dir, write, "hello", "print('x')\n")
    aiopt(project_dir, "cache", "update")
    aiopt(project_dir, "cache", "update")
    aiopt(project_dir, "context", "search", "cart")
    aiopt(project_dir, "context", "build", "--task", "cart")
    aiopt(project_dir, "trust", input="y\n")
    aiopt(project_dir, "task", "run", "hello")
    report = as_json(aiopt(project_dir, "--json", "metrics"))
    assert report["token_source"] == "estimated"
    assert report["provider_reported_usage"] is None
    assert report["cache"]["updates"] >= 2 and report["cache"]["files_hash_skipped"] > 0
    assert report["retrieval"]["searches"] == 1 and report["retrieval"]["packages_built"] == 1
    assert report["tasks"]["runs"] == 1 and report["tasks"]["passed"] == 1
    text = aiopt(project_dir, "metrics").stdout
    assert "estimate" in text


def test_metrics_on_empty_project(project_dir: Path) -> None:
    result = aiopt(project_dir, "metrics")
    assert result.exit_code == 0 and "n/a" in result.stdout


def test_quiet_suppresses_nonessential_output(project_dir: Path) -> None:
    assert aiopt(project_dir, "-q", "cache", "update").stdout == ""
    assert "src/shop/auth.py" in aiopt(project_dir, "-q", "context", "search", "password").stdout


def test_commands_run_from_a_subdirectory(
    project_dir: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    monkeypatch.chdir(project_dir / "src" / "shop")
    result = runner.invoke(app, ["--json", "analyze"])
    assert result.exit_code == 0
    assert (project_dir / ".ai-optimizer/cache/project-info.json").is_file()
    assert not (project_dir / "src/shop/.ai-optimizer").exists()


def test_trust_refuses_without_a_terminal(
    project_dir: Path, write: Writer, allow_python: None, monkeypatch: pytest.MonkeyPatch
) -> None:
    add_task(project_dir, write, "hello", "print('x')\n")
    monkeypatch.setattr(cli, "_stdin_is_tty", lambda: False)
    result = aiopt(project_dir, "trust", input="y\n")
    assert result.exit_code == 4 and "interactive terminal" in result.stderr
    assert aiopt(project_dir, "--json", "trust", input="y\n").exit_code == 4
    assert aiopt(project_dir, "task", "run", "hello").exit_code == 4
