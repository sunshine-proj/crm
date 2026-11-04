"""Provider-reported usage records, kept strictly apart from local estimates.

Records come from an assistant's own session logs (see ``integrations`` importers) and hold
only counts and tool names, never conversation content.
"""

from __future__ import annotations

import json
import os
import statistics
from dataclasses import asdict, dataclass, field
from typing import Any

from ai_optimizer.core.config import ProjectPaths
from ai_optimizer.core.errors import ConfigError

USAGE_FILE = "usage.json"
USAGE_VERSION = 1
UNLABELED = "(unlabeled)"
MIN_SESSIONS_FOR_COMPARISON = 5
SOURCE_NOTE = "provider-reported: imported from the assistant's own session logs"

EXPLORATION_TOOLS = frozenset({"Read", "Grep", "Glob", "LS", "NotebookRead"})
EDIT_TOOLS = frozenset({"Edit", "Write", "MultiEdit", "NotebookEdit"})
METRICS = (
    "input_tokens",
    "output_tokens",
    "cache_read_tokens",
    "cache_write_tokens",
    "tool_calls",
    "exploration_calls",
    "shell_calls",
    "mcp_calls",
    "aiopt_calls",
)


def categorize_tool(name: str) -> str:
    """Group a tool name into exploration, edit, shell, mcp or other."""
    if name in EXPLORATION_TOOLS:
        return "exploration"
    if name in EDIT_TOOLS:
        return "edit"
    if name == "Bash":
        return "shell"
    if name.startswith("mcp__"):
        return "mcp"
    return "other"


@dataclass(slots=True)
class SessionUsage:
    """Usage totals for one assistant session."""

    session_id: str
    source: str
    label: str | None = None
    started_at: str | None = None
    ended_at: str | None = None
    assistant_messages: int = 0
    sidechain_messages: int = 0
    input_tokens: int = 0
    output_tokens: int = 0
    cache_read_tokens: int = 0
    cache_write_tokens: int = 0
    tool_calls: dict[str, int] = field(default_factory=dict)
    aiopt_calls: int = 0
    models: list[str] = field(default_factory=list)

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)

    @classmethod
    def from_dict(cls, data: dict[str, Any]) -> SessionUsage:
        known = {name for name in cls.__dataclass_fields__}
        return cls(**{key: value for key, value in data.items() if key in known})

    def metric(self, name: str) -> int:
        """Value of one of ``METRICS`` for this session."""
        by_category: dict[str, int] = {}
        for tool, count in self.tool_calls.items():
            category = categorize_tool(tool)
            by_category[category] = by_category.get(category, 0) + count
        values = {
            "input_tokens": self.input_tokens,
            "output_tokens": self.output_tokens,
            "cache_read_tokens": self.cache_read_tokens,
            "cache_write_tokens": self.cache_write_tokens,
            "tool_calls": sum(self.tool_calls.values()),
            "exploration_calls": by_category.get("exploration", 0),
            "shell_calls": by_category.get("shell", 0),
            "mcp_calls": by_category.get("mcp", 0),
            "aiopt_calls": self.aiopt_calls,
        }
        return values[name]


def load_sessions(paths: ProjectPaths) -> dict[str, SessionUsage]:
    """Load stored sessions keyed by session id (empty when none are stored)."""
    file = paths.reports_dir / USAGE_FILE
    if not file.is_file():
        return {}
    try:
        raw = json.loads(file.read_text(encoding="utf-8"))
        return {k: SessionUsage.from_dict(v) for k, v in raw["sessions"].items()}
    except (OSError, json.JSONDecodeError, KeyError, TypeError, AttributeError) as exc:
        raise ConfigError(f"Cannot read {file}: {exc}") from exc


def save_sessions(paths: ProjectPaths, sessions: dict[str, SessionUsage]) -> None:
    """Write sessions atomically."""
    paths.reports_dir.mkdir(parents=True, exist_ok=True)
    file = paths.reports_dir / USAGE_FILE
    document = {
        "version": USAGE_VERSION,
        "source": SOURCE_NOTE,
        "sessions": {k: v.to_dict() for k, v in sorted(sessions.items())},
    }
    temp = file.with_suffix(".tmp")
    temp.write_text(json.dumps(document, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    os.replace(temp, file)


def upsert_sessions(
    paths: ProjectPaths, incoming: list[SessionUsage], label: str | None
) -> tuple[int, int]:
    """Insert or refresh sessions; keep an existing label unless a new one is given."""
    stored = load_sessions(paths)
    added = updated = 0
    for session in incoming:
        previous = stored.get(session.session_id)
        session.label = label or (previous.label if previous else None)
        if previous is None:
            added += 1
        elif previous.to_dict() != session.to_dict():
            updated += 1
        stored[session.session_id] = session
    save_sessions(paths, stored)
    return added, updated


def set_label(paths: ProjectPaths, session_prefix: str, label: str) -> SessionUsage:
    """Label the single session whose id starts with ``session_prefix``."""
    stored = load_sessions(paths)
    matches = [sid for sid in stored if sid.startswith(session_prefix)]
    if not matches:
        raise ConfigError(f"No stored session starts with '{session_prefix}'.")
    if len(matches) > 1:
        raise ConfigError(f"'{session_prefix}' is ambiguous ({len(matches)} sessions match).")
    stored[matches[0]].label = label
    save_sessions(paths, stored)
    return stored[matches[0]]


def _group_stats(sessions: list[SessionUsage]) -> dict[str, Any]:
    return {
        "sessions": len(sessions),
        "mean": {m: round(statistics.fmean(s.metric(m) for s in sessions), 1) for m in METRICS},
        "median": {m: round(statistics.median(s.metric(m) for s in sessions), 1) for m in METRICS},
    }


def usage_report(paths: ProjectPaths, baseline: str = "baseline") -> dict[str, Any]:
    """Group stored sessions by label and compare each group with ``baseline``."""
    sessions = list(load_sessions(paths).values())
    groups: dict[str, list[SessionUsage]] = {}
    for session in sessions:
        groups.setdefault(session.label or UNLABELED, []).append(session)
    stats = {label: _group_stats(members) for label, members in sorted(groups.items())}
    warnings = [
        f"Group '{label}' has {len(members)} session(s); fewer than "
        f"{MIN_SESSIONS_FOR_COMPARISON} means differences are not statistically meaningful."
        for label, members in sorted(groups.items())
        if len(members) < MIN_SESSIONS_FOR_COMPARISON
    ]
    comparisons = []
    if baseline in stats:
        for label, data in stats.items():
            if label == baseline:
                continue
            delta: dict[str, float | None] = {}
            for metric in METRICS:
                base = stats[baseline]["mean"][metric]
                delta[metric] = (
                    round((data["mean"][metric] - base) / base * 100, 1) if base else None
                )
            comparisons.append({"group": label, "versus": baseline, "mean_change_percent": delta})
    return {
        "source": SOURCE_NOTE,
        "sessions": len(sessions),
        "groups": stats,
        "comparisons": comparisons,
        "warnings": warnings,
        "notes": [
            "Token counts are provider-reported and listed separately; they are not summed or "
            "converted to cost.",
            "Differences between groups are observations, not proof: task difficulty and model "
            "variance affect them.",
        ],
    }
