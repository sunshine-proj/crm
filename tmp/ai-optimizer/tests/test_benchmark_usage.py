"""Benchmark, usage import and report tests."""

from __future__ import annotations

import json
from collections.abc import Callable
from pathlib import Path
from typing import Any

import pytest
from typer.testing import CliRunner

from ai_optimizer import services
from ai_optimizer.cli import app
from ai_optimizer.context.benchmark import load_spec, score_paths, summarize
from ai_optimizer.core.errors import ConfigError
from ai_optimizer.core.project import Project
from ai_optimizer.integrations.claude_usage import (
    find_transcripts,
    import_claude_sessions,
    invokes_aiopt,
    parse_transcript,
)
from ai_optimizer.metrics import usage as usage_store
from ai_optimizer.metrics.report import build_report, render_markdown
from ai_optimizer.metrics.store import read_events

Writer = Callable[[Path, str, str], Path]
runner = CliRunner()
SECRET = "TOP-SECRET-CONVERSATION-TEXT"

# --- benchmark: spec and scoring ---------------------------------------------------------------

SPEC = """
name: fixture
k: 3
cases:
  - id: reset
    query: password reset email
    expect: ["src/shop/auth.py"]
  - id: cart
    query: add an item to the cart
    expect: ["src/shop/cart.py", "tests/test_*.py"]
  - id: impossible
    query: quantum entanglement scheduler
    expect: ["src/shop/does_not_exist.py"]
"""


@pytest.fixture
def spec_file(project_dir: Path, write: Writer) -> Path:
    return write(project_dir, ".ai-optimizer/bench/fixture.yaml", SPEC)


@pytest.mark.parametrize(
    "text",
    [
        "cases: []",
        "name: x",
        "cases:\n  - {id: a, query: hello world, expect: []}",
        "cases:\n  - {id: a, query: hi, expect: [x]}",
        "cases:\n  - {id: a, query: hello world, expect: [x], bogus: 1}",
        "cases:\n  - {id: a, query: hello world, expect: [x]}\n  - {id: a, query: hello again, expect: [y]}",
        "k: 0\ncases:\n  - {id: a, query: hello world, expect: [x]}",
    ],
)
def test_invalid_specs_are_rejected(tmp_path: Path, text: str) -> None:
    file = tmp_path / "s.yaml"
    file.write_text(text)
    with pytest.raises(ConfigError):
        load_spec(file)


def test_missing_spec_file(tmp_path: Path) -> None:
    with pytest.raises(ConfigError, match="Cannot read"):
        load_spec(tmp_path / "nope.yaml")


def test_score_paths_recall_hit_and_rank() -> None:
    ranked = ["a.py", "b.py", "docs/c.md", "d.py", "e.py"]
    full = score_paths(ranked, ["a.py", "docs/*.md"], k=3)
    assert full["recall"] == 1.0 and full["hit"] and full["first_rank"] == 1
    partial = score_paths(ranked, ["a.py", "e.py"], k=3)
    assert partial["recall"] == 0.5 and not partial["hit"] and partial["missed"] == ["e.py"]
    outside = score_paths(ranked, ["e.py"], k=3)
    assert outside["recall"] == 0.0 and outside["first_rank"] == 5
    assert outside["reciprocal_rank"] == 0.2
    none = score_paths(ranked, ["zzz.py"], k=3)
    assert none["first_rank"] is None and none["reciprocal_rank"] == 0.0


def case_result(
    recall: float, hit: bool, rr: float, tokens: int, repo: int, grep: int
) -> dict[str, Any]:
    score = {"recall": recall, "hit": hit, "reciprocal_rank": rr}
    return {
        "search": score,
        "search_at_package_depth": score,
        "package": {"recall": recall, "hit": hit, "files": 4, "estimated_tokens": tokens},
        "baselines": {"whole_repo_tokens": repo, "grep_tokens": grep, "top_k_read_tokens": 400},
        "elapsed_ms": 10.0,
    }


def test_summarize_averages_and_skips_zero_baselines() -> None:
    summary = summarize(
        [case_result(1.0, True, 1.0, 100, 1000, 500), case_result(0.5, False, 0.5, 200, 1000, 0)]
    )
    assert summary["cases"] == 2
    assert summary["search"]["mean_recall_at_k"] == 0.75
    assert summary["search"]["full_hit_rate"] == 0.5
    assert summary["search"]["mrr"] == 0.75
    assert summary["package"]["mean_estimated_tokens"] == 150.0
    assert summary["size_ratios"]["package_vs_whole_repo"] == 0.15  # (0.1 + 0.2) / 2
    assert summary["size_ratios"]["package_vs_grep_hits"] == 0.2  # second case has no baseline


# --- benchmark: running it ---------------------------------------------------------------------


def test_bench_run_scores_hits_and_misses(project: Project, spec_file: Path) -> None:
    result = services.bench_run(project, spec_file)
    cases = {c["id"]: c for c in result["cases"]}
    assert cases["reset"]["search"]["hit"] and cases["reset"]["search"]["first_rank"] == 1
    assert cases["cart"]["search"]["recall"] > 0  # cart.py is found even if no test file matches
    assert cases["impossible"]["search"]["recall"] == 0.0
    assert cases["impossible"]["search"]["first_rank"] is None
    summary = result["summary"]
    assert summary["cases"] == 3 and 0 < summary["search"]["mean_recall_at_k"] < 1
    baselines = cases["reset"]["baselines"]
    assert baselines["whole_repo_tokens"] > 0 and baselines["grep_tokens"] > 0
    assert cases["reset"]["package"]["estimated_tokens"] > 0
    assert any("not assistant task success" in note for note in result["methodology"])


def test_bench_k_override_changes_depth(project: Project, spec_file: Path) -> None:
    shallow = services.bench_run(project, spec_file, k=1, save=False)
    deep = services.bench_run(project, spec_file, k=10, save=False)
    assert shallow["k"] == 1 and deep["k"] == 10
    assert (
        shallow["summary"]["search"]["mean_recall_at_k"]
        <= deep["summary"]["search"]["mean_recall_at_k"]
    )


def test_bench_does_not_skew_operational_metrics(project: Project, spec_file: Path) -> None:
    services.cache_update(project)
    before = len(read_events(project.paths))
    services.bench_run(project, spec_file)
    after = [e["type"] for e in read_events(project.paths)[before:]]
    assert "context_build" not in after and "context_search" not in after


def test_bench_saves_result_only_when_asked(project: Project, spec_file: Path) -> None:
    services.bench_run(project, spec_file, save=False)
    assert not (project.paths.reports_dir / "bench-latest.json").exists()
    services.bench_run(project, spec_file)
    saved = json.loads((project.paths.reports_dir / "bench-latest.json").read_text())
    assert saved["spec"] == "fixture"


def test_bench_accepts_relative_spec_path(project: Project, spec_file: Path) -> None:
    relative = spec_file.relative_to(project.root)
    assert services.bench_run(project, relative, save=False)["summary"]["cases"] == 3


def test_bench_cli_output_and_thresholds(project_dir: Path, spec_file: Path) -> None:
    base = ["--project-dir", str(project_dir)]
    ok = runner.invoke(app, [*base, "bench", "run", str(spec_file), "--cases"])
    assert ok.exit_code == 0, ok.output
    assert "recall@3" in ok.stdout and "MISS" in ok.stdout and "missed:" in ok.stdout
    assert "(estimates)" in ok.stdout
    failing = runner.invoke(app, [*base, "bench", "run", str(spec_file), "--min-recall", "0.99"])
    assert failing.exit_code == 3 and "FAILED" in failing.stdout
    passing = runner.invoke(app, [*base, "bench", "run", str(spec_file), "--min-recall", "0.1"])
    assert passing.exit_code == 0
    as_json = runner.invoke(app, ["--json", *base, "bench", "run", str(spec_file)])
    assert json.loads(as_json.stdout)["failures"] == []


def test_bench_cli_errors(project_dir: Path, tmp_path: Path) -> None:
    base = ["--project-dir", str(project_dir)]
    assert runner.invoke(app, [*base, "bench", "run", str(tmp_path / "nope.yaml")]).exit_code == 2
    bad = tmp_path / "bad.yaml"
    bad.write_text("cases: []")
    assert runner.invoke(app, [*base, "bench", "run", str(bad)]).exit_code == 2


# --- transcripts: parsing ----------------------------------------------------------------------


def line(**fields: Any) -> str:
    return json.dumps(fields)


def assistant(
    message_id: str,
    block: dict[str, Any],
    *,
    usage: tuple[int, int, int, int] = (10, 100, 1000, 50),
    when: str = "2026-01-01T10:00:00Z",
    sidechain: bool = False,
    model: str = "model-a",
) -> str:
    inp, out, read, write = usage
    return line(
        type="assistant",
        sessionId="sess-1",
        timestamp=when,
        isSidechain=sidechain,
        uuid=f"u-{message_id}-{block.get('id', block.get('type'))}",
        message={
            "id": message_id,
            "model": model,
            "content": [block],
            "usage": {
                "input_tokens": inp,
                "output_tokens": out,
                "cache_read_input_tokens": read,
                "cache_creation_input_tokens": write,
            },
        },
    )


def tool(tool_id: str, name: str, **tool_input: Any) -> dict[str, Any]:
    return {"type": "tool_use", "id": tool_id, "name": name, "input": tool_input}


def make_transcript(directory: Path, name: str = "sess-1", extra: list[str] | None = None) -> Path:
    lines = [
        line(type="user", sessionId="sess-1", message={"role": "user", "content": SECRET}),
        line(type="queue-operation", content=SECRET),
        # one API message split over three lines that repeat identical usage
        assistant("m1", {"type": "thinking", "thinking": SECRET}),
        assistant("m1", tool("t1", "Read", file_path=f"/x/{SECRET}")),
        assistant("m1", tool("t2", "Grep", pattern=SECRET)),
        assistant(
            "m2",
            tool("t3", "Bash", command="aiopt context build --task x"),
            usage=(5, 50, 2000, 0),
            when="2026-01-01T10:05:00Z",
            model="model-b",
        ),  # fmt: skip
        assistant(
            "m2",
            tool("t3", "Bash", command="aiopt context build --task x"),
            usage=(5, 50, 2000, 0),
            when="2026-01-01T10:05:00Z",
        ),  # fmt: skip
        assistant(
            "m3",
            tool("t4", "mcp__aiopt__search_context", query="x"),
            usage=(1, 10, 100, 0),
            when="2026-01-01T09:00:00Z",
            sidechain=True,
        ),  # fmt: skip
        assistant("m3", tool("t5", "mcp__other__thing"), usage=(1, 10, 100, 0), sidechain=True),
        assistant(
            "m4", tool("t6", "Bash", command="echo aiopt; grep aiopt README.md"), usage=(0, 0, 0, 0)
        ),  # fmt: skip
        "not json at all",
        line(type="assistant", message="not a dict"),
        *(extra or []),
    ]
    file = directory / f"{name}.jsonl"
    directory.mkdir(parents=True, exist_ok=True)
    file.write_text("\n".join(lines) + "\n")
    return file


def test_parse_counts_each_message_once(tmp_path: Path) -> None:
    session = parse_transcript(make_transcript(tmp_path))
    assert session is not None
    assert session.session_id == "sess-1" and session.source == "claude-code"
    assert session.assistant_messages == 4
    assert session.input_tokens == 10 + 5 + 1 + 0
    assert session.output_tokens == 100 + 50 + 10 + 0
    assert session.cache_read_tokens == 1000 + 2000 + 100
    assert session.cache_write_tokens == 50
    assert session.sidechain_messages == 1
    assert session.models == ["model-a", "model-b"]
    assert session.started_at == "2026-01-01T09:00:00Z"
    assert session.ended_at == "2026-01-01T10:05:00Z"


def test_parse_counts_tool_calls_once_and_detects_aiopt(tmp_path: Path) -> None:
    session = parse_transcript(make_transcript(tmp_path))
    assert session is not None
    assert session.tool_calls == {
        "Read": 1, "Grep": 1, "Bash": 2, "mcp__aiopt__search_context": 1, "mcp__other__thing": 1,
    }  # fmt: skip
    assert session.aiopt_calls == 2  # one Bash aiopt command + one aiopt MCP tool
    assert session.metric("exploration_calls") == 2
    assert session.metric("shell_calls") == 2 and session.metric("mcp_calls") == 2
    assert session.metric("tool_calls") == 6


@pytest.mark.parametrize(
    ("command", "expected"),
    [
        ("aiopt context build --task x", True),
        ("cd /repo && aiopt cache update", True),
        ("/home/me/.local/bin/aiopt task list", True),
        ("FOO=1 BAR=2 aiopt metrics", True),
        ("pytest -q; aiopt report", True),
        ("python3 -m ai_optimizer analyze", True),
        ("echo aiopt", False),
        ("grep aiopt README.md", False),
        ("pytest tests/test_aiopt.py", False),
        ("cat aiopt.log | head", False),
        ("", False),
    ],
)
def test_invokes_aiopt(command: str, expected: bool) -> None:
    assert invokes_aiopt(command) is expected


def test_subagent_transcripts_are_merged_into_the_session(tmp_path: Path) -> None:
    make_transcript(tmp_path, "sess-1")
    sub = tmp_path / "sess-1" / "subagents"
    sub.mkdir(parents=True)
    (sub / "agent-a.jsonl").write_text(
        "\n".join(
            [
                assistant("sa1", tool("s1", "Grep", pattern="x"), usage=(7, 70, 700, 7)),
                assistant("sa1", tool("s2", "Read", file_path="f"), usage=(7, 70, 700, 7)),
                assistant("m1", tool("t1", "Read", file_path="dup")),  # id already seen in main
            ]
        )
        + "\n"
    )
    session = parse_transcript(tmp_path / "sess-1.jsonl")
    assert session is not None
    assert session.session_id == "sess-1"
    assert session.assistant_messages == 5 and session.sidechain_messages == 1 + 1
    assert session.input_tokens == 16 + 7 and session.output_tokens == 160 + 70
    assert session.tool_calls["Grep"] == 2 and session.tool_calls["Read"] == 2


def test_no_conversation_content_is_ever_stored(project: Project, tmp_path: Path) -> None:
    make_transcript(tmp_path / "t")
    import_claude_sessions(project.paths, path=tmp_path / "t", label="x")
    stored = (project.paths.reports_dir / "usage.json").read_text()
    assert SECRET not in stored
    assert "Read" in stored  # tool names and counts are kept


def test_transcript_without_assistant_messages_is_ignored(tmp_path: Path) -> None:
    file = tmp_path / "empty.jsonl"
    file.write_text(line(type="user", message={"content": "hi"}) + "\n")
    assert parse_transcript(file) is None


def test_missing_or_odd_usage_counts_as_zero(tmp_path: Path) -> None:
    file = tmp_path / "odd.jsonl"
    file.write_text(
        line(type="assistant", sessionId="s", message={"id": "a", "usage": {"input_tokens": None,
             "output_tokens": True, "cache_read_input_tokens": "5"}, "content": []}) + "\n")  # fmt: skip
    session = parse_transcript(file)
    assert session is not None
    assert (session.input_tokens, session.output_tokens, session.cache_read_tokens) == (0, 0, 0)


# --- transcripts: locating, importing, labeling -------------------------------------------------


def test_find_transcripts_uses_escaped_project_path(
    project: Project, tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    base = tmp_path / "claude" / "projects"
    escaped = str(project.root).replace("/", "-")
    make_transcript(base / escaped, "a")
    make_transcript(base / escaped, "b")
    make_transcript(base / "-some-other-project", "c")
    monkeypatch.setenv("CLAUDE_CONFIG_DIR", str(tmp_path / "claude"))
    found = find_transcripts(project.root)
    assert [f.stem for f in found] == ["a", "b"]


def test_import_without_transcripts_explains_what_to_do(
    project: Project, tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    monkeypatch.setenv("CLAUDE_CONFIG_DIR", str(tmp_path / "empty"))
    with pytest.raises(ConfigError, match="--path"):
        import_claude_sessions(project.paths)
    with pytest.raises(ConfigError, match="does not exist"):
        import_claude_sessions(project.paths, path=tmp_path / "nope")


def test_import_is_idempotent_and_keeps_labels(project: Project, tmp_path: Path) -> None:
    make_transcript(tmp_path, "one")
    first = import_claude_sessions(project.paths, path=tmp_path, label="baseline")
    assert (first["added"], first["updated"]) == (1, 0)
    again = import_claude_sessions(project.paths, path=tmp_path)
    assert (again["added"], again["updated"]) == (0, 0)
    sessions = usage_store.load_sessions(project.paths)
    assert next(iter(sessions.values())).label == "baseline"
    relabeled = import_claude_sessions(project.paths, path=tmp_path, label="aiopt")
    assert relabeled["added"] == 0
    assert next(iter(usage_store.load_sessions(project.paths).values())).label == "aiopt"


def test_import_since_filters_by_start_time(project: Project, tmp_path: Path) -> None:
    make_transcript(tmp_path, "one")
    assert (
        import_claude_sessions(project.paths, path=tmp_path, since="2026-02-01")[
            "sessions_imported"
        ]
        == 0
    )
    assert (
        import_claude_sessions(project.paths, path=tmp_path, since="2025-12-31")[
            "sessions_imported"
        ]
        == 1
    )


def test_label_by_prefix(project: Project, tmp_path: Path) -> None:
    make_transcript(tmp_path, "one")
    import_claude_sessions(project.paths, path=tmp_path)
    assert usage_store.set_label(project.paths, "sess", "baseline").label == "baseline"
    with pytest.raises(ConfigError, match="No stored session"):
        usage_store.set_label(project.paths, "zzz", "x")


def test_ambiguous_label_prefix_is_rejected(project: Project) -> None:
    usage_store.save_sessions(
        project.paths,
        {
            "abc1": usage_store.SessionUsage("abc1", "t"),
            "abc2": usage_store.SessionUsage("abc2", "t"),
        },
    )
    with pytest.raises(ConfigError, match="ambiguous"):
        usage_store.set_label(project.paths, "abc", "x")


def test_corrupt_usage_file_is_a_clear_error(project: Project) -> None:
    project.paths.reports_dir.mkdir(parents=True)
    (project.paths.reports_dir / "usage.json").write_text("{broken")
    with pytest.raises(ConfigError, match="Cannot read"):
        usage_store.load_sessions(project.paths)


# --- usage report ------------------------------------------------------------------------------


def session(
    sid: str, label: str | None, out: int, tools: dict[str, int], aiopt: int = 0
) -> usage_store.SessionUsage:
    return usage_store.SessionUsage(
        sid,
        "t",
        label=label,
        output_tokens=out,
        input_tokens=out,
        tool_calls=tools,
        aiopt_calls=aiopt,
    )


def test_usage_report_groups_compares_and_warns(project: Project) -> None:
    usage_store.save_sessions(
        project.paths,
        {
            s.session_id: s
            for s in (
                session("b1", "baseline", 1000, {"Read": 10, "Grep": 6, "Edit": 2}),
                session("b2", "baseline", 3000, {"Read": 14, "Grep": 6, "Edit": 2}),
                session("a1", "aiopt", 1000, {"Read": 4, "Edit": 2, "Bash": 2}, aiopt=3),
                session("u1", None, 500, {}),
            )
        },
    )
    report = usage_store.usage_report(project.paths)
    assert report["sessions"] == 4
    assert set(report["groups"]) == {"baseline", "aiopt", "(unlabeled)"}
    base = report["groups"]["baseline"]
    assert base["sessions"] == 2 and base["mean"]["output_tokens"] == 2000.0
    assert base["mean"]["exploration_calls"] == 18.0 and base["median"]["output_tokens"] == 2000.0
    assert {c["group"] for c in report["comparisons"]} == {"aiopt", "(unlabeled)"}
    assert all(c["versus"] == "baseline" for c in report["comparisons"])
    by_group = {c["group"]: c["mean_change_percent"] for c in report["comparisons"]}
    assert by_group["aiopt"]["output_tokens"] == -50.0
    assert by_group["aiopt"]["exploration_calls"] == pytest.approx(-77.8, abs=0.1)
    assert by_group["aiopt"]["aiopt_calls"] is None  # baseline mean is zero: no percentage
    assert len(report["warnings"]) == 3  # every group has fewer than five sessions
    assert any("not statistically meaningful" in w for w in report["warnings"])
    assert report["source"].startswith("provider-reported")


def test_usage_report_without_baseline_has_no_comparisons(project: Project) -> None:
    usage_store.save_sessions(project.paths, {"x": session("x", "aiopt", 1, {})})
    assert usage_store.usage_report(project.paths)["comparisons"] == []
    assert usage_store.usage_report(project.paths, baseline="aiopt")["comparisons"] == []


def test_enough_sessions_clear_the_warning(project: Project) -> None:
    sessions = {f"s{i}": session(f"s{i}", "baseline", 100, {}) for i in range(5)}
    usage_store.save_sessions(project.paths, sessions)
    assert usage_store.usage_report(project.paths)["warnings"] == []


# --- usage CLI ---------------------------------------------------------------------------------


def test_usage_cli_round_trip(project_dir: Path, tmp_path: Path) -> None:
    make_transcript(tmp_path / "t", "one")
    base = ["--project-dir", str(project_dir)]
    imported = runner.invoke(
        app,
        [*base, "usage", "import", "claude", "--path", str(tmp_path / "t"), "--label", "baseline"],
    )
    assert imported.exit_code == 0, imported.output
    assert "Imported 1 session" in imported.stdout and "no message content" in imported.stdout
    listing = runner.invoke(app, [*base, "usage", "list"])
    assert "baseline" in listing.stdout and "aiopt" in listing.stdout
    relabel = runner.invoke(app, [*base, "usage", "label", "sess", "treatment"])
    assert relabel.exit_code == 0 and "treatment" in relabel.stdout
    report = runner.invoke(app, [*base, "usage", "report"])
    assert report.exit_code == 0
    assert "provider-reported" in report.stdout and "Warning" in report.stdout
    payload = json.loads(runner.invoke(app, ["--json", *base, "usage", "report"]).stdout)
    assert "treatment" in payload["groups"]


def test_usage_cli_errors(project_dir: Path, tmp_path: Path) -> None:
    base = ["--project-dir", str(project_dir)]
    assert runner.invoke(app, [*base, "usage", "import", "gemini"]).exit_code == 2
    assert (
        runner.invoke(
            app, [*base, "usage", "import", "claude", "--path", str(tmp_path / "x")]
        ).exit_code
        == 2
    )
    assert runner.invoke(app, [*base, "usage", "label", "nope", "x"]).exit_code == 2
    empty = runner.invoke(app, [*base, "usage", "list"])
    assert empty.exit_code == 0 and "No sessions imported" in empty.stdout


# --- report ------------------------------------------------------------------------------------


def test_report_labels_every_source(project: Project, spec_file: Path, tmp_path: Path) -> None:
    services.cache_update(project)
    services.search_context(project, "cart")
    services.bench_run(project, spec_file)
    make_transcript(tmp_path / "t")
    import_claude_sessions(project.paths, path=tmp_path / "t", label="baseline")
    report = services.generate_report(project)
    assert "estimate" in report["local_operations"]["source"]
    assert "estimate" in report["retrieval_benchmark"]["source"]
    assert report["provider_usage"]["source"].startswith("provider-reported")
    text = render_markdown(report)
    for heading in ("## Local operations", "## Retrieval benchmark", "## Provider-reported usage"):
        assert heading in text
    assert text.count("_Source:") == 3
    assert "estimate" in text and "provider-reported" in text
    assert "does not measure assistant behavior" in text
    assert SECRET not in text


def test_report_without_optional_sections(project: Project) -> None:
    text = render_markdown(services.generate_report(project))
    assert "No benchmark has been run" in text and "No sessions imported" in text
    assert text.count("_Source:") == 1


def test_report_survives_a_corrupt_benchmark_file(project: Project) -> None:
    project.paths.reports_dir.mkdir(parents=True)
    (project.paths.reports_dir / "bench-latest.json").write_text("{nope")
    assert services.generate_report(project)["retrieval_benchmark"] is None


def test_report_values_match_their_sources(project: Project, spec_file: Path) -> None:
    result = services.bench_run(project, spec_file)
    report = services.generate_report(project)
    assert report["retrieval_benchmark"]["summary"] == result["summary"]
    metrics = services.metrics_report(project)
    assert report["local_operations"]["cache"] == metrics["cache"]
    assert build_report(metrics, None, None)["provider_usage"] is None


def test_report_cli(project_dir: Path, spec_file: Path) -> None:
    base = ["--project-dir", str(project_dir)]
    runner.invoke(app, [*base, "bench", "run", str(spec_file)])
    md = runner.invoke(app, [*base, "report"])
    assert md.exit_code == 0 and md.stdout.startswith("# AI Optimizer report")
    js = runner.invoke(app, [*base, "report", "--format", "json"])
    assert json.loads(js.stdout)["retrieval_benchmark"]["spec"] == "fixture"
    out = runner.invoke(app, [*base, "report", "--output", "reports/summary.md"])
    assert out.exit_code == 0 and (project_dir / "reports/summary.md").read_text().startswith(
        "# AI"
    )
    assert runner.invoke(app, [*base, "report", "--output", "../escape.md"]).exit_code == 4
    assert runner.invoke(app, [*base, "report", "--format", "xml"]).exit_code == 2
