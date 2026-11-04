"""Integration tests: skills, install engine, adapters, hook, and CLI wiring."""

from __future__ import annotations

import json
import os
import re
import tomllib
from collections.abc import Callable
from importlib import resources
from pathlib import Path
from typing import Any

import pytest
from typer.testing import CliRunner

from ai_optimizer import cli, services
from ai_optimizer.cli import app
from ai_optimizer.core.errors import ConfigError
from ai_optimizer.core.project import Project
from ai_optimizer.integrations import adapters
from ai_optimizer.integrations.adapters import InstallOptions
from ai_optimizer.integrations.content import INSTRUCTIONS, SKILL_NAMES, load_skill
from ai_optimizer.integrations.engine import (
    OWNED_MARKER,
    TOML_BLOCK,
    Workspace,
    strip_jsonc,
)
from ai_optimizer.integrations.hooks import session_start_summary
from ai_optimizer.integrations.mcp_server import TOOL_NAMES

Writer = Callable[[Path, str, str], Path]
runner = CliRunner()


def snapshot(root: Path) -> dict[str, str]:
    return {
        p.relative_to(root).as_posix(): p.read_text()
        for p in sorted(root.rglob("*"))
        if p.is_file()
    }


def statuses(report: Any) -> set[str]:
    return {c.status for c in report.changes}


def changed(report: Any, path: str) -> Any:
    return next(c for c in report.changes if c.path == path)


# --- shared skills ---------------------------------------------------------------------------


def skill_text(name: str) -> str:
    return (resources.files("ai_optimizer.integrations") / "skills" / name / "SKILL.md").read_text()


def test_skill_directories_match_registered_names() -> None:
    root = resources.files("ai_optimizer.integrations") / "skills"
    assert sorted(p.name for p in root.iterdir() if p.is_dir()) == sorted(SKILL_NAMES)


@pytest.mark.parametrize("name", SKILL_NAMES)
def test_skill_structure(name: str) -> None:
    text = skill_text(name)
    match = re.match(r"---\nname: (.+)\ndescription: (.+)\n---\n", text)
    assert match, "frontmatter must be name then description"
    assert match.group(1) == name
    assert 40 < len(match.group(2)) <= 1024
    for section in ("# Purpose", "# Workflow", "# Failure handling", "# Output"):
        assert section in text, f"{name} lacks {section}"


@pytest.mark.parametrize("name", SKILL_NAMES)
def test_skill_commands_exist_in_the_cli(name: str) -> None:
    commands = re.findall(r"`(aiopt [^`]+)`", skill_text(name))
    assert commands
    for command in commands:
        words = []
        for token in command.split()[1:]:
            if not re.fullmatch(r"[a-z][a-z-]*", token):
                break
            words.append(token)
        result = runner.invoke(app, [*words, "--help"])
        assert result.exit_code == 0, f"`{command}` is not a valid command"


@pytest.mark.parametrize("name", SKILL_NAMES)
def test_skill_mcp_tools_exist(name: str) -> None:
    for token in re.findall(r"`([a-z]+_[a-z_]+)`", skill_text(name)):
        assert token in TOOL_NAMES, f"{name} references unknown MCP tool {token}"


@pytest.mark.parametrize("name", SKILL_NAMES)
def test_skills_never_tell_agents_to_trust_or_bypass(name: str) -> None:
    text = skill_text(name)
    for line in text.splitlines():
        if "aiopt trust" in line or "--allow-destructive" in line:
            assert re.search(r"never|ask the user|do not|unless the user|suggest", line, re.I), line


def test_instructions_forbid_self_trust() -> None:
    assert "Never run `aiopt trust`" in INSTRUCTIONS


def test_loaded_skill_carries_marker_after_frontmatter() -> None:
    loaded = load_skill("aiopt-context")
    assert loaded.startswith("---\nname: aiopt-context\n")
    assert OWNED_MARKER in loaded
    assert loaded.index(OWNED_MARKER) > loaded.index("\n---\n")
    with pytest.raises(ValueError):
        load_skill("../etc/passwd")


# --- engine ----------------------------------------------------------------------------------


def test_strip_jsonc_respects_strings() -> None:
    text = '{"url": "http://x//y", /* c */ "a": [1, 2,], // tail\n}'
    assert json.loads(strip_jsonc(text)) == {"url": "http://x//y", "a": [1, 2]}


def test_block_lifecycle_preserves_surroundings(tmp_path: Path, write: Writer) -> None:
    write(tmp_path, "doc.md", "# Title\n\nUser text.\n")
    ws = Workspace(tmp_path)
    assert ws.set_block("doc.md", "first").status == "updated"
    assert ws.set_block("doc.md", "first").status == "unchanged"
    assert ws.set_block("doc.md", "second").status == "updated"
    text = (tmp_path / "doc.md").read_text()
    assert text.startswith("# Title\n\nUser text.\n") and text.count("aiopt:begin") == 1
    assert "second" in text and "first" not in text
    assert ws.remove_block("doc.md").status == "updated"
    assert (tmp_path / "doc.md").read_text() == "# Title\n\nUser text.\n"


def test_block_in_new_file_is_removed_with_the_file(tmp_path: Path) -> None:
    ws = Workspace(tmp_path)
    assert ws.set_block("new/doc.md", "body").status == "created"
    assert ws.remove_block("new/doc.md").status == "removed"
    assert not (tmp_path / "new").exists()


def test_malformed_markers_are_not_touched(tmp_path: Path, write: Writer) -> None:
    write(tmp_path, "doc.md", "<!-- aiopt:begin -->\nno end marker\n")
    before = (tmp_path / "doc.md").read_text()
    assert Workspace(tmp_path).set_block("doc.md", "x").status == "skipped"
    assert (tmp_path / "doc.md").read_text() == before


def test_dry_run_never_writes(tmp_path: Path, write: Writer) -> None:
    write(tmp_path, "keep.md", "keep\n")
    ws = Workspace(tmp_path, dry_run=True)
    ws.set_block("keep.md", "x")
    ws.set_block("new.md", "x")
    ws.write_owned("skills/a/SKILL.md", f"{OWNED_MARKER}\n")
    ws.edit_json("c.json", lambda d: d.update(a=1) or True)
    assert snapshot(tmp_path) == {"keep.md": "keep\n"}
    assert {c.status for c in ws.changes} == {"updated", "created"}


def test_owned_files_are_protected(tmp_path: Path, write: Writer) -> None:
    write(tmp_path, "s/SKILL.md", "my own skill\n")
    ws = Workspace(tmp_path)
    content = f"x\n{OWNED_MARKER}\n"
    skipped = ws.write_owned("s/SKILL.md", content)
    assert skipped.status == "skipped" and (tmp_path / "s/SKILL.md").read_text() == "my own skill\n"
    assert ws.write_owned("s/SKILL.md", content, force=True).status == "updated"
    assert ws.write_owned("s/SKILL.md", content).status == "unchanged"
    assert ws.remove_owned("s/SKILL.md").status == "removed"
    write(tmp_path, "other/SKILL.md", "not ours\n")
    assert ws.remove_owned("other/SKILL.md").status == "skipped"
    assert (tmp_path / "other/SKILL.md").exists()


def test_json_with_comments_is_never_rewritten(tmp_path: Path, write: Writer) -> None:
    original = '{\n  // my note\n  "servers": {}\n}\n'
    write(tmp_path, "c.json", original)
    change = Workspace(tmp_path).edit_json("c.json", lambda d: d.update(a=1) or True)
    assert change.status == "skipped" and "comments" in change.detail
    assert (tmp_path / "c.json").read_text() == original


@pytest.mark.parametrize("content", ["{broken", "[1, 2]"])
def test_invalid_or_non_object_json_is_skipped(tmp_path: Path, write: Writer, content: str) -> None:
    write(tmp_path, "c.json", content)
    change = Workspace(tmp_path).edit_json("c.json", lambda d: d.update(a=1) or True)
    assert change.status == "skipped" and (tmp_path / "c.json").read_text() == content


def test_paths_cannot_escape_the_project(tmp_path: Path) -> None:
    outside = tmp_path / "outside"
    outside.mkdir()
    root = tmp_path / "root"
    root.mkdir()
    os.symlink(outside, root / ".claude")
    os.symlink(outside / "target.md", root / "link.md")
    ws = Workspace(root)
    with pytest.raises(ValueError, match="outside the project"):
        ws.write_owned(".claude/skills/x/SKILL.md", OWNED_MARKER)
    with pytest.raises(ValueError, match="outside the project"):
        ws.set_block("link.md", "x")
    with pytest.raises(ValueError):
        ws.set_block("../escape.md", "x")
    assert not list(outside.iterdir())


# --- Claude Code adapter ---------------------------------------------------------------------


def claude(root: Path, **overrides: Any) -> Any:
    return adapters.install(root, "claude", InstallOptions(**overrides))


def test_claude_install_creates_expected_files(project_dir: Path) -> None:
    report = claude(project_dir)
    assert statuses(report) == {"created"}
    for name in SKILL_NAMES:
        text = (project_dir / f".claude/skills/{name}/SKILL.md").read_text()
        assert OWNED_MARKER in text and text.startswith(f"---\nname: {name}\n")
    assert "## AI Optimizer" in (project_dir / "CLAUDE.md").read_text()
    settings = json.loads((project_dir / ".claude/settings.json").read_text())
    assert "Bash(aiopt context build *)" in settings["permissions"]["allow"]
    assert "Bash(aiopt trust *)" in settings["permissions"]["deny"]
    assert "hooks" not in settings
    mcp = json.loads((project_dir / ".mcp.json").read_text())["mcpServers"]["aiopt"]
    assert mcp == {"type": "stdio", "command": "aiopt", "args": ["mcp", "serve"]}


def test_claude_permissions_do_not_auto_approve_risky_commands(project_dir: Path) -> None:
    claude(project_dir)
    allow = json.loads((project_dir / ".claude/settings.json").read_text())["permissions"]["allow"]
    joined = " ".join(allow)
    assert "trust" not in joined and "cache clear" not in joined and "init" not in joined
    assert "Bash(aiopt task run validate)" in allow  # exact match: extra flags still prompt
    assert not any(r.startswith("Bash(aiopt task run *") for r in allow)
    assert "mcp__aiopt__run_task" not in allow


def test_claude_install_is_idempotent(project_dir: Path) -> None:
    claude(project_dir, hooks=True)
    first = snapshot(project_dir)
    second = claude(project_dir, hooks=True)
    assert statuses(second) == {"unchanged"}
    assert snapshot(project_dir) == first
    settings = json.loads((project_dir / ".claude/settings.json").read_text())
    assert len(settings["hooks"]["SessionStart"]) == 1


def test_claude_hook_is_opt_in_and_quotes_the_command(project_dir: Path) -> None:
    claude(project_dir, command="/opt/my tools/aiopt", hooks=True)
    settings = json.loads((project_dir / ".claude/settings.json").read_text())
    hook = settings["hooks"]["SessionStart"][0]["hooks"][0]
    assert (
        hook["type"] == "command" and hook["command"] == "'/opt/my tools/aiopt' hook session-start"
    )
    mcp = json.loads((project_dir / ".mcp.json").read_text())["mcpServers"]["aiopt"]
    assert mcp["command"] == "/opt/my tools/aiopt"


def test_claude_options_can_disable_parts(project_dir: Path) -> None:
    report = claude(project_dir, mcp=False, permissions=False)
    paths = {c.path for c in report.changes}
    assert ".mcp.json" not in paths and ".claude/settings.json" not in paths
    assert not (project_dir / ".mcp.json").exists()
    settings_only_hooks = claude(project_dir, mcp=False, permissions=False, hooks=True)
    settings = json.loads((project_dir / ".claude/settings.json").read_text())
    assert "permissions" not in settings and "hooks" in settings
    assert changed(settings_only_hooks, ".claude/settings.json").status == "created"


def test_claude_preserves_existing_configuration_and_round_trips(
    project_dir: Path, write: Writer
) -> None:
    write(
        project_dir,
        ".claude/settings.json",
        json.dumps({"model": "x", "permissions": {"allow": ["Bash(git status)"]}}, indent=2) + "\n",
    )
    write(
        project_dir,
        ".mcp.json",
        json.dumps({"mcpServers": {"other": {"command": "x"}}}, indent=2) + "\n",
    )
    write(project_dir, "CLAUDE.md", "# My rules\n\nBe nice.\n")
    before = snapshot(project_dir)
    claude(project_dir, hooks=True)
    settings = json.loads((project_dir / ".claude/settings.json").read_text())
    assert settings["model"] == "x"
    assert settings["permissions"]["allow"][0] == "Bash(git status)"
    assert "other" in json.loads((project_dir / ".mcp.json").read_text())["mcpServers"]
    assert (project_dir / "CLAUDE.md").read_text().startswith("# My rules\n\nBe nice.\n")
    adapters.uninstall(project_dir, "claude")
    after = snapshot(project_dir)
    assert {k: json.loads(v) for k, v in after.items() if k.endswith(".json")} == {
        k: json.loads(v) for k, v in before.items() if k.endswith(".json")
    }
    assert after == before


def test_claude_uninstall_on_fresh_project_leaves_no_trace(project_dir: Path) -> None:
    before = snapshot(project_dir)
    claude(project_dir, hooks=True)
    assert snapshot(project_dir) != before
    adapters.uninstall(project_dir, "claude")
    assert snapshot(project_dir) == before
    assert not (project_dir / ".claude").exists()


def test_claude_uses_agents_md_when_claude_md_imports_it(project_dir: Path, write: Writer) -> None:
    write(project_dir, "CLAUDE.md", "@AGENTS.md\n")
    claude(project_dir)
    assert (project_dir / "CLAUDE.md").read_text() == "@AGENTS.md\n"
    agents = (project_dir / "AGENTS.md").read_text()
    assert agents.startswith("Use type hints.") and "## AI Optimizer" in agents


def test_claude_does_not_overwrite_a_users_skill_or_server(
    project_dir: Path, write: Writer
) -> None:
    write(project_dir, ".claude/skills/aiopt-context/SKILL.md", "my custom skill\n")
    write(
        project_dir,
        ".mcp.json",
        json.dumps({"mcpServers": {"aiopt": {"command": "custom", "args": ["x"]}}}),
    )
    report = claude(project_dir)
    assert changed(report, ".claude/skills/aiopt-context/SKILL.md").status == "skipped"
    assert changed(report, ".mcp.json").status == "skipped"
    assert (
        project_dir / ".claude/skills/aiopt-context/SKILL.md"
    ).read_text() == "my custom skill\n"
    forced = claude(project_dir, force=True)
    assert changed(forced, ".mcp.json").status == "updated"
    assert OWNED_MARKER in (project_dir / ".claude/skills/aiopt-context/SKILL.md").read_text()


def test_claude_leaves_malformed_settings_alone(project_dir: Path, write: Writer) -> None:
    write(project_dir, ".claude/settings.json", '{"permissions": {"allow": "oops"}}')
    report = claude(project_dir)
    assert changed(report, ".claude/settings.json").status == "skipped"
    assert (
        project_dir / ".claude/settings.json"
    ).read_text() == '{"permissions": {"allow": "oops"}}'


# --- Copilot (VS Code) adapter ---------------------------------------------------------------


def copilot(root: Path, **overrides: Any) -> Any:
    return adapters.install(root, "copilot", InstallOptions(**overrides))


def test_copilot_install_and_round_trip(project_dir: Path) -> None:
    before = snapshot(project_dir)
    copilot(project_dir)
    assert "## AI Optimizer" in (project_dir / ".github/copilot-instructions.md").read_text()
    for name in SKILL_NAMES:
        assert OWNED_MARKER in (project_dir / f".github/skills/{name}/SKILL.md").read_text()
    server = json.loads((project_dir / ".vscode/mcp.json").read_text())["servers"]["aiopt"]
    assert server == {
        "type": "stdio",
        "command": "aiopt",
        "args": ["--project-dir", "${workspaceFolder}", "mcp", "serve"],
    }
    assert statuses(copilot(project_dir)) == {"unchanged"}
    adapters.uninstall(project_dir, "copilot")
    assert snapshot(project_dir) == before


def test_copilot_reuses_claude_skills_instead_of_duplicating(project_dir: Path) -> None:
    claude(project_dir)
    report = copilot(project_dir)
    assert not (project_dir / ".github/skills").exists()
    assert any("already reads" in n for n in report.notes)


def test_claude_warns_when_copilot_skills_would_duplicate(project_dir: Path) -> None:
    copilot(project_dir)
    report = claude(project_dir)
    assert any("twice" in n for n in report.notes)


def test_copilot_never_rewrites_mcp_json_with_comments(project_dir: Path, write: Writer) -> None:
    original = '{\n  // team servers\n  "servers": {"x": {"command": "y"}}\n}\n'
    write(project_dir, ".vscode/mcp.json", original)
    report = copilot(project_dir)
    assert changed(report, ".vscode/mcp.json").status == "skipped"
    assert (project_dir / ".vscode/mcp.json").read_text() == original
    assert any("skipped" in n for n in report.notes)


def test_copilot_keeps_other_servers(project_dir: Path, write: Writer) -> None:
    write(project_dir, ".vscode/mcp.json", json.dumps({"servers": {"db": {"command": "db"}}}))
    copilot(project_dir)
    adapters.uninstall(project_dir, "copilot")
    assert json.loads((project_dir / ".vscode/mcp.json").read_text()) == {
        "servers": {"db": {"command": "db"}}
    }


# --- Codex adapter ---------------------------------------------------------------------------


def codex(root: Path, **overrides: Any) -> Any:
    return adapters.install(root, "codex", InstallOptions(**overrides))


def test_codex_install_and_round_trip(project_dir: Path) -> None:
    before = snapshot(project_dir)
    codex(project_dir)
    assert (project_dir / "AGENTS.md").read_text().startswith("Use type hints.")
    config = tomllib.loads((project_dir / ".codex/config.toml").read_text())
    assert config["mcp_servers"]["aiopt"] == {"command": "aiopt", "args": ["mcp", "serve"]}
    assert statuses(codex(project_dir)) == {"unchanged"}
    adapters.uninstall(project_dir, "codex")
    assert snapshot(project_dir) == before


def test_codex_preserves_existing_toml(project_dir: Path, write: Writer) -> None:
    write(project_dir, ".codex/config.toml", 'model = "x"\n\n[mcp_servers.other]\ncommand = "o"\n')
    codex(project_dir)
    config = tomllib.loads((project_dir / ".codex/config.toml").read_text())
    assert config["model"] == "x" and set(config["mcp_servers"]) == {"other", "aiopt"}
    adapters.uninstall(project_dir, "codex")
    assert (project_dir / ".codex/config.toml").read_text() == (
        'model = "x"\n\n[mcp_servers.other]\ncommand = "o"\n'
    )


def test_codex_refuses_to_create_invalid_toml(project_dir: Path, write: Writer) -> None:
    original = '[mcp_servers.aiopt]\ncommand = "mine"\n'
    write(project_dir, ".codex/config.toml", original)
    report = codex(project_dir)
    assert changed(report, ".codex/config.toml").status == "skipped"
    assert (project_dir / ".codex/config.toml").read_text() == original


def test_codex_quotes_unusual_commands(project_dir: Path) -> None:
    codex(project_dir, command='C:\\tools\\"aiopt"')
    config = tomllib.loads((project_dir / ".codex/config.toml").read_text())
    assert config["mcp_servers"]["aiopt"]["command"] == 'C:\\tools\\"aiopt"'


def test_shared_agents_block_survives_until_last_user_leaves(
    project_dir: Path, write: Writer
) -> None:
    write(project_dir, "CLAUDE.md", "@AGENTS.md\n")
    claude(project_dir)
    codex(project_dir)
    assert (project_dir / "AGENTS.md").read_text().count("aiopt:begin") == 1
    report = adapters.uninstall(project_dir, "claude")
    assert "aiopt:begin" in (project_dir / "AGENTS.md").read_text()
    assert any("Codex still uses" in n for n in report.notes)
    adapters.uninstall(project_dir, "codex")
    assert (project_dir / "AGENTS.md").read_text() == "Use type hints.\n"


def test_status_reports_installed_providers(project_dir: Path) -> None:
    assert not any(r["installed"] for r in adapters.status(project_dir))
    claude(project_dir)
    codex(project_dir)
    installed = {r["provider"] for r in adapters.status(project_dir) if r["installed"]}
    assert installed == {"claude", "codex"}


def test_unknown_provider(project_dir: Path) -> None:
    with pytest.raises(ConfigError, match="claude"):
        adapters.install(project_dir, "emacs", InstallOptions())


# --- CLI -------------------------------------------------------------------------------------


def aiopt(root: Path, *args: str) -> Any:
    return runner.invoke(app, ["--project-dir", str(root), *args])


def test_cli_install_list_uninstall(project_dir: Path) -> None:
    listing = aiopt(project_dir, "integrations", "list")
    assert listing.exit_code == 0 and "not installed" in listing.stdout
    result = aiopt(project_dir, "--json", "integrations", "install", "claude", "--with-hooks")
    assert result.exit_code == 0
    payload = json.loads(result.stdout)
    assert payload["provider"] == "claude" and payload["dry_run"] is False
    assert {c["status"] for c in payload["changes"]} == {"created"}
    assert "installed" in aiopt(project_dir, "integrations", "list").stdout
    out = aiopt(project_dir, "integrations", "uninstall", "claude")
    assert out.exit_code == 0 and "removed" in out.stdout
    assert not (project_dir / ".claude").exists()


def test_cli_dry_run_changes_nothing(project_dir: Path) -> None:
    before = snapshot(project_dir)
    result = aiopt(project_dir, "integrations", "install", "codex", "--dry-run")
    assert result.exit_code == 0 and "[dry run]" in result.stdout
    assert snapshot(project_dir) == before


def test_cli_unknown_provider_is_a_usage_error(project_dir: Path) -> None:
    result = aiopt(project_dir, "integrations", "install", "vim")
    assert result.exit_code == 2 and "Available" in result.stderr


def test_cli_warns_when_the_command_is_not_on_path(project_dir: Path) -> None:
    result = aiopt(project_dir, "integrations", "install", "claude", "--command", "no-such-aiopt")
    assert "not found on PATH" in result.stdout
    ok = aiopt(project_dir, "integrations", "install", "codex")
    assert "not found on PATH" not in ok.stdout


def test_cli_no_mcp_and_no_permissions_flags(project_dir: Path) -> None:
    result = aiopt(project_dir, "integrations", "install", "claude", "--no-mcp", "--no-permissions")
    assert result.exit_code == 0
    assert not (project_dir / ".mcp.json").exists()
    assert not (project_dir / ".claude/settings.json").exists()


# --- session hook ----------------------------------------------------------------------------


def test_hook_is_silent_when_not_set_up(project_dir: Path) -> None:
    assert session_start_summary(project_dir) == ""
    result = aiopt(project_dir, "hook", "session-start")
    assert result.exit_code == 0 and result.stdout == ""


def test_hook_reports_status_briefly(project: Project, monkeypatch: pytest.MonkeyPatch) -> None:
    services.init_project(project.root)
    summary = session_start_summary(project.root)
    assert "cache not built" in summary and "NOT trusted" in summary
    services.cache_update(project)
    summary = session_start_summary(project.root)
    assert "cache current" in summary and len(summary.splitlines()) == 2
    services.trust_project(project)
    assert "tasks trusted" in session_start_summary(project.root)
    (project.root / "src/shop/new.py").write_text("x = 1\n")
    assert "stale" in session_start_summary(project.root)
    assert project.paths.db_file.stat().st_size > 0


def test_hook_never_raises(project: Project, monkeypatch: pytest.MonkeyPatch) -> None:
    services.init_project(project.root)

    def boom(*_: object, **__: object) -> None:
        raise RuntimeError("x")

    monkeypatch.setattr(services, "cache_status", boom)
    assert session_start_summary(project.root) == ""
    assert cli.app is app


def test_hook_does_not_refresh_the_cache(project: Project) -> None:
    services.init_project(project.root)
    session_start_summary(project.root)
    assert not project.paths.db_file.exists()


def test_hook_command_is_hidden_from_help() -> None:
    assert "hook" not in runner.invoke(app, ["--help"]).stdout.replace("--help", "")


# --- service contract ------------------------------------------------------------------------


def test_service_results_are_json_serializable(project: Project, write: Writer) -> None:
    """Integrations rely on every service returning plain JSON data."""
    write(
        project.root,
        ".ai-optimizer/tasks/noop.yaml",
        "steps:\n  - {name: s, command: [pytest, --version]}\n",
    )
    services.trust_project(project)
    results = [
        services.project_info(project),
        services.analyze_project(project),
        services.cache_update(project),
        services.cache_status(project),
        services.search_context(project, "password"),
        services.build_context(project, task="password reset")["package"],
        services.list_profiles(project),
        services.show_profile(project, "backend"),
        services.list_tasks(project),
        services.show_task(project, "noop"),
        services.validate_config(project),
        services.metrics_report(project),
        services.run_task(project, "noop"),
    ]
    for result in results:
        json.dumps(result)


def test_toml_block_marker_is_stable() -> None:
    assert TOML_BLOCK == ("# aiopt:begin", "# aiopt:end")


def test_uninstall_leaves_a_users_own_server_entry(project_dir: Path, write: Writer) -> None:
    mine = {"mcpServers": {"aiopt": {"command": "custom", "args": ["serve-my-way"]}}}
    write(project_dir, ".mcp.json", json.dumps(mine))
    adapters.uninstall(project_dir, "claude")
    assert json.loads((project_dir / ".mcp.json").read_text()) == mine


# --- architecture boundaries -----------------------------------------------------------------


def test_core_modules_never_import_integrations_or_provider_sdks() -> None:
    import ast

    import ai_optimizer

    src = Path(ai_optimizer.__file__).parent
    forbidden_sdks = {"mcp", "anthropic", "openai", "github", "copilot"}
    offenders: list[str] = []
    for py in sorted(src.rglob("*.py")):
        relative = py.relative_to(src)
        if relative.parts[0] == "integrations" or relative.name in {"cli.py", "__main__.py"}:
            continue
        for node in ast.walk(ast.parse(py.read_text())):
            modules = []
            if isinstance(node, ast.Import):
                modules = [alias.name for alias in node.names]
            elif isinstance(node, ast.ImportFrom) and node.module:
                modules = [node.module]
            for module in modules:
                if module.startswith("ai_optimizer.integrations") or (
                    module.split(".")[0] in forbidden_sdks
                ):
                    offenders.append(f"{relative}: {module}")
    assert offenders == []


def test_everything_except_mcp_serve_works_without_the_mcp_package(project_dir: Path) -> None:
    import subprocess
    import sys

    code = (
        "import sys\n"
        "sys.modules['mcp'] = None\n"
        "from typer.testing import CliRunner\n"
        "from ai_optimizer.cli import app\n"
        "r = CliRunner()\n"
        "base = ['--project-dir', sys.argv[1]]\n"
        "a = r.invoke(app, [*base, 'analyze'])\n"
        "b = r.invoke(app, [*base, 'context', 'search', 'password'])\n"
        "c = r.invoke(app, [*base, 'mcp', 'serve'])\n"
        "print(a.exit_code, b.exit_code, c.exit_code, 'ai-optimizer[mcp]' in c.stderr)\n"
    )
    done = subprocess.run(
        [sys.executable, "-c", code, str(project_dir)], capture_output=True, text=True, timeout=120
    )
    assert done.stdout.strip() == "0 0 2 True", done.stderr


def test_installed_assistant_files_are_not_indexed(project_dir: Path) -> None:
    from ai_optimizer.cache.indexer import update_index
    from ai_optimizer.core.config import ProjectConfig, ProjectPaths

    claude(project_dir, hooks=True)
    codex(project_dir)
    result = update_index(ProjectPaths(project_dir), ProjectConfig())
    assert not [p for p in result.added if p.startswith((".claude/", ".codex/"))]
