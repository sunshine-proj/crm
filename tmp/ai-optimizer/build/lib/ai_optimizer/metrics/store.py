"""Append-only local metrics log and aggregation.

Token figures are estimates. No provider-reported usage is recorded in the MVP.
"""

from __future__ import annotations

import json
from datetime import UTC, datetime
from typing import Any

from ai_optimizer.core.config import ProjectPaths
from ai_optimizer.core.log import get_logger
from ai_optimizer.metrics.estimate import ESTIMATION_METHOD

log = get_logger("metrics")
METRICS_FILE = "metrics.jsonl"


def record_event(paths: ProjectPaths, event_type: str, data: dict[str, Any]) -> None:
    """Append one event. Failures are logged and never break the calling command."""
    event = {"ts": datetime.now(UTC).isoformat(timespec="seconds"), "type": event_type, **data}
    try:
        paths.reports_dir.mkdir(parents=True, exist_ok=True)
        with (paths.reports_dir / METRICS_FILE).open("a", encoding="utf-8") as handle:
            handle.write(json.dumps(event, sort_keys=True) + "\n")
    except OSError as exc:
        log.warning("Could not record metrics: %s", exc)


def read_events(paths: ProjectPaths) -> list[dict[str, Any]]:
    """Read recorded events, skipping malformed lines."""
    file = paths.reports_dir / METRICS_FILE
    if not file.is_file():
        return []
    events: list[dict[str, Any]] = []
    for line in file.read_text(encoding="utf-8", errors="replace").splitlines():
        try:
            parsed = json.loads(line)
        except json.JSONDecodeError:
            continue
        if isinstance(parsed, dict) and "type" in parsed:
            events.append(parsed)
    return events


def summarize(paths: ProjectPaths) -> dict[str, Any]:
    """Aggregate recorded events into a report. Every token value is an estimate."""
    events = read_events(paths)
    by_type: dict[str, list[dict[str, Any]]] = {}
    for event in events:
        by_type.setdefault(str(event["type"]), []).append(event)

    updates = by_type.get("cache_update", [])
    rehashed = sum(int(e.get("rehashed", 0)) for e in updates)
    skipped = sum(int(e.get("hash_skipped", 0)) for e in updates)
    builds = by_type.get("context_build", [])
    runs = by_type.get("task_run", [])
    return {
        "events_recorded": len(events),
        "token_source": "estimated",
        "estimation_method": ESTIMATION_METHOD,
        "provider_reported_usage": None,
        "cache": {
            "updates": len(updates),
            "indexed_files": int(updates[-1].get("indexed_total", 0)) if updates else 0,
            "files_rehashed": rehashed,
            "files_hash_skipped": skipped,
            "hash_skip_rate": round(skipped / (rehashed + skipped), 4)
            if rehashed + skipped
            else None,
        },
        "retrieval": {
            "searches": len(by_type.get("context_search", [])),
            "packages_built": len(builds),
            "avg_package_bytes": _average(builds, "bytes"),
            "avg_package_estimated_tokens": _average(builds, "estimated_tokens"),
        },
        "tasks": {
            "runs": len(runs),
            "passed": sum(1 for e in runs if e.get("status") == "passed"),
            "failed": sum(1 for e in runs if e.get("status") != "passed"),
            "avg_duration_seconds": _average(runs, "duration_seconds", digits=3),
        },
    }


def _average(events: list[dict[str, Any]], key: str, digits: int = 1) -> float | None:
    values = [float(e[key]) for e in events if key in e]
    return round(sum(values) / len(values), digits) if values else None
