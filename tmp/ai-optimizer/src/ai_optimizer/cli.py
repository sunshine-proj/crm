"""Typer command-line interface. Commands only parse arguments and render results."""

from __future__ import annotations

import functools
import json
import sys
from collections.abc import Callable
from dataclasses import dataclass
from pathlib import Path
from typing import Annotated, Any, cast

import typer

from ai_optimizer import __version__, services
from ai_optimizer.core.errors import AIOptError, ConfigError, TrustError
from ai_optimizer.core.log import configure_logging, get_logger
from ai_optimizer.core.project import Project, find_project_root, load_project
from ai_optimizer.integrations import adapters as integrations
from ai_optimizer.integrations import claude_usage, hooks
from ai_optimizer.metrics.report import render_markdown as report_markdown

log = get_logger("cli")

app = typer.Typer(
    name="aiopt",
    help="AI Optimizer: local project intelligence, context retrieval and safe task execution.",
    no_args_is_help=True,
    add_completion=False,
)
config_app = typer.Typer(help="Configuration commands.", no_args_is_help=True)
project_app = typer.Typer(help="Project metadata commands.", no_args_is_help=True)
cache_app = typer.Typer(help="Index cache commands.", no_args_is_help=True)
context_app = typer.Typer(help="Context search and package generation.", no_args_is_help=True)
profile_app = typer.Typer(help="Context profile commands.", no_args_is_help=True)
task_app = typer.Typer(help="Task registry and execution.", no_args_is_help=True)
integrations_app = typer.Typer(help="Install agent integrations.", no_args_is_help=True)
mcp_app = typer.Typer(help="MCP server.", no_args_is_help=True)
hook_app = typer.Typer(help="Assistant hook entry points.", no_args_is_help=True, hidden=True)
bench_app = typer.Typer(help="Retrieval benchmark.", no_args_is_help=True)
usage_app = typer.Typer(
    help="Provider-reported usage from assistant sessions.", no_args_is_help=True
)
app.add_typer(config_app, name="config")
app.add_typer(project_app, name="project")
app.add_typer(cache_app, name="cache")
app.add_typer(context_app, name="context")
app.add_typer(profile_app, name="profile")
app.add_typer(task_app, name="task")
app.add_typer(integrations_app, name="integrations")
app.add_typer(mcp_app, name="mcp")
app.add_typer(bench_app, name="bench")
app.add_typer(usage_app, name="usage")
app.add_typer(hook_app, name="hook", hidden=True)


@dataclass(slots=True)
class State:
    """Global options shared by all commands."""

    project_dir: Path | None = None
    json_output: bool = False
    quiet: bool = False


def _state(ctx: typer.Context) -> State:
    return ctx.obj if isinstance(ctx.obj, State) else State()


def _project(ctx: typer.Context) -> Project:
    return load_project(_state(ctx).project_dir)


def _emit(
    ctx: typer.Context, payload: dict[str, Any], text: str, *, essential: bool = False
) -> None:
    state = _state(ctx)
    if state.json_output:
        typer.echo(json.dumps(payload, indent=2, sort_keys=True))
    elif essential or not state.quiet:
        typer.echo(text)


def _fail(ctx: typer.Context, error_type: str, message: str, exit_code: int) -> typer.Exit:
    if _state(ctx).json_output:
        payload = {"error": {"type": error_type, "message": message, "exit_code": exit_code}}
        typer.echo(json.dumps(payload, indent=2))
    else:
        typer.echo(f"Error: {message}", err=True)
    return typer.Exit(exit_code)


def guarded[**P](func: Callable[P, None]) -> Callable[P, None]:
    """Map expected errors to messages and exit codes; hide tracebacks from users.

    Every guarded command must take ``ctx: typer.Context`` as its first parameter.
    """

    @functools.wraps(func)
    def wrapper(*args: P.args, **kwargs: P.kwargs) -> None:
        ctx = cast(typer.Context, kwargs["ctx"])
        try:
            func(*args, **kwargs)
        except (typer.Exit, typer.Abort, typer.TyperException):
            raise
        except AIOptError as exc:
            raise _fail(ctx, type(exc).__name__, str(exc), exc.exit_code) from exc
        except Exception as exc:
            log.debug("Unexpected error", exc_info=True)
            raise _fail(ctx, "UnexpectedError", f"Unexpected error: {exc}", 1) from exc

    return wrapper


def _version_callback(value: bool) -> None:
    if value:
        typer.echo(f"aiopt {__version__}")
        raise typer.Exit()


@app.callback()
def root_callback(
    ctx: typer.Context,
    project_dir: Annotated[
        Path | None, typer.Option("--project-dir", help="Project directory (default: auto-detect).")
    ] = None,
    json_output: Annotated[bool, typer.Option("--json", help="Machine-readable output.")] = False,
    verbose: Annotated[int, typer.Option("--verbose", "-v", count=True, help="More logging.")] = 0,
    quiet: Annotated[bool, typer.Option("--quiet", "-q", help="Only essential output.")] = False,
    version: Annotated[
        bool, typer.Option("--version", callback=_version_callback, is_eager=True)
    ] = False,
) -> None:
    """Global options."""
    configure_logging(verbose, quiet)
    ctx.obj = State(project_dir=project_dir, json_output=json_output, quiet=quiet)


# --- init / trust / config ---------------------------------------------------------------


@app.command("init")
@guarded
def init_command(
    ctx: typer.Context,
    force: Annotated[bool, typer.Option(help="Overwrite config.yaml if it exists.")] = False,
) -> None:
    """Create .ai-optimizer/ with default configuration and tasks."""
    state = _state(ctx)
    root = state.project_dir.resolve() if state.project_dir else find_project_root(Path.cwd())
    result = services.init_project(root, force=force)
    lines = [f"Initialized AI Optimizer in {result['root']}"]
    lines += [f"  created  {name}" for name in result["created"]]
    lines += [f"  existing {name}" for name in result["existing"]]
    lines += [f"Note: {note}" for note in result["notes"]]
    _emit(ctx, result, "\n".join(lines))


def _stdin_is_tty() -> bool:
    return sys.stdin.isatty()


@app.command("trust")
@guarded
def trust_command(
    ctx: typer.Context,
    revoke: Annotated[
        bool, typer.Option("--revoke", help="Remove trust for this project.")
    ] = False,
) -> None:
    """Review and trust this project's tasks so they may be executed."""
    project = _project(ctx)
    if revoke:
        result = services.revoke_trust(project)
        _emit(ctx, result, "Trust revoked." if result["revoked"] else "Project was not trusted.")
        return
    if not _stdin_is_tty():
        raise TrustError(
            "`aiopt trust` needs an interactive terminal so that a person reviews and confirms. "
            "Run it yourself in a terminal (for example the VS Code integrated terminal)."
        )
    summary = services.trust_summary(project)
    typer.echo(f"Project: {summary['root']}", err=True)
    if not summary["tasks"]:
        typer.echo("No tasks defined.", err=True)
    for task in summary["tasks"]:
        flag = " [destructive]" if task["destructive"] else ""
        typer.echo(f"Task '{task['name']}'{flag} will run:", err=True)
        for command in task["commands"]:
            typer.echo(f"  $ {command}", err=True)
    if not typer.confirm("Trust this project to run these tasks?", err=True):
        raise TrustError("Trust was not granted.")
    result = services.trust_project(project)
    _emit(ctx, result, f"Project trusted (fingerprint {result['fingerprint'][:12]}).")


@config_app.command("validate")
@guarded
def config_validate(ctx: typer.Context) -> None:
    """Validate config.yaml, task definitions and profiles."""
    result = services.validate_config(_project(ctx))
    text = (
        f"Configuration is valid. Tasks: {', '.join(result['tasks']) or 'none'}. "
        f"Profiles: {', '.join(result['profiles']) or 'none (built-ins only)'}."
    )
    _emit(ctx, result, text)


# --- analysis ----------------------------------------------------------------------------


def _describe_project(info: dict[str, Any]) -> str:
    lines = [f"Project: {info['name']}"]
    lines.append(f"Languages: {', '.join(info['languages']) or 'none detected'}")
    lines.append(f"Package managers: {', '.join(info['package_managers']) or 'none detected'}")
    if info["frameworks"]:
        lines.append("Frameworks: " + ", ".join(f["name"] for f in info["frameworks"]))
    if info["dev_commands"]:
        lines.append("Development commands:")
        lines += [f"  {c['command']}  ({c['kind']})" for c in info["dev_commands"]]
    lines += [f"Warning: {w}" for w in info["warnings"]]
    return "\n".join(lines)


@app.command("analyze")
@guarded
def analyze_command(ctx: typer.Context) -> None:
    """Analyze the project and write metadata to .ai-optimizer/cache/."""
    result = services.analyze_project(_project(ctx))
    text = _describe_project(result["project"]) + "\nWrote: " + ", ".join(result["written"])
    _emit(ctx, result, text)


@project_app.command("info")
@guarded
def project_info_command(ctx: typer.Context) -> None:
    """Show detected project information (analyzes first if needed)."""
    info = services.project_info(_project(ctx))
    _emit(ctx, info, _describe_project(info))


# --- cache -------------------------------------------------------------------------------


@cache_app.command("update")
@guarded
def cache_update_command(
    ctx: typer.Context,
    full: Annotated[bool, typer.Option(help="Rehash every file, ignoring metadata.")] = False,
) -> None:
    """Incrementally update the file index."""
    result = services.cache_update(_project(ctx), full=full)
    lines = [
        f"Indexed files: {result['indexed_total']} "
        f"(added {len(result['added'])}, changed {len(result['changed'])}, "
        f"removed {len(result['removed'])}, renamed {len(result['renamed'])})",
        f"Rehashed {result['rehashed']} files; skipped hashing {result['hash_skipped']}.",
    ]
    if result["rebuilt"]:
        lines.append("The cache was corrupt or incompatible and has been rebuilt.")
    _emit(ctx, result, "\n".join(lines))


@cache_app.command("status")
@guarded
def cache_status_command(ctx: typer.Context) -> None:
    """Show cache health and pending changes."""
    status = services.cache_status(_project(ctx))
    if not status["exists"]:
        text = "Cache has not been built. Run `aiopt cache update`."
    elif not status["healthy"]:
        text = f"Cache is unhealthy: {status['error']}"
    else:
        pending = status["pending"]
        text = (
            f"Indexed files: {status['indexed_files']} (binary skipped: {status['binary_files']})\n"
            f"Last update: {status['last_update']}\n"
            f"Pending changes: {pending['added']} added, {pending['changed']} changed, "
            f"{pending['removed']} removed -> {'STALE' if status['stale'] else 'up to date'}"
        )
    _emit(ctx, status, text)


@cache_app.command("clear")
@guarded
def cache_clear_command(ctx: typer.Context) -> None:
    """Delete the generated cache (index and metadata)."""
    result = services.cache_clear(_project(ctx))
    _emit(ctx, result, "Cache cleared." if result["cleared"] else "Nothing to clear.")


# --- context -----------------------------------------------------------------------------


@context_app.command("search")
@guarded
def context_search(
    ctx: typer.Context,
    query: Annotated[str, typer.Argument(help="Search text.")],
    limit: Annotated[int | None, typer.Option(help="Maximum results (1-100).")] = None,
) -> None:
    """Search the indexed project and return ranked results."""
    result = services.search_context(_project(ctx), query, limit)
    lines = [f"Terms: {', '.join(result['terms'])}"]
    for number, hit in enumerate(result["results"], start=1):
        lines.append(f"{number}. {hit['path']}  [{hit['kind']}, score {hit['score']}]")
        if hit["excerpt"]:
            lines.append(f"     {hit['excerpt']}")
    if not result["results"]:
        lines.append("No results.")
    if result["omitted"]:
        lines.append(f"({result['omitted']} more results omitted by limits)")
    lines += [f"Warning: {w}" for w in result["warnings"]]
    _emit(ctx, result, "\n".join(lines), essential=True)


@context_app.command("build")
@guarded
def context_build(
    ctx: typer.Context,
    profile: Annotated[str | None, typer.Option(help="Context profile name.")] = None,
    task: Annotated[str | None, typer.Option(help="Task description.")] = None,
    max_tokens: Annotated[int | None, typer.Option(help="Estimated token budget.")] = None,
    fmt: Annotated[str, typer.Option("--format", help="md or json.")] = "md",
    output: Annotated[Path | None, typer.Option(help="Write to a file inside the project.")] = None,
) -> None:
    """Build a compact context package for a task and/or profile."""
    result = services.build_context(
        _project(ctx),
        task=task,
        profile_name=profile,
        max_tokens=max_tokens,
        fmt=fmt,
        output=output,
    )
    payload = {**result["package"], "output_file": result["output_file"]}
    if result["output_file"]:
        budget = result["package"]["budget"]
        text = f"Wrote {result['output_file']} (~{budget['estimated_tokens']} estimated tokens)."
    else:
        text = result["text"].rstrip("\n")
    _emit(ctx, payload, text, essential=True)


# --- profiles ----------------------------------------------------------------------------


@profile_app.command("list")
@guarded
def profile_list(ctx: typer.Context) -> None:
    """List built-in and project context profiles."""
    result = services.list_profiles(_project(ctx))
    lines = [f"{p['name']:<14} {p['source']:<8} {p['description']}" for p in result["profiles"]]
    _emit(ctx, result, "\n".join(lines))


@profile_app.command("show")
@guarded
def profile_show(ctx: typer.Context, name: Annotated[str, typer.Argument()]) -> None:
    """Show one profile's settings."""
    result = services.show_profile(_project(ctx), name)
    _emit(ctx, result, json.dumps(result, indent=2, sort_keys=True))


# --- tasks -------------------------------------------------------------------------------


@task_app.command("list")
@guarded
def task_list(ctx: typer.Context) -> None:
    """List defined tasks."""
    result = services.list_tasks(_project(ctx))
    lines = [
        f"{t['name']:<14} {t['steps']} step(s)  {t['description']}"
        + ("  [destructive]" if t["destructive"] else "")
        for t in result["tasks"]
    ] or ["No tasks defined. Run `aiopt init` or add files to .ai-optimizer/tasks/."]
    lines.append("Trusted: " + ("yes" if result["trusted"] else "no (run `aiopt trust`)"))
    _emit(ctx, result, "\n".join(lines))


@task_app.command("show")
@guarded
def task_show(ctx: typer.Context, name: Annotated[str, typer.Argument()]) -> None:
    """Show one task definition."""
    result = services.show_task(_project(ctx), name)
    _emit(ctx, result, json.dumps(result, indent=2, sort_keys=True))


@task_app.command("run")
@guarded
def task_run(
    ctx: typer.Context,
    name: Annotated[str, typer.Argument(help="Task name.")],
    allow_destructive: Annotated[
        bool, typer.Option("--allow-destructive", help="Permit destructive operations.")
    ] = False,
) -> None:
    """Run a task (requires `aiopt trust`)."""
    report = services.run_task(_project(ctx), name, allow_destructive=allow_destructive)
    lines = [
        f"Task '{report['task']}': {report['status'].upper()} in {report['duration_seconds']}s"
    ]
    for step in report["steps"]:
        code = "" if step["exit_code"] is None else f" (exit {step['exit_code']})"
        lines.append(f"  [{step['status']}] {step['name']}{code}")
        if step["status"] in {"failed", "timeout", "error"}:
            tail = (step["stderr"] or step["stdout"]).strip().splitlines()[-10:]
            lines += [f"      {line}" for line in tail]
        if step["skip_reason"]:
            lines.append(f"      {step['skip_reason']}")
    lines.append("Report: " + ", ".join(report["report_files"]))
    _emit(ctx, report, "\n".join(lines), essential=True)
    if report["status"] != "passed":
        raise typer.Exit(3)


# --- integrations ------------------------------------------------------------------------


def _render_install(report: Any, verb: str) -> str:
    prefix = "[dry run] " if report.dry_run else ""
    lines = [f"{prefix}{verb} {report.provider}:"]
    for change in report.changes:
        detail = f" ({change.detail})" if change.detail else ""
        lines.append(f"  {change.status:<9} {change.path}{detail}")
    lines += [f"Note: {note}" for note in report.notes]
    return "\n".join(lines)


@integrations_app.command("list")
@guarded
def integrations_list(ctx: typer.Context) -> None:
    """Show available integrations and which are installed."""
    state = _state(ctx)
    root = state.project_dir.resolve() if state.project_dir else find_project_root(Path.cwd())
    rows = integrations.status(root)
    lines = [
        f"{row['provider']:<9} {'installed' if row['installed'] else 'not installed':<14} "
        f"{row['description']}"
        for row in rows
    ]
    _emit(ctx, {"integrations": rows}, "\n".join(lines))


@integrations_app.command("install")
@guarded
def integrations_install(
    ctx: typer.Context,
    provider: Annotated[str, typer.Argument(help="claude, copilot or codex.")],
    command: Annotated[
        str, typer.Option(help="Executable the editor should launch (use an absolute path).")
    ] = "aiopt",
    mcp: Annotated[bool, typer.Option("--mcp/--no-mcp", help="Register the MCP server.")] = True,
    hooks: Annotated[
        bool, typer.Option("--with-hooks", help="Claude Code: add a SessionStart status hook.")
    ] = False,
    permissions: Annotated[
        bool,
        typer.Option("--permissions/--no-permissions", help="Claude Code: add permission rules."),
    ] = True,
    force: Annotated[
        bool, typer.Option(help="Replace files and servers not managed by aiopt.")
    ] = False,
    dry_run: Annotated[
        bool, typer.Option("--dry-run", help="Show changes without writing.")
    ] = False,
) -> None:
    """Install skills, instructions and MCP configuration for an assistant."""
    state = _state(ctx)
    root = state.project_dir.resolve() if state.project_dir else find_project_root(Path.cwd())
    options = integrations.InstallOptions(
        command=command, mcp=mcp, hooks=hooks, permissions=permissions, force=force, dry_run=dry_run
    )
    report = integrations.install(root, provider, options)
    _emit(ctx, report.to_dict(), _render_install(report, "Installed"))


@integrations_app.command("uninstall")
@guarded
def integrations_uninstall(
    ctx: typer.Context,
    provider: Annotated[str, typer.Argument(help="claude, copilot or codex.")],
    dry_run: Annotated[
        bool, typer.Option("--dry-run", help="Show changes without writing.")
    ] = False,
) -> None:
    """Remove what `install` added. Files you changed yourself are left in place."""
    state = _state(ctx)
    root = state.project_dir.resolve() if state.project_dir else find_project_root(Path.cwd())
    report = integrations.uninstall(root, provider, dry_run=dry_run)
    _emit(ctx, report.to_dict(), _render_install(report, "Uninstalled"))


@mcp_app.command("serve")
@guarded
def mcp_serve(ctx: typer.Context) -> None:
    """Run the MCP server over stdio. Normally started by your editor, not by hand."""
    from ai_optimizer.integrations import mcp_server

    mcp_server.serve(_state(ctx).project_dir)


@hook_app.command("session-start")
def hook_session_start(ctx: typer.Context) -> None:
    """Print a short session status for Claude Code (empty when not set up)."""
    summary = hooks.session_start_summary(_state(ctx).project_dir)
    if summary:
        typer.echo(summary)


# --- benchmark, usage and report ----------------------------------------------------------


def _pct(value: float | None) -> str:
    return "n/a" if value is None else f"{value * 100:.0f}%"


@bench_app.command("run")
@guarded
def bench_run(
    ctx: typer.Context,
    spec: Annotated[Path, typer.Argument(help="Benchmark YAML file.")],
    k: Annotated[int | None, typer.Option(help="Override the depth k from the spec.")] = None,
    min_recall: Annotated[
        float | None,
        typer.Option(help="Fail (exit 3) if mean search recall@k is below this (0-1)."),
    ] = None,
    min_package_recall: Annotated[
        float | None, typer.Option(help="Fail (exit 3) if mean package recall is below this (0-1).")
    ] = None,
    save: Annotated[
        bool, typer.Option("--save/--no-save", help="Keep the result for `aiopt report`.")
    ] = True,
    verbose_cases: Annotated[bool, typer.Option("--cases", help="List every case.")] = False,
) -> None:
    """Score retrieval against labeled cases and compare context sizes with baselines."""
    result = services.bench_run(_project(ctx), spec, k=k, save=save)
    summary = result["summary"]
    s, p, r = summary["search"], summary["package"], summary["size_ratios"]
    lines = [
        f"Benchmark '{result['spec']}': {summary['cases']} cases over "
        f"{result['indexed_files']} files, k={result['k']}",
        f"Search:  recall@{result['k']} {_pct(s['mean_recall_at_k'])}, all expected found "
        f"{_pct(s['full_hit_rate'])}, MRR {s['mrr']}",
        f"Package: recall {_pct(p['mean_recall'])} with {p['mean_items']:.1f} items on average "
        f"(search at that depth: {_pct(summary['search_at_package_depth']['mean_recall'])}), "
        f"~{p['mean_estimated_tokens']:.0f} estimated tokens",
        f"Package size vs whole repo {_pct(r['package_vs_whole_repo'])}, vs files containing a "
        f"query term {_pct(r['package_vs_grep_hits'])}, vs top-{result['k']} files "
        f"{_pct(r['package_vs_top_k_files'])}  (estimates)",
    ]
    if verbose_cases:
        for case in result["cases"]:
            mark = "ok " if case["search"]["hit"] else "MISS"
            rank = case["search"]["first_rank"] or "-"
            lines.append(f"  [{mark}] {case['id']:<28} first match at rank {rank}; package recall "
                         f"{_pct(case['package']['recall'])}")  # fmt: skip
            for glob in case["search"]["missed"]:
                lines.append(f"         missed: {glob}")
    failures = []
    if min_recall is not None and (s["mean_recall_at_k"] or 0) < min_recall:
        failures.append(f"search recall {_pct(s['mean_recall_at_k'])} is below {_pct(min_recall)}")
    if min_package_recall is not None and (p["mean_recall"] or 0) < min_package_recall:
        failures.append(
            f"package recall {_pct(p['mean_recall'])} is below {_pct(min_package_recall)}"
        )
    lines += [f"FAILED: {failure}" for failure in failures]
    _emit(ctx, {**result, "failures": failures}, "\n".join(lines), essential=True)
    if failures:
        raise typer.Exit(3)


@usage_app.command("import")
@guarded
def usage_import(
    ctx: typer.Context,
    provider: Annotated[str, typer.Argument(help="Session source. Currently: claude.")],
    path: Annotated[Path | None, typer.Option(help="Transcript file or directory.")] = None,
    label: Annotated[str | None, typer.Option(help="Group name, e.g. baseline or aiopt.")] = None,
    since: Annotated[
        str | None, typer.Option(help="Only sessions starting at/after this ISO date.")
    ] = None,
) -> None:
    """Import token and tool-call counts from an assistant's session logs (no content stored)."""
    if provider != "claude":
        raise ConfigError(f"Unsupported provider '{provider}'. Supported: claude")
    project = _project(ctx)
    result = claude_usage.import_claude_sessions(project.paths, path=path, label=label, since=since)
    text = (
        f"Imported {result['sessions_imported']} session(s) from {result['files_found']} file(s): "
        f"{result['added']} new, {result['updated']} updated"
        + (f", labeled '{label}'" if label else "")
        + f".\n{result['privacy']}"
    )
    _emit(ctx, result, text)


@usage_app.command("list")
@guarded
def usage_list_command(ctx: typer.Context) -> None:
    """List imported sessions."""
    result = services.usage_list(_project(ctx))
    lines = [
        f"{s['session_id'][:8]}  {s['label'] or '(unlabeled)':<12} {s['started_at'] or '?':<26} "
        f"out {s['output_tokens']:>7}  tools {sum(s['tool_calls'].values()):>4}  "
        f"aiopt {s['aiopt_calls']:>3}"
        for s in result["sessions"]
    ] or ["No sessions imported. Use `aiopt usage import claude`."]
    _emit(ctx, result, "\n".join(lines))


@usage_app.command("label")
@guarded
def usage_label_command(
    ctx: typer.Context,
    session: Annotated[str, typer.Argument(help="Session id or unique prefix.")],
    label: Annotated[str, typer.Argument(help="Group name, e.g. baseline or aiopt.")],
) -> None:
    """Assign an imported session to a comparison group."""
    result = services.usage_label(_project(ctx), session, label)
    _emit(ctx, result, f"Session {result['session_id'][:8]} labeled '{label}'.")


@usage_app.command("report")
@guarded
def usage_report_command(
    ctx: typer.Context,
    baseline: Annotated[
        str, typer.Option(help="Group to compare the others against.")
    ] = "baseline",
) -> None:
    """Compare provider-reported usage between labeled groups."""
    report = services.usage_report(_project(ctx), baseline)
    lines = [f"Source: {report['source']}"]
    for label, group in report["groups"].items():
        m = group["mean"]
        lines.append(
            f"{label:<12} n={group['sessions']:<3} mean out {m['output_tokens']:.0f}  "
            f"in {m['input_tokens']:.0f}  "
            f"cache-read {m['cache_read_tokens']:.0f}  tools {m['tool_calls']:.1f}  "
            f"exploration {m['exploration_calls']:.1f}  aiopt {m['aiopt_calls']:.1f}"
        )
    for comparison in report["comparisons"]:
        c = comparison["mean_change_percent"]
        lines.append(
            f"{comparison['group']} vs {comparison['versus']}: "
            f"output tokens {c['output_tokens']}%, "
            f"tool calls {c['tool_calls']}%, exploration calls {c['exploration_calls']}%"
        )
    lines += [f"Warning: {w}" for w in report["warnings"]] + [f"Note: {n}" for n in report["notes"]]
    _emit(ctx, report, "\n".join(lines))


@app.command("report")
@guarded
def report_command(
    ctx: typer.Context,
    fmt: Annotated[str, typer.Option("--format", help="md or json.")] = "md",
    output: Annotated[Path | None, typer.Option(help="Write to a file inside the project.")] = None,
) -> None:
    """Optimization report combining local metrics, the latest benchmark and imported usage."""
    if fmt not in {"md", "json"}:
        raise ConfigError("Format must be 'md' or 'json'.")
    project = _project(ctx)
    report = services.generate_report(project)
    text = (
        json.dumps(report, indent=2, sort_keys=True) if fmt == "json" else report_markdown(report)
    )
    if output is not None:
        target = services.resolve_output(project, output)
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(text + "\n", encoding="utf-8")
        _emit(ctx, {"output_file": str(target)}, f"Wrote {target}.")
    else:
        _emit(ctx, report, text, essential=True)


# --- metrics -----------------------------------------------------------------------------


@app.command("metrics")
@guarded
def metrics_command(ctx: typer.Context) -> None:
    """Show local operation statistics (token figures are estimates)."""
    report = services.metrics_report(_project(ctx))
    cache, retrieval, tasks = report["cache"], report["retrieval"], report["tasks"]
    lines = [
        f"Events recorded: {report['events_recorded']}",
        f"Token figures: {report['token_source']} ({report['estimation_method']})",
        f"Cache: {cache['updates']} updates, {cache['indexed_files']} indexed files, "
        f"{cache['files_rehashed']} rehashed, {cache['files_hash_skipped']} skipped "
        f"(skip rate {_num(cache['hash_skip_rate'])})",
        f"Retrieval: {retrieval['searches']} searches, {retrieval['packages_built']} packages, "
        f"avg {_num(retrieval['avg_package_bytes'])} bytes / "
        f"~{_num(retrieval['avg_package_estimated_tokens'])} estimated tokens",
        f"Tasks: {tasks['runs']} runs ({tasks['passed']} passed, {tasks['failed']} failed), "
        f"avg {_num(tasks['avg_duration_seconds'], 's')}",
    ]
    _emit(ctx, report, "\n".join(lines))


def _num(value: float | None, unit: str = "") -> str:
    return "n/a" if value is None else f"{value}{unit}"


def main() -> None:
    """Console-script entry point."""
    app()
