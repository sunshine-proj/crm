"""Optimization report: every figure is tagged with where it came from."""

from __future__ import annotations

from datetime import UTC, datetime
from typing import Any

LOCAL_SOURCE = "measured locally; token figures are estimates (characters/4)"
BENCH_SOURCE = (
    "measured locally against labeled cases; token figures are estimates, "
    "and this does not measure assistant behavior"
)


def build_report(
    metrics: dict[str, Any], bench: dict[str, Any] | None, usage: dict[str, Any] | None
) -> dict[str, Any]:
    """Combine the three evidence sources without mixing them."""
    return {
        "generated_at": datetime.now(UTC).isoformat(timespec="seconds"),
        "local_operations": {"source": LOCAL_SOURCE, **metrics},
        "retrieval_benchmark": None if bench is None else {"source": BENCH_SOURCE, **bench},
        "provider_usage": usage,
    }


def _pct(value: float | None) -> str:
    return "n/a" if value is None else f"{value * 100:.1f}%"


def _num(value: float | None) -> str:
    return "n/a" if value is None else f"{value:g}"


def render_markdown(report: dict[str, Any]) -> str:
    """Render the report as Markdown."""
    local = report["local_operations"]
    cache, retrieval, tasks = local["cache"], local["retrieval"], local["tasks"]
    lines = [
        "# AI Optimizer report",
        "",
        f"Generated {report['generated_at']}.",
        "",
        "## Local operations",
        f"_Source: {local['source']}._",
        "",
        f"- Cache updates: {cache['updates']}; files rehashed {cache['files_rehashed']}, "
        f"hashing skipped {cache['files_hash_skipped']} "
        f"(skip rate {_pct(cache['hash_skip_rate'])})",
        f"- Context packages built: {retrieval['packages_built']}; average "
        f"{_num(retrieval['avg_package_bytes'])} bytes, "
        f"~{_num(retrieval['avg_package_estimated_tokens'])} estimated tokens",
        f"- Searches: {retrieval['searches']}",
        f"- Task runs: {tasks['runs']} ({tasks['passed']} passed, {tasks['failed']} failed)",
        "",
        "## Retrieval benchmark",
    ]
    bench = report["retrieval_benchmark"]
    if bench is None:
        lines += ["_No benchmark has been run. Use `aiopt bench run SPEC`._", ""]
    else:
        summary = bench["summary"]
        s, p, r = summary["search"], summary["package"], summary["size_ratios"]
        same_depth = _pct(summary["search_at_package_depth"]["mean_recall"])
        lines += [
            f"_Source: {bench['source']}._",
            "",
            f"- Spec `{bench['spec']}`: {bench['summary']['cases']} cases, depth k={bench['k']}",
            f"- Search: recall@{bench['k']} {_pct(s['mean_recall_at_k'])}, "
            f"all expected files found {_pct(s['full_hit_rate'])}, MRR {_num(s['mrr'])}",
            f"- Context package: recall {_pct(p['mean_recall'])} with "
            f"{_num(p['mean_items'])} items on average (search at the same depth: "
            f"{same_depth}), ~{_num(p['mean_estimated_tokens'])} estimated tokens on average",
            f"- Package size vs whole repository {_pct(r['package_vs_whole_repo'])}, "
            f"vs files containing a query term {_pct(r['package_vs_grep_hits'])}, "
            f"vs reading the top-{bench['k']} files {_pct(r['package_vs_top_k_files'])}",
            "",
        ]
    lines.append("## Provider-reported usage")
    usage = report["provider_usage"]
    if not usage or not usage["sessions"]:
        lines += ["_No sessions imported. Use `aiopt usage import claude`._", ""]
    else:
        lines += [f"_Source: {usage['source']}._", ""]
        lines.append(
            "| Group | Sessions | Input | Output | Cache read | Tool calls | Exploration | aiopt |"
        )
        lines.append("| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |")
        for label, group in usage["groups"].items():
            m = group["mean"]
            lines.append(
                f"| {label} | {group['sessions']} | {_num(m['input_tokens'])} | "
                f"{_num(m['output_tokens'])} | {_num(m['cache_read_tokens'])} | "
                f"{_num(m['tool_calls'])} | {_num(m['exploration_calls'])} | "
                f"{_num(m['aiopt_calls'])} |"
            )
        lines += ["", "Means per session.", ""]
        for comparison in usage["comparisons"]:
            change = comparison["mean_change_percent"]
            lines.append(
                f"- `{comparison['group']}` vs `{comparison['versus']}`: output tokens "
                f"{_num(change['output_tokens'])}%, tool calls {_num(change['tool_calls'])}%, "
                f"exploration calls {_num(change['exploration_calls'])}%"
            )
        lines += [f"- Warning: {w}" for w in usage["warnings"]]
        lines += [f"- Note: {n}" for n in usage["notes"]]
        lines.append("")
    return "\n".join(lines).rstrip() + "\n"
