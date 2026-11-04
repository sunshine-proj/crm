"""MCP server exposing the service layer. Requires the optional ``mcp`` package.

Tools call the same service functions as the CLI, so trust, allowlist and timeout rules
apply unchanged. Destructive operations are not exposed at all. Results are compacted:
a server whose purpose is saving context must not flood it.
"""

from __future__ import annotations

import functools
import importlib
from collections.abc import Callable
from pathlib import Path
from typing import Any

from ai_optimizer import services
from ai_optimizer.core.errors import AIOptError, ConfigError
from ai_optimizer.core.project import Project, load_project

TOOL_NAMES = (
    "get_project_info",
    "analyze_project",
    "search_context",
    "get_context_profile",
    "build_context",
    "get_cache_status",
    "refresh_cache",
    "list_tasks",
    "run_task",
)
MAX_LISTED_PATHS = 20
OUTPUT_TAIL_CHARS = 2000

INSTRUCTIONS = (
    "Local project intelligence. Use search_context or build_context before exploring "
    "files, and run_task('validate') instead of assembling lint/test commands. "
    "Token figures are estimates. Trusting a project is done by the user in a terminal."
)


def _import_server_class() -> Any:
    """Load MCPServer (mcp 2.x) or FastMCP (mcp 1.x) without a hard dependency."""
    for module_name, class_name in (
        ("mcp.server.mcpserver", "MCPServer"),
        ("mcp.server.fastmcp", "FastMCP"),
    ):
        try:
            return getattr(importlib.import_module(module_name), class_name)
        except (ImportError, AttributeError):
            continue
    raise ConfigError(
        "The MCP server needs the optional 'mcp' package: pip install 'ai-optimizer[mcp]'"
    )


def _import_tool_error() -> type[Exception]:
    """Load the SDK's ToolError, the only exception whose message reaches the model."""
    for module_name in ("mcp.server.mcpserver.exceptions", "mcp.server.fastmcp.exceptions"):
        try:
            error: type[Exception] = importlib.import_module(module_name).ToolError
            return error
        except (ImportError, AttributeError):
            continue
    return RuntimeError


def compact_update(result: dict[str, Any]) -> dict[str, Any]:
    """Summarize a cache update: counts plus a short sample of changed paths."""
    summary: dict[str, Any] = {
        key: result[key]
        for key in ("indexed_total", "rehashed", "hash_skipped", "rebuilt", "metadata_refreshed")
    }
    for key in ("added", "changed", "removed"):
        paths = result[key]
        summary[key] = {"count": len(paths), "sample": paths[:MAX_LISTED_PATHS]}
    summary["renamed"] = {"count": len(result["renamed"]), "sample": result["renamed"][:5]}
    return summary


def compact_report(report: dict[str, Any]) -> dict[str, Any]:
    """Drop output of passing steps and keep only the tail of failing ones."""
    steps = []
    for step in report["steps"]:
        entry = {
            key: step[key]
            for key in ("name", "status", "exit_code", "duration_seconds", "skip_reason")
        }
        if step["status"] not in {"passed", "skipped"}:
            output = (step["stderr"].strip() + "\n" + step["stdout"].strip()).strip()
            entry["output_tail"] = output[-OUTPUT_TAIL_CHARS:]
        steps.append(entry)
    return {
        "task": report["task"],
        "status": report["status"],
        "duration_seconds": report["duration_seconds"],
        "steps": steps,
        "report_files": report["report_files"],
    }


def create_server(project_dir: Path | None = None) -> Any:
    """Build the MCP server. The project is re-resolved on every call so edits apply."""
    server_class = _import_server_class()
    server = server_class("aiopt", instructions=INSTRUCTIONS)
    tool_error = _import_tool_error()

    def surfaced[**P, R](function: Callable[P, R]) -> Callable[P, R]:
        """Expose expected errors (not trusted, denied, bad input) to the model verbatim."""

        @functools.wraps(function)
        def wrapper(*args: P.args, **kwargs: P.kwargs) -> R:
            try:
                return function(*args, **kwargs)
            except AIOptError as exc:
                raise tool_error(str(exc)) from exc

        return wrapper

    def project() -> Project:
        return load_project(project_dir)

    def get_project_info() -> dict[str, Any]:
        """Detected languages, frameworks, package managers and development commands."""
        return services.project_info(project())

    def analyze_project() -> dict[str, Any]:
        """Re-analyze the project and refresh its generated metadata."""
        info: dict[str, Any] = services.analyze_project(project())["project"]
        return info

    def search_context(query: str, limit: int | None = None) -> dict[str, Any]:
        """Ranked keyword search over the project. Returns bounded results with excerpts."""
        return services.search_context(project(), query, limit)

    def get_context_profile(name: str | None = None) -> dict[str, Any]:
        """Show one context profile, or list all profiles when no name is given."""
        current = project()
        return services.show_profile(current, name) if name else services.list_profiles(current)

    def build_context(
        task: str | None = None,
        profile: str | None = None,
        max_tokens: int | None = None,
        format: str = "md",
    ) -> dict[str, Any]:
        """Build a compact context package for a task and/or profile (format: md or json)."""
        built = services.build_context(
            project(), task=task, profile_name=profile, max_tokens=max_tokens, fmt=format
        )
        package = built["package"]
        return {
            "format": format,
            "content": built["text"],
            "estimated_tokens": package["budget"]["estimated_tokens"],
            "warnings": package["warnings"],
        }

    def get_cache_status() -> dict[str, Any]:
        """Cache health, indexed file count and pending (unindexed) changes."""
        return services.cache_status(project())

    def refresh_cache(full: bool = False) -> dict[str, Any]:
        """Incrementally update the index. Only changed files are re-read."""
        return compact_update(services.cache_update(project(), full=full))

    def list_tasks() -> dict[str, Any]:
        """Defined tasks and whether the project has been trusted for execution."""
        return services.list_tasks(project())

    def run_task(name: str) -> dict[str, Any]:
        """Run a task. Needs a user-trusted project; destructive tasks are never run."""
        return compact_report(services.run_task(project(), name, allow_destructive=False))

    for tool in (
        get_project_info,
        analyze_project,
        search_context,
        get_context_profile,
        build_context,
        get_cache_status,
        refresh_cache,
        list_tasks,
        run_task,
    ):
        server.add_tool(surfaced(tool))
    return server


def serve(project_dir: Path | None = None) -> None:
    """Run the server over stdio (stdout carries the protocol; logs go to stderr)."""
    create_server(project_dir).run("stdio")
