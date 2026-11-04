"""Import provider-reported usage from Claude Code session transcripts.

Reads only counts and tool names. Message text, tool inputs and results are never stored.
The transcript format is Claude Code's internal log, so parsing is defensive: unknown line
types are ignored and missing fields count as zero.
"""

from __future__ import annotations

import json
import os
import re
from pathlib import Path
from typing import Any

from ai_optimizer.core.config import ProjectPaths
from ai_optimizer.core.errors import ConfigError
from ai_optimizer.metrics.usage import SessionUsage, upsert_sessions

SOURCE = "claude-code"
_SEGMENT_SPLIT = re.compile(r"&&|\|\||[;|\n]")
_ENV_ASSIGNMENT = re.compile(r"^[A-Za-z_][A-Za-z0-9_]*=\S*$")


def claude_projects_dir() -> Path:
    """Where Claude Code keeps per-project transcripts."""
    override = os.environ.get("CLAUDE_CONFIG_DIR")
    return (Path(override) if override else Path.home() / ".claude") / "projects"


def find_transcripts(root: Path, base: Path | None = None) -> list[Path]:
    """Locate transcript files for the project at ``root`` (top-level ``*.jsonl`` only)."""
    base = base or claude_projects_dir()
    names = {str(root).replace("/", "-"), re.sub(r"[^A-Za-z0-9]", "-", str(root))}
    found: list[Path] = []
    for name in sorted(names):
        directory = base / name
        if directory.is_dir():
            found += sorted(directory.glob("*.jsonl"))
    return found


def invokes_aiopt(command: str) -> bool:
    """True if a shell command line runs the ``aiopt`` executable (not merely mentions it)."""
    for segment in _SEGMENT_SPLIT.split(command):
        tokens = segment.split()
        while tokens and _ENV_ASSIGNMENT.match(tokens[0]):
            tokens.pop(0)
        if not tokens:
            continue
        if tokens[0].rsplit("/", 1)[-1] == "aiopt":
            return True
        if tokens[0].rsplit("/", 1)[-1].startswith("python") and tokens[1:3] == [
            "-m",
            "ai_optimizer",
        ]:
            return True
    return False


def _int(value: Any) -> int:
    return value if isinstance(value, int) and not isinstance(value, bool) else 0


def _read_lines(file: Path) -> list[str]:
    try:
        return file.read_text(encoding="utf-8", errors="replace").splitlines()
    except OSError as exc:
        raise ConfigError(f"Cannot read {file}: {exc}") from exc


def parse_transcript(file: Path) -> SessionUsage | None:
    """Summarize one session: its transcript plus any subagent transcripts beside it.

    Returns None if the session holds no assistant messages.
    """
    subagent_files = sorted((file.with_suffix("") / "subagents").glob("*.jsonl"))
    session = SessionUsage(session_id=file.stem, source=SOURCE)
    seen_messages: set[str] = set()
    seen_tools: set[str] = set()
    models: set[str] = set()
    for source_file in [file, *subagent_files]:
        is_subagent = source_file is not file
        for line in _read_lines(source_file):
            try:
                entry = json.loads(line)
            except json.JSONDecodeError:
                continue
            if not isinstance(entry, dict) or entry.get("type") != "assistant":
                continue
            message = entry.get("message")
            if not isinstance(message, dict):
                continue
            if not is_subagent:
                session.session_id = str(entry.get("sessionId") or session.session_id)
            timestamp = entry.get("timestamp")
            if isinstance(timestamp, str):
                session.started_at = min(filter(None, [session.started_at, timestamp]))
                session.ended_at = max(filter(None, [session.ended_at, timestamp]))
            # One API response is split across several lines that repeat the same usage.
            message_id = str(message.get("id") or entry.get("uuid") or "")
            if message_id not in seen_messages:
                seen_messages.add(message_id)
                session.assistant_messages += 1
                if is_subagent or entry.get("isSidechain"):
                    session.sidechain_messages += 1
                raw_usage = message.get("usage")
                usage: dict[str, Any] = raw_usage if isinstance(raw_usage, dict) else {}
                session.input_tokens += _int(usage.get("input_tokens"))
                session.output_tokens += _int(usage.get("output_tokens"))
                session.cache_read_tokens += _int(usage.get("cache_read_input_tokens"))
                session.cache_write_tokens += _int(usage.get("cache_creation_input_tokens"))
                if isinstance(message.get("model"), str):
                    models.add(message["model"])
            for part in message.get("content") or []:
                if not isinstance(part, dict) or part.get("type") != "tool_use":
                    continue
                tool_id = str(part.get("id") or "")
                if tool_id in seen_tools:
                    continue
                seen_tools.add(tool_id)
                name = str(part.get("name") or "unknown")
                session.tool_calls[name] = session.tool_calls.get(name, 0) + 1
                tool_input = part.get("input")
                command = tool_input.get("command") if isinstance(tool_input, dict) else None
                if name.startswith("mcp__aiopt__") or (
                    name == "Bash" and isinstance(command, str) and invokes_aiopt(command)
                ):
                    session.aiopt_calls += 1
    if not session.assistant_messages:
        return None
    session.models = sorted(models)
    return session


def import_claude_sessions(
    paths: ProjectPaths,
    *,
    path: Path | None = None,
    label: str | None = None,
    since: str | None = None,
) -> dict[str, Any]:
    """Parse transcripts (a file, a directory, or the auto-detected ones) and store them."""
    if path is None:
        files = find_transcripts(paths.root)
    elif path.is_file():
        files = [path]
    elif path.is_dir():
        files = sorted(path.glob("*.jsonl"))
    else:
        raise ConfigError(f"Transcript path does not exist: {path}")
    if not files:
        raise ConfigError(
            "No Claude Code transcripts found for this project. Use --path to point at a "
            "transcript file or directory (normally under ~/.claude/projects/)."
        )
    sessions = [s for f in files if (s := parse_transcript(f)) is not None]
    if since:
        sessions = [s for s in sessions if s.started_at and s.started_at >= since]
    added, updated = upsert_sessions(paths, sessions, label)
    return {
        "files_found": len(files),
        "sessions_imported": len(sessions),
        "added": added,
        "updated": updated,
        "label": label,
        "session_ids": [s.session_id for s in sessions],
        "privacy": "Only counts and tool names were stored; no message content.",
    }
