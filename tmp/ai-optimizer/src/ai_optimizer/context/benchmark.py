"""Retrieval benchmark: labeled cases, scoring and summary statistics.

This measures retrieval relevance and context size against explicit baselines. It does not
measure downstream assistant behavior; see docs/benchmark.md for that protocol.
"""

from __future__ import annotations

import statistics
from pathlib import Path
from typing import Any

from pydantic import Field, ValidationError, field_validator

from ai_optimizer.core.config import StrictModel, format_validation_error, load_yaml_mapping
from ai_optimizer.core.errors import ConfigError
from ai_optimizer.core.globs import match_glob


class BenchCase(StrictModel):
    """One labeled question: a query and the files that should be found."""

    id: str
    query: str = Field(min_length=3)
    task: str | None = None
    profile: str | None = None
    expect: list[str] = Field(min_length=1)


class BenchSpec(StrictModel):
    """A set of cases scored at depth ``k``."""

    name: str = "benchmark"
    k: int = Field(default=5, ge=1, le=50)
    cases: list[BenchCase] = Field(min_length=1)

    @field_validator("cases")
    @classmethod
    def _unique_ids(cls, cases: list[BenchCase]) -> list[BenchCase]:
        ids = [case.id for case in cases]
        if len(set(ids)) != len(ids):
            raise ValueError("case ids must be unique")
        return cases


def load_spec(path: Path) -> BenchSpec:
    """Load and validate a benchmark specification."""
    try:
        text = path.read_text(encoding="utf-8")
    except (OSError, UnicodeDecodeError) as exc:
        raise ConfigError(f"Cannot read benchmark spec {path}: {exc}") from exc
    try:
        return BenchSpec.model_validate(load_yaml_mapping(text, str(path)))
    except ValidationError as exc:
        raise ConfigError(format_validation_error(exc, str(path))) from exc


def score_paths(ranked: list[str], expect: list[str], k: int) -> dict[str, Any]:
    """Score a ranked path list against expected globs.

    ``recall`` is the share of expected globs matched by at least one of the top ``k`` paths.
    ``first_rank`` is the 1-based rank of the first path matching any expected glob, over the
    whole list, so reciprocal rank is meaningful even when the answer falls outside the top k.
    """
    top = ranked[:k]
    matched = [g for g in expect if any(match_glob(g, p) for p in top)]
    first_rank = next(
        (i for i, p in enumerate(ranked, start=1) if any(match_glob(g, p) for g in expect)), None
    )
    return {
        "matched": matched,
        "missed": [g for g in expect if g not in matched],
        "recall": round(len(matched) / len(expect), 4),
        "hit": len(matched) == len(expect),
        "first_rank": first_rank,
        "reciprocal_rank": round(1 / first_rank, 4) if first_rank else 0.0,
    }


def _mean(values: list[float]) -> float | None:
    return round(statistics.fmean(values), 4) if values else None


def summarize(results: list[dict[str, Any]]) -> dict[str, Any]:
    """Aggregate per-case results. Ratios use only cases where the baseline is non-zero."""
    search = [r["search"] for r in results]
    package = [r["package"] for r in results]

    def ratio(numerator: str, baseline: str) -> list[float]:
        return [
            r["package"][numerator] / r["baselines"][baseline]
            for r in results
            if r["baselines"][baseline] > 0
        ]

    return {
        "cases": len(results),
        "search": {
            "mean_recall_at_k": _mean([s["recall"] for s in search]),
            "full_hit_rate": _mean([1.0 if s["hit"] else 0.0 for s in search]),
            "mrr": _mean([s["reciprocal_rank"] for s in search]),
        },
        "search_at_package_depth": {
            "mean_recall": _mean([r["search_at_package_depth"]["recall"] for r in results]),
            "full_hit_rate": _mean(
                [1.0 if r["search_at_package_depth"]["hit"] else 0.0 for r in results]
            ),
        },
        "package": {
            "mean_items": _mean([float(p["files"]) for p in package]),
            "mean_recall": _mean([p["recall"] for p in package]),
            "full_hit_rate": _mean([1.0 if p["hit"] else 0.0 for p in package]),
            "mean_estimated_tokens": _mean([float(p["estimated_tokens"]) for p in package]),
        },
        "size_ratios": {
            "package_vs_whole_repo": _mean(ratio("estimated_tokens", "whole_repo_tokens")),
            "package_vs_grep_hits": _mean(ratio("estimated_tokens", "grep_tokens")),
            "package_vs_top_k_files": _mean(ratio("estimated_tokens", "top_k_read_tokens")),
        },
        "median_elapsed_ms": round(statistics.median(r["elapsed_ms"] for r in results), 1),
    }
