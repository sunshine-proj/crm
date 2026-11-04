"""Metrics tests: estimation and honest labeling."""

from __future__ import annotations

import json
from pathlib import Path

from ai_optimizer.core.config import ProjectPaths
from ai_optimizer.metrics.estimate import ESTIMATION_METHOD, estimate_tokens
from ai_optimizer.metrics.store import METRICS_FILE, read_events, record_event, summarize


def test_estimate_tokens_is_ceil_of_chars_over_four() -> None:
    assert estimate_tokens("") == 0
    assert estimate_tokens("abcd") == 1
    assert estimate_tokens("abcde") == 2
    assert estimate_tokens("x" * 400) == 100


def test_method_is_labeled_as_estimate() -> None:
    assert "estimate" in ESTIMATION_METHOD and "not provider-reported" in ESTIMATION_METHOD


def test_summary_aggregates_events(tmp_path: Path) -> None:
    paths = ProjectPaths(tmp_path)
    record_event(paths, "cache_update", {"rehashed": 10, "hash_skipped": 0, "indexed_total": 10})
    record_event(paths, "cache_update", {"rehashed": 1, "hash_skipped": 9, "indexed_total": 10})
    record_event(paths, "context_build", {"bytes": 1000, "estimated_tokens": 250})
    record_event(paths, "context_build", {"bytes": 3000, "estimated_tokens": 750})
    record_event(paths, "task_run", {"status": "passed", "duration_seconds": 1.0})
    record_event(paths, "task_run", {"status": "failed", "duration_seconds": 3.0})
    report = summarize(paths)
    assert report["cache"]["hash_skip_rate"] == 0.45
    assert report["cache"]["files_rehashed"] == 11
    assert report["retrieval"]["avg_package_bytes"] == 2000.0
    assert report["retrieval"]["avg_package_estimated_tokens"] == 500.0
    assert report["tasks"] == {
        "runs": 2, "passed": 1, "failed": 1, "avg_duration_seconds": 2.0,
    }  # fmt: skip
    assert report["token_source"] == "estimated"
    assert report["provider_reported_usage"] is None


def test_empty_summary_has_no_invented_numbers(tmp_path: Path) -> None:
    report = summarize(ProjectPaths(tmp_path))
    assert report["cache"]["hash_skip_rate"] is None
    assert report["retrieval"]["avg_package_bytes"] is None
    assert report["tasks"]["avg_duration_seconds"] is None


def test_malformed_lines_are_ignored(tmp_path: Path) -> None:
    paths = ProjectPaths(tmp_path)
    record_event(paths, "context_search", {"results": 1})
    with (paths.reports_dir / METRICS_FILE).open("a") as handle:
        handle.write("not json\n[1, 2]\n" + json.dumps({"no_type": True}) + "\n")
    assert len(read_events(paths)) == 1


def test_recording_failure_never_raises(tmp_path: Path) -> None:
    blocker = tmp_path / ".ai-optimizer"
    blocker.write_text("a file where the directory should be")
    record_event(ProjectPaths(tmp_path), "context_search", {})
