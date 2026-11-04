"""Thin provider adapters: choose shared content, then let the engine write it safely.

Adapters contain no workflow logic. Skills, instructions and the MCP server are defined
once (``content.py``, ``skills/``, ``mcp_server.py``) and merely placed where each tool looks.
"""

from __future__ import annotations

import json
import re
import shlex
import shutil
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Protocol

from ai_optimizer.core.errors import ConfigError
from ai_optimizer.integrations.content import INSTRUCTIONS, SERVER_NAME, SKILL_NAMES, load_skill
from ai_optimizer.integrations.engine import (
    MARKDOWN_BLOCK,
    OWNED_MARKER,
    TOML_BLOCK,
    InstallReport,
    Workspace,
    validate_toml,
)

MCP_ARGS = ["mcp", "serve"]
CLAUDE_SKILLS_DIR = ".claude/skills"
GITHUB_SKILLS_DIR = ".github/skills"

CLAUDE_ALLOW = [
    "Bash(aiopt context search *)",
    "Bash(aiopt context build *)",
    "Bash(aiopt cache status *)",
    "Bash(aiopt cache update *)",
    "Bash(aiopt analyze *)",
    "Bash(aiopt project info *)",
    "Bash(aiopt profile list *)",
    "Bash(aiopt profile show *)",
    "Bash(aiopt task list *)",
    "Bash(aiopt task show *)",
    "Bash(aiopt task run validate)",
    "Bash(aiopt metrics *)",
]
CLAUDE_MCP_ALLOW = [
    f"mcp__{SERVER_NAME}__{tool}"
    for tool in (
        "get_project_info",
        "analyze_project",
        "search_context",
        "get_context_profile",
        "build_context",
        "get_cache_status",
        "refresh_cache",
        "list_tasks",
    )
]
# Deny rules match any sub-command of a compound command. `aiopt trust` additionally
# requires a real terminal, which covers forms a text rule cannot (e.g. `aiopt --json trust`).
CLAUDE_DENY = ["Bash(aiopt trust *)"]


@dataclass(frozen=True, slots=True)
class InstallOptions:
    """User-selectable installation behavior."""

    command: str = "aiopt"
    mcp: bool = True
    hooks: bool = False
    permissions: bool = True
    force: bool = False
    dry_run: bool = False


class Adapter(Protocol):
    name: str
    description: str

    def install(self, ws: Workspace, opts: InstallOptions) -> list[str]: ...
    def uninstall(self, ws: Workspace) -> list[str]: ...
    def is_installed(self, ws: Workspace) -> bool: ...


# --- shared helpers ------------------------------------------------------------------------


def _install_skills(ws: Workspace, directory: str, force: bool) -> None:
    for name in SKILL_NAMES:
        ws.write_owned(f"{directory}/{name}/SKILL.md", load_skill(name), force=force)


def _remove_skills(ws: Workspace, directory: str) -> None:
    for name in SKILL_NAMES:
        ws.remove_owned(f"{directory}/{name}/SKILL.md")


def _has_marker(ws: Workspace, relative: str, marker: str = OWNED_MARKER) -> bool:
    text = ws.read(relative)
    return text is not None and marker in text


def _mcp_entry(opts: InstallOptions, *, args_prefix: list[str] | None = None) -> dict[str, Any]:
    return {
        "type": "stdio",
        "command": opts.command,
        "args": [*(args_prefix or []), *MCP_ARGS],
    }


def _is_ours(entry: object) -> bool:
    return isinstance(entry, dict) and entry.get("args", [])[-2:] == MCP_ARGS


def _set_server(data: dict[str, Any], key: str, entry: dict[str, Any], force: bool) -> bool:
    servers = data.setdefault(key, {})
    if not isinstance(servers, dict):
        raise ValueError(f"'{key}' is not an object; edit manually")
    current = servers.get(SERVER_NAME)
    if current == entry:
        return False
    if current is not None and not force and not _is_ours(current):
        raise ValueError(f"a server named '{SERVER_NAME}' already exists; use --force to replace")
    servers[SERVER_NAME] = entry
    return True


def _drop_server(data: dict[str, Any], key: str) -> bool:
    servers = data.get(key)
    if not isinstance(servers, dict) or not _is_ours(servers.get(SERVER_NAME)):
        return False
    del servers[SERVER_NAME]
    if not servers:
        del data[key]
    return True


def _agents_md_imported_by_claude(ws: Workspace) -> bool:
    text = ws.read("CLAUDE.md")
    return text is not None and re.search(r"^@AGENTS\.md\s*$", text, re.MULTILINE) is not None


def _shared_agents_block_in_use(ws: Workspace, leaving: str) -> bool:
    """True if a provider other than ``leaving`` still relies on the AGENTS.md block."""
    if leaving != "codex" and _has_marker(ws, ".codex/config.toml", TOML_BLOCK[0]):
        return True
    return (
        leaving != "claude"
        and _agents_md_imported_by_claude(ws)
        and _has_marker(ws, ".claude/skills/aiopt-context/SKILL.md")
    )


# --- Claude Code (CLI and VS Code extension) ----------------------------------------------


class ClaudeAdapter:
    name = "claude"
    description = "Claude Code: skills, instructions, permissions, MCP server, optional hook"

    def _instruction_file(self, ws: Workspace) -> str:
        return "AGENTS.md" if _agents_md_imported_by_claude(ws) else "CLAUDE.md"

    @staticmethod
    def _hook_command(opts: InstallOptions) -> str:
        return f"{shlex.quote(opts.command)} hook session-start"

    def install(self, ws: Workspace, opts: InstallOptions) -> list[str]:
        notes: list[str] = []
        _install_skills(ws, CLAUDE_SKILLS_DIR, opts.force)
        target = self._instruction_file(ws)
        ws.set_block(target, INSTRUCTIONS)
        if target == "AGENTS.md":
            notes.append("CLAUDE.md imports AGENTS.md, so the instructions were added there.")
        if opts.permissions or opts.hooks:
            ws.edit_json(".claude/settings.json", lambda data: self._edit_settings(data, opts))
            notes.append(
                "Claude Code applies allow rules from .claude/settings.json only after you trust "
                "the workspace."
            )
        if opts.mcp:
            ws.edit_json(
                ".mcp.json",
                lambda data: _set_server(data, "mcpServers", _mcp_entry(opts), opts.force),
            )
            notes.append("Claude Code asks you to approve the project MCP server on first use.")
        if _has_marker(ws, f"{GITHUB_SKILLS_DIR}/aiopt-context/SKILL.md"):
            notes.append(
                "VS Code reads both .claude/skills and .github/skills, so the skills now appear "
                "twice. Run `aiopt integrations uninstall copilot` to remove the duplicates."
            )
        return notes

    def _edit_settings(self, data: dict[str, Any], opts: InstallOptions) -> bool:
        changed = False
        if opts.permissions:
            permissions = data.setdefault("permissions", {})
            if not isinstance(permissions, dict):
                raise ValueError("'permissions' is not an object; edit manually")
            allow = CLAUDE_ALLOW + (CLAUDE_MCP_ALLOW if opts.mcp else [])
            for key, wanted in (("allow", allow), ("deny", CLAUDE_DENY)):
                rules = permissions.setdefault(key, [])
                if not isinstance(rules, list):
                    raise ValueError(f"'permissions.{key}' is not a list; edit manually")
                for rule in wanted:
                    if rule not in rules:
                        rules.append(rule)
                        changed = True
        if opts.hooks:
            hooks = data.setdefault("hooks", {})
            entries = hooks.setdefault("SessionStart", []) if isinstance(hooks, dict) else None
            if not isinstance(entries, list):
                raise ValueError("'hooks' has an unexpected shape; edit manually")
            command = self._hook_command(opts)
            if not any(self._entry_has_command(entry, command) for entry in entries):
                entries.append(
                    {
                        "matcher": "startup",
                        "hooks": [{"type": "command", "command": command, "timeout": 10}],
                    }
                )
                changed = True
        return changed

    @staticmethod
    def _entry_has_command(entry: object, command: str) -> bool:
        return isinstance(entry, dict) and any(
            isinstance(h, dict) and h.get("command") == command for h in entry.get("hooks", [])
        )

    @staticmethod
    def _is_our_hook(command: object) -> bool:
        return isinstance(command, str) and command.endswith(" hook session-start")

    def _unedit_settings(self, data: dict[str, Any]) -> bool:
        changed = False
        permissions = data.get("permissions")
        if isinstance(permissions, dict):
            ours = {"allow": [*CLAUDE_ALLOW, *CLAUDE_MCP_ALLOW], "deny": CLAUDE_DENY}
            for key, rules in ours.items():
                current = permissions.get(key)
                if isinstance(current, list):
                    kept = [r for r in current if r not in rules]
                    if len(kept) != len(current):
                        changed = True
                        permissions[key] = kept
                    if not permissions[key]:
                        del permissions[key]
            if not permissions:
                del data["permissions"]
        hooks = data.get("hooks")
        if isinstance(hooks, dict) and isinstance(hooks.get("SessionStart"), list):
            kept_entries = []
            for entry in hooks["SessionStart"]:
                if isinstance(entry, dict) and isinstance(entry.get("hooks"), list):
                    remaining = [
                        h
                        for h in entry["hooks"]
                        if not (isinstance(h, dict) and self._is_our_hook(h.get("command")))
                    ]
                    if len(remaining) != len(entry["hooks"]):
                        changed = True
                        if not remaining:
                            continue
                        entry = {**entry, "hooks": remaining}
                kept_entries.append(entry)
            hooks["SessionStart"] = kept_entries
            if not kept_entries:
                del hooks["SessionStart"]
            if not hooks:
                del data["hooks"]
        return changed

    def uninstall(self, ws: Workspace) -> list[str]:
        _remove_skills(ws, CLAUDE_SKILLS_DIR)
        notes: list[str] = []
        ws.edit_json(".claude/settings.json", self._unedit_settings, delete_if_empty=True)
        ws.edit_json(".mcp.json", lambda d: _drop_server(d, "mcpServers"), delete_if_empty=True)
        in_agents = _agents_md_imported_by_claude(ws)
        if in_agents and _shared_agents_block_in_use(ws, "claude"):
            notes.append("Left the AGENTS.md block in place because Codex still uses it.")
        elif in_agents:
            ws.remove_block("AGENTS.md")
        ws.remove_block("CLAUDE.md")
        return notes

    def is_installed(self, ws: Workspace) -> bool:
        return _has_marker(ws, f"{CLAUDE_SKILLS_DIR}/aiopt-context/SKILL.md")


# --- GitHub Copilot in VS Code ---------------------------------------------------------------


class CopilotAdapter:
    name = "copilot"
    description = "GitHub Copilot (VS Code): instructions, skills, MCP server"

    def install(self, ws: Workspace, opts: InstallOptions) -> list[str]:
        notes: list[str] = []
        if _has_marker(ws, f"{CLAUDE_SKILLS_DIR}/aiopt-context/SKILL.md"):
            notes.append("Skills not copied: VS Code already reads the ones in .claude/skills.")
        else:
            _install_skills(ws, GITHUB_SKILLS_DIR, opts.force)
        ws.set_block(".github/copilot-instructions.md", INSTRUCTIONS)
        if opts.mcp:
            entry = _mcp_entry(opts, args_prefix=["--project-dir", "${workspaceFolder}"])
            ws.edit_json(
                ".vscode/mcp.json", lambda data: _set_server(data, "servers", entry, opts.force)
            )
            notes.append("VS Code asks you to trust the MCP server the first time it starts.")
        if opts.hooks:
            notes.append("Hooks are only supported for Claude Code; ignored.")
        return notes

    def uninstall(self, ws: Workspace) -> list[str]:
        _remove_skills(ws, GITHUB_SKILLS_DIR)
        ws.remove_block(".github/copilot-instructions.md")
        ws.edit_json(".vscode/mcp.json", lambda d: _drop_server(d, "servers"), delete_if_empty=True)
        return []

    def is_installed(self, ws: Workspace) -> bool:
        return _has_marker(ws, ".github/copilot-instructions.md", MARKDOWN_BLOCK[0])


# --- OpenAI Codex ----------------------------------------------------------------------------


class CodexAdapter:
    name = "codex"
    description = "OpenAI Codex: AGENTS.md instructions and MCP server"

    def install(self, ws: Workspace, opts: InstallOptions) -> list[str]:
        notes: list[str] = []
        ws.set_block("AGENTS.md", INSTRUCTIONS)
        if opts.mcp:
            body = (
                f"[mcp_servers.{SERVER_NAME}]\n"
                f"command = {json.dumps(opts.command)}\n"
                f"args = {json.dumps(MCP_ARGS)}"
            )
            ws.set_block(".codex/config.toml", body, TOML_BLOCK, validate=validate_toml)
            notes.append("Codex reads .codex/config.toml only for projects you have trusted.")
        if opts.hooks:
            notes.append("Hooks are only supported for Claude Code; ignored.")
        return notes

    def uninstall(self, ws: Workspace) -> list[str]:
        ws.remove_block(".codex/config.toml", TOML_BLOCK)
        if _shared_agents_block_in_use(ws, "codex"):
            return ["Left the AGENTS.md block in place because Claude Code still uses it."]
        ws.remove_block("AGENTS.md")
        return []

    def is_installed(self, ws: Workspace) -> bool:
        return _has_marker(ws, ".codex/config.toml", TOML_BLOCK[0]) or (
            _has_marker(ws, "AGENTS.md", MARKDOWN_BLOCK[0])
            and not _agents_md_imported_by_claude(ws)
        )


PROVIDERS: dict[str, Adapter] = {
    adapter.name: adapter for adapter in (ClaudeAdapter(), CopilotAdapter(), CodexAdapter())
}


def _get(provider: str) -> Adapter:
    if provider not in PROVIDERS:
        raise ConfigError(
            f"Unknown provider '{provider}'. Available: {', '.join(sorted(PROVIDERS))}"
        )
    return PROVIDERS[provider]


def install(root: Path, provider: str, opts: InstallOptions) -> InstallReport:
    """Install an integration into the project at ``root``."""
    adapter = _get(provider)
    ws = Workspace(root, dry_run=opts.dry_run)
    try:
        notes = adapter.install(ws, opts)
    except ValueError as exc:
        raise ConfigError(str(exc)) from exc
    if (opts.mcp or opts.hooks) and shutil.which(opts.command) is None:
        notes.append(
            f"'{opts.command}' was not found on PATH. Editors start tools with their own "
            "environment, so use --command with an absolute path (for example "
            '--command "$(which aiopt)").'
        )
    skipped = [c for c in ws.changes if c.status == "skipped"]
    if skipped:
        notes.append(f"{len(skipped)} item(s) were skipped; see details above.")
    return InstallReport(provider, opts.dry_run, ws.changes, notes)


def uninstall(root: Path, provider: str, *, dry_run: bool = False) -> InstallReport:
    """Remove everything an earlier install added; files you edited are left alone."""
    adapter = _get(provider)
    ws = Workspace(root, dry_run=dry_run)
    notes = adapter.uninstall(ws)
    return InstallReport(provider, dry_run, ws.changes, notes)


def status(root: Path) -> list[dict[str, Any]]:
    """Report which integrations appear to be installed in the project."""
    ws = Workspace(root, dry_run=True)
    return [
        {"provider": a.name, "description": a.description, "installed": a.is_installed(ws)}
        for a in PROVIDERS.values()
    ]
