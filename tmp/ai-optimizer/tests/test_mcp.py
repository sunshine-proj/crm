"""MCP server tests: tool surface, security parity with the CLI, and the stdio protocol."""

from __future__ import annotations

import asyncio
import json
import subprocess
import sys
import threading
from collections.abc import Callable
from pathlib import Path
from typing import Any

import pytest

pytest.importorskip("mcp")

from ai_optimizer import services  # noqa: E402
from ai_optimizer.core.project import Project  # noqa: E402
from ai_optimizer.integrations.mcp_server import (  # noqa: E402
    OUTPUT_TAIL_CHARS,
    TOOL_NAMES,
    compact_report,
    compact_update,
    create_server,
)
from tests.conftest import PYTHON_NAME  # noqa: E402

Writer = Callable[[Path, str, str], Path]


def call(server: Any, tool: str, /, **arguments: Any) -> tuple[bool, Any]:
    """Call a tool; return (is_error, parsed JSON or error text)."""
    try:
        result = asyncio.run(server.call_tool(tool, arguments))
    except Exception as exc:  # some SDK versions raise instead of returning is_error
        return True, str(exc)
    text = "".join(getattr(part, "text", "") for part in result.content)
    if result.is_error:
        return True, text
    return False, json.loads(text)


@pytest.fixture
def server(project: Project) -> Any:
    return create_server(project.root)


def test_exposes_exactly_the_documented_tools(server: Any) -> None:
    names = {tool.name for tool in asyncio.run(server.list_tools())}
    assert names == set(TOOL_NAMES)
    assert len(TOOL_NAMES) == 9


def test_no_tool_can_request_destructive_execution(server: Any) -> None:
    for tool in asyncio.run(server.list_tools()):
        properties = json.dumps(
            tool.input_schema if hasattr(tool, "input_schema") else tool.inputSchema
        )
        assert "destructive" not in properties
    run_task = next(t for t in asyncio.run(server.list_tools()) if t.name == "run_task")
    schema = getattr(run_task, "input_schema", None) or run_task.inputSchema
    assert set(schema["properties"]) == {"name"}


def test_read_tools(server: Any) -> None:
    error, info = call(server, "get_project_info")
    assert not error and info["name"] == "shop" and "python" in info["languages"]
    error, found = call(server, "search_context", query="password reset", limit=2)
    assert not error and found["results"][0]["path"] == "src/shop/auth.py"
    error, profiles = call(server, "get_context_profile")
    assert not error and "backend" in {p["name"] for p in profiles["profiles"]}
    error, backend = call(server, "get_context_profile", name="backend")
    assert not error and backend["name"] == "backend"
    error, status = call(server, "get_cache_status")
    assert not error and status["exists"] is True
    error, tasks = call(server, "list_tasks")
    assert not error and tasks["trusted"] is False


def test_build_context_returns_one_rendition(server: Any) -> None:
    error, built = call(server, "build_context", task="password reset", max_tokens=1500)
    assert not error
    assert built["format"] == "md" and built["content"].startswith("# Context Package")
    assert built["estimated_tokens"] <= 1500
    error, as_json = call(server, "build_context", task="password reset", format="json")
    assert not error and json.loads(as_json["content"])["task"] == "password reset"


def test_errors_surface_as_tool_errors(server: Any) -> None:
    error, message = call(server, "search_context", query="the of")
    assert error and "searchable terms" in message
    error, message = call(server, "build_context")
    assert error and "--task" in message
    error, message = call(server, "get_context_profile", name="nope")
    assert error and "Unknown profile" in message


def test_refresh_and_analyze(server: Any, project: Project, write: Writer) -> None:
    services.cache_update(project)
    write(project.root, "src/shop/orders.py", "def place_order(): pass\n")
    error, update = call(server, "refresh_cache")
    assert not error
    assert update["added"] == {"count": 1, "sample": ["src/shop/orders.py"]}
    assert update["rehashed"] == 1
    error, info = call(server, "analyze_project")
    assert not error and info["primary_language"] == "python"


def test_compact_update_caps_listed_paths() -> None:
    result = {
        "indexed_total": 500, "rehashed": 500, "hash_skipped": 0, "rebuilt": False,
        "metadata_refreshed": True, "added": [f"f{i}.py" for i in range(500)], "changed": [],
        "removed": [], "renamed": [],
    }  # fmt: skip
    compact = compact_update(result)
    assert compact["added"]["count"] == 500 and len(compact["added"]["sample"]) == 20


# --- run_task: same security as the CLI -------------------------------------------------------


@pytest.fixture
def runnable(project: Project, write: Writer, allow_python: None) -> Project:
    write(project.root, "ok.py", "print('quiet success output ' * 50)\n")
    write(
        project.root,
        "bad.py",
        "import sys\nprint('noise' * 10)\nprint('the real error', file=sys.stderr)\nsys.exit(4)\n",
    )
    for name in ("ok", "bad"):
        write(
            project.root,
            f".ai-optimizer/tasks/{name}.yaml",
            f"steps:\n  - {{name: run, command: [{PYTHON_NAME}, {name}.py]}}\n",
        )
    return project


def test_run_task_requires_trust(runnable: Project) -> None:
    error, message = call(create_server(runnable.root), "run_task", name="ok")
    assert error and "not trusted" in message


def test_run_task_after_trust_is_compact(runnable: Project) -> None:
    services.trust_project(runnable)
    server = create_server(runnable.root)
    error, report = call(server, "run_task", name="ok")
    assert not error and report["status"] == "passed"
    assert "quiet success output" not in json.dumps(report)  # passing output is dropped
    assert report["report_files"]
    error, failed = call(server, "run_task", name="bad")
    assert not error and failed["status"] == "failed"
    step = failed["steps"][0]
    assert step["exit_code"] == 4 and "the real error" in step["output_tail"]


def test_run_task_cannot_run_destructive_tasks(runnable: Project, write: Writer) -> None:
    write(
        runnable.root,
        ".ai-optimizer/tasks/wipe.yaml",
        f"destructive: true\nsteps:\n  - {{name: s, command: [{PYTHON_NAME}, ok.py]}}\n",
    )
    services.trust_project(runnable)
    error, message = call(create_server(runnable.root), "run_task", name="wipe")
    assert error and "destructive" in message


def test_run_task_enforces_the_allowlist(runnable: Project, write: Writer) -> None:
    write(
        runnable.root,
        ".ai-optimizer/tasks/evil.yaml",
        "steps:\n  - {name: s, command: [sh, -c, 'echo hi']}\n",
    )
    services.trust_project(runnable)
    error, message = call(create_server(runnable.root), "run_task", name="evil")
    assert error and "denied" in message


def test_trust_cannot_be_granted_through_mcp(server: Any) -> None:
    names = {tool.name for tool in asyncio.run(server.list_tools())}
    assert not any("trust" in name for name in names)


def test_compact_report_truncates_long_failure_output() -> None:
    report = {
        "task": "t", "status": "failed", "duration_seconds": 1.0, "report_files": ["r.json"],
        "steps": [
            {"name": "s", "status": "failed", "exit_code": 1, "duration_seconds": 1.0,
             "skip_reason": None, "stdout": "x" * 10_000, "stderr": "tail marker"},
        ],
    }  # fmt: skip
    step = compact_report(report)["steps"][0]
    assert len(step["output_tail"]) == OUTPUT_TAIL_CHARS


# --- real stdio protocol ------------------------------------------------------------------------


def rpc(process: subprocess.Popen[str], payload: dict[str, Any]) -> dict[str, Any]:
    assert process.stdin is not None and process.stdout is not None
    process.stdin.write(json.dumps(payload) + "\n")
    process.stdin.flush()
    line = process.stdout.readline()
    assert line, "server closed stdout"
    return json.loads(line)  # raises if anything but JSON-RPC reaches stdout


def test_stdio_server_speaks_clean_json_rpc(project: Project) -> None:
    process = subprocess.Popen(
        [sys.executable, "-m", "ai_optimizer", "--project-dir", str(project.root), "mcp", "serve"],
        stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
    )  # fmt: skip
    watchdog = threading.Timer(60, process.kill)
    watchdog.start()
    try:
        init = rpc(
            process,
            {
                "jsonrpc": "2.0", "id": 1, "method": "initialize",
                "params": {
                    "protocolVersion": "2025-06-18", "capabilities": {},
                    "clientInfo": {"name": "test", "version": "0"},
                },
            },
        )  # fmt: skip
        assert init["result"]["serverInfo"]["name"] == "aiopt"
        assert process.stdin is not None
        process.stdin.write(
            json.dumps({"jsonrpc": "2.0", "method": "notifications/initialized"}) + "\n"
        )
        process.stdin.flush()
        listed = rpc(process, {"jsonrpc": "2.0", "id": 2, "method": "tools/list"})
        assert {t["name"] for t in listed["result"]["tools"]} == set(TOOL_NAMES)
        called = rpc(
            process,
            {
                "jsonrpc": "2.0", "id": 3, "method": "tools/call",
                "params": {"name": "search_context", "arguments": {"query": "password reset"}},
            },
        )  # fmt: skip
        text = called["result"]["content"][0]["text"]
        assert json.loads(text)["results"][0]["path"] == "src/shop/auth.py"
    finally:
        watchdog.cancel()
        process.kill()
        process.wait()
