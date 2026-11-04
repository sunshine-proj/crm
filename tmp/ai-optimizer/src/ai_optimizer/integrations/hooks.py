"""Claude Code SessionStart hook: a few lines of context, or nothing at all."""

from __future__ import annotations

from pathlib import Path

from ai_optimizer import services
from ai_optimizer.core.project import load_project


def session_start_summary(project_dir: Path | None = None) -> str:
    """Return a short status for the session, or "" when AI Optimizer is not set up.

    Read-only and fast; it never refreshes the cache and never raises.
    """
    try:
        project = load_project(project_dir)
        if not project.paths.base.is_dir():
            return ""
        status = services.cache_status(project)
        if not status["exists"]:
            cache = "cache not built (run `aiopt cache update`)"
        elif not status["healthy"]:
            cache = "cache unhealthy (run `aiopt cache update` to rebuild)"
        elif status["stale"]:
            pending = sum(status["pending"].values())
            cache = f"cache stale ({pending} pending changes)"
        else:
            cache = f"{status['indexed_files']} files indexed, cache current"
        tasks = services.list_tasks(project)
        trust = (
            "tasks trusted"
            if tasks["trusted"]
            else "tasks NOT trusted: ask the user to review .ai-optimizer/ and run `aiopt trust`"
        )
        return (
            f"AI Optimizer is set up for {project.name}: {cache}; {trust}.\n"
            'Use `aiopt context build --task "<task>"` before exploring files and '
            "`aiopt task run validate` to validate."
        )
    except Exception:  # a hook must never disturb the session
        return ""
