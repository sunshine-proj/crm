"""Secure task execution: argument arrays only, timeouts, scrubbed environment."""

from __future__ import annotations

import json
import os
import signal
import subprocess
import sys
import time
from dataclasses import dataclass
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

from ai_optimizer.core.config import ProjectConfig, ProjectPaths
from ai_optimizer.core.errors import PermissionDeniedError
from ai_optimizer.core.files import is_within
from ai_optimizer.core.redact import redact
from ai_optimizer.tasks.models import Step, TaskDefinition
from ai_optimizer.tasks.permissions import CommandPolicy, is_destructive_command

TIMEOUT_GRACE_SECONDS = 5


@dataclass(frozen=True, slots=True)
class PreparedStep:
    """A step that passed validation, with its resolved executable and working directory."""

    step: Step
    executable: str
    cwd: Path
    timeout: int


def prepare_task(
    paths: ProjectPaths,
    config: ProjectConfig,
    task: TaskDefinition,
    policy: CommandPolicy,
    *,
    allow_destructive: bool = False,
) -> list[PreparedStep]:
    """Validate every step before anything runs (all-or-nothing)."""
    if task.destructive and not allow_destructive:
        raise PermissionDeniedError(
            f"Task '{task.name}' is marked destructive. Re-run with --allow-destructive to proceed."
        )
    prepared: list[PreparedStep] = []
    for step in task.ordered_steps():
        if is_destructive_command(step.command) and not allow_destructive:
            raise PermissionDeniedError(
                f"Step '{step.name}' runs a potentially destructive command "
                f"({step.command[0]}). Re-run with --allow-destructive to proceed."
            )
        executable = policy.check(step.command)
        cwd = (paths.root / step.cwd).resolve()
        if not is_within(paths.root, cwd) or not cwd.is_dir():
            raise PermissionDeniedError(
                f"Step '{step.name}': working directory '{step.cwd}' is outside the project "
                "root or does not exist."
            )
        timeout = step.timeout_seconds or config.tasks.default_timeout_seconds
        prepared.append(PreparedStep(step, executable, cwd, timeout))
    return prepared


def _decode(data: bytes, limit: int) -> tuple[str, bool]:
    truncated = len(data) > limit
    text = data[:limit].decode("utf-8", errors="replace")
    return redact(text), truncated


def _kill_tree(process: subprocess.Popen[bytes]) -> None:
    if sys.platform != "win32":
        try:
            os.killpg(process.pid, signal.SIGKILL)
        except (ProcessLookupError, PermissionError):
            process.kill()
    else:
        process.kill()


def _run_step(prepared: PreparedStep, env: dict[str, str], max_output: int) -> dict[str, Any]:
    argv = [prepared.executable, *prepared.step.command[1:]]
    started = time.perf_counter()
    record: dict[str, Any] = {
        "name": prepared.step.name,
        "command": [redact(part) for part in prepared.step.command],
        "executable": prepared.executable,
        "status": "passed",
        "exit_code": None,
        "stdout": "",
        "stderr": "",
        "truncated": False,
        "skip_reason": None,
    }
    try:
        process = subprocess.Popen(  # noqa: S603 - argv is validated against the allowlist
            argv,
            cwd=prepared.cwd,
            env=env,
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            start_new_session=sys.platform != "win32",
        )
    except OSError as exc:
        record.update(status="error", stderr=redact(f"Could not start process: {exc}"))
        record["duration_seconds"] = round(time.perf_counter() - started, 3)
        return record
    try:
        out, err = process.communicate(timeout=prepared.timeout)
        record["exit_code"] = process.returncode
        record["status"] = "passed" if process.returncode == 0 else "failed"
    except subprocess.TimeoutExpired:
        _kill_tree(process)
        try:
            out, err = process.communicate(timeout=TIMEOUT_GRACE_SECONDS)
        except subprocess.TimeoutExpired:
            out, err = b"", b""
        record["status"] = "timeout"
        err += f"\n[timed out after {prepared.timeout}s]".encode()
    record["stdout"], out_truncated = _decode(out, max_output)
    record["stderr"], err_truncated = _decode(err, max_output)
    record["truncated"] = out_truncated or err_truncated
    record["duration_seconds"] = round(time.perf_counter() - started, 3)
    return record


def execute_task(
    task: TaskDefinition,
    prepared: list[PreparedStep],
    policy: CommandPolicy,
    config: ProjectConfig,
) -> dict[str, Any]:
    """Run prepared steps in order and return a structured report."""
    started_at = datetime.now(UTC)
    clock = time.perf_counter()
    env = policy.environment()
    outcomes: dict[str, str] = {}
    steps: list[dict[str, Any]] = []
    stopped_by: str | None = None
    for item in prepared:
        step = item.step
        failed_dep = next((d for d in step.depends_on if outcomes.get(d) != "passed"), None)
        skip_reason = None
        if stopped_by is not None:
            skip_reason = f"task stopped after step '{stopped_by}' failed"
        elif failed_dep is not None:
            skip_reason = f"dependency '{failed_dep}' did not pass"
        if skip_reason:
            outcomes[step.name] = "skipped"
            steps.append(
                {
                    "name": step.name,
                    "command": [redact(p) for p in step.command],
                    "executable": item.executable,
                    "status": "skipped",
                    "exit_code": None,
                    "stdout": "",
                    "stderr": "",
                    "truncated": False,
                    "skip_reason": skip_reason,
                    "duration_seconds": 0.0,
                }
            )
            continue
        record = _run_step(item, env, config.tasks.max_output_bytes)
        outcomes[step.name] = record["status"]
        steps.append(record)
        if record["status"] != "passed" and step.on_failure == "stop":
            stopped_by = step.name
    passed = all(s["status"] == "passed" for s in steps)
    return {
        "schema_version": 1,
        "task": task.name,
        "description": task.description,
        "status": "passed" if passed else "failed",
        "started_at": started_at.isoformat(timespec="seconds"),
        "duration_seconds": round(time.perf_counter() - clock, 3),
        "steps": steps,
    }


def render_report_markdown(report: dict[str, Any]) -> str:
    """Render an execution report as Markdown."""
    lines = [
        f"# Task report: {report['task']}",
        "",
        f"- Status: **{report['status']}**",
        f"- Started: {report['started_at']}",
        f"- Duration: {report['duration_seconds']}s",
        "",
    ]
    for step in report["steps"]:
        code = "" if step["exit_code"] is None else f", exit code {step['exit_code']}"
        lines.append(f"## {step['name']} — {step['status']}{code}")
        lines.append(f"`{' '.join(step['command'])}`")
        if step["skip_reason"]:
            lines.append(f"Skipped: {step['skip_reason']}")
        for stream in ("stdout", "stderr"):
            if step[stream].strip():
                lines += ["", f"{stream}:", "```", step[stream].rstrip(), "```"]
        if step["truncated"]:
            lines.append("_Output truncated._")
        lines.append("")
    return "\n".join(lines).rstrip() + "\n"


def write_report(paths: ProjectPaths, report: dict[str, Any]) -> list[str]:
    """Write JSON and Markdown reports to the reports directory; return their paths."""
    paths.reports_dir.mkdir(parents=True, exist_ok=True)
    stamp = datetime.now(UTC).strftime("%Y%m%dT%H%M%S%fZ")
    base = paths.reports_dir / f"{report['task']}-{stamp}"
    json_file = base.with_suffix(".json")
    md_file = base.with_suffix(".md")
    json_file.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    md_file.write_text(render_report_markdown(report), encoding="utf-8")
    return [str(json_file.relative_to(paths.root)), str(md_file.relative_to(paths.root))]
