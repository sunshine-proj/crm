"""Task definition, permission, trust and execution tests (including security)."""

from __future__ import annotations

import os
import sys
import time
from collections.abc import Callable
from pathlib import Path

import pytest

from ai_optimizer import services
from ai_optimizer.core.config import ProjectConfig
from ai_optimizer.core.errors import ConfigError, PermissionDeniedError, TrustError
from ai_optimizer.core.project import Project
from ai_optimizer.tasks import trust as trust_store
from ai_optimizer.tasks.models import TaskDefinition, get_task, load_tasks, parse_task
from ai_optimizer.tasks.permissions import (
    CommandPolicy,
    is_destructive_command,
    load_policy,
)
from tests.conftest import PYTHON_NAME

Writer = Callable[[Path, str, str], Path]

# --- task definitions --------------------------------------------------------------------


def task_yaml(*steps: str, extra: str = "") -> str:
    return "name: demo\n" + extra + "steps:\n" + "".join(steps)


def step(name: str, command: str, extra: str = "") -> str:
    return f"  - name: {name}\n    command: {command}\n" + "".join(
        f"    {line}\n" for line in extra.splitlines()
    )


def test_valid_task_parses_with_defaults() -> None:
    task = parse_task(task_yaml(step("a", "[pytest, -q]")), "t.yaml", "demo")
    assert task.steps[0].on_failure == "stop"
    assert task.destructive is False
    assert task.steps[0].timeout_seconds is None


def test_name_defaults_to_filename() -> None:
    task = parse_task("steps:\n  - {name: a, command: [pytest]}\n", "t.yaml", "fromfile")
    assert task.name == "fromfile"


@pytest.mark.parametrize(
    "text",
    [
        "name: d\nsteps: []\n",
        "name: d\n",
        "name: d\nsteps:\n  - {name: a, command: []}\n",
        "name: d\nsteps:\n  - {name: a, command: ['']}\n",
        "name: d\nsteps:\n  - {name: a, command: [1, 2]}\n",
        "name: d\nsteps:\n  - {name: a, command: pytest}\n",
        "name: d\nsteps:\n  - {name: A, command: [x]}\n",
        "name: d\nsteps:\n  - {name: a, command: [x], timeout_seconds: 0}\n",
        "name: d\nsteps:\n  - {name: a, command: [x], timeout_seconds: 99999}\n",
        "name: d\nsteps:\n  - {name: a, command: [x], on_failure: explode}\n",
        "name: d\nsteps:\n  - {name: a, command: [x]}\n  - {name: a, command: [y]}\n",
        "name: d\nsteps:\n  - {name: a, command: [x], depends_on: [ghost]}\n",
        "name: d\nsteps:\n  - {name: a, command: [x], depends_on: [b]}\n  - {name: b, command: [y], depends_on: [a]}\n",
        "name: ../evil\nsteps:\n  - {name: a, command: [x]}\n",
        "name: d\nextra: 1\nsteps:\n  - {name: a, command: [x]}\n",
    ],
)
def test_invalid_tasks_are_rejected(text: str) -> None:
    with pytest.raises(ConfigError):
        parse_task(text, "t.yaml", "d")


def test_steps_are_ordered_topologically_and_stably() -> None:
    task = TaskDefinition.model_validate(
        {
            "name": "d",
            "steps": [
                {"name": "test", "command": ["x"], "depends_on": ["build"]},
                {"name": "lint", "command": ["x"]},
                {"name": "build", "command": ["x"], "depends_on": ["lint"]},
            ],
        }
    )
    assert [s.name for s in task.ordered_steps()] == ["lint", "build", "test"]


def test_registry_rejects_duplicates_and_reports_unknown(project: Project, write: Writer) -> None:
    write(
        project.root,
        ".ai-optimizer/tasks/a.yaml",
        "name: same\nsteps:\n  - {name: s, command: [x]}\n",
    )
    write(
        project.root,
        ".ai-optimizer/tasks/b.yaml",
        "name: same\nsteps:\n  - {name: s, command: [x]}\n",
    )
    with pytest.raises(ConfigError, match="Duplicate"):
        load_tasks(project.paths)
    (project.root / ".ai-optimizer/tasks/b.yaml").unlink()
    with pytest.raises(ConfigError, match="Available tasks: same"):
        get_task(project.paths, "ghost")


# --- permission policy (security) --------------------------------------------------------


def policy(**kwargs: object) -> CommandPolicy:
    return load_policy(**kwargs)  # type: ignore[arg-type]


@pytest.mark.parametrize(
    "name", ["sh", "bash", "zsh", "cmd", "powershell", "env", "sudo", "npx", "xargs"]
)
def test_shells_and_runners_are_denied_by_default(name: str) -> None:
    with pytest.raises(PermissionDeniedError, match="denied by default"):
        policy().check([name, "-c", "echo hi"])


def test_shells_stay_denied_unless_user_names_them(isolated_user_dirs: Path) -> None:
    config = isolated_user_dirs / "config"
    config.mkdir(parents=True)
    (config / "allowlist.yaml").write_text("commands:\n  - name: bash\n")
    assert policy().check(["bash", "script.sh"]).endswith("bash")


@pytest.mark.parametrize(
    "argv",
    [
        ["python", "-c", "print(1)"],
        ["python3", "-c", "import os"],
        ["node", "-e", "1"],
        ["python3.12", "-c", "1"],
    ],
)
def test_inline_code_execution_is_denied(argv: list[str], isolated_user_dirs: Path) -> None:
    config = isolated_user_dirs / "config"
    config.mkdir(parents=True)
    (config / "allowlist.yaml").write_text(
        "commands:\n"
        + "".join(f"  - name: {n}\n" for n in ("python", "python3", "python3.12", "node"))
    )
    with pytest.raises(PermissionDeniedError, match="Inline code"):
        policy().check(argv)


def test_unlisted_commands_are_denied() -> None:
    with pytest.raises(PermissionDeniedError, match="not in the allowlist"):
        policy().check(["curl", "https://example.com"])


@pytest.mark.parametrize(
    "name", ["./pytest", "/usr/bin/pytest", "../pytest", ".\\pytest", "bin/pytest"]
)
def test_path_based_executables_are_denied(name: str) -> None:
    with pytest.raises(PermissionDeniedError, match="bare executable"):
        policy().check([name])


def test_argument_prefix_is_enforced() -> None:
    p = policy()
    assert p.check(["python", "-m", "pytest", "-q"])
    with pytest.raises(PermissionDeniedError, match="do not match"):
        p.check(["python", "script.py"])
    with pytest.raises(PermissionDeniedError, match="do not match"):
        p.check(["python", "-m", "http.server"])


def test_project_can_narrow_but_never_widen(isolated_user_dirs: Path) -> None:
    assert policy().check(["pytest"])
    with pytest.raises(PermissionDeniedError, match="project config"):
        policy(project_allowed=["ruff"]).check(["pytest"])
    with pytest.raises(PermissionDeniedError, match="not in the allowlist"):
        policy(project_allowed=["curl"]).check(["curl"])


def test_missing_executable_is_a_config_error(isolated_user_dirs: Path) -> None:
    config = isolated_user_dirs / "config"
    config.mkdir(parents=True)
    (config / "allowlist.yaml").write_text("commands:\n  - name: definitely-not-installed\n")
    with pytest.raises(ConfigError, match="not found"):
        policy().check(["definitely-not-installed"])


def test_malformed_user_allowlist_is_rejected(isolated_user_dirs: Path) -> None:
    config = isolated_user_dirs / "config"
    config.mkdir(parents=True)
    (config / "allowlist.yaml").write_text("commands:\n  - nme: typo\n")
    with pytest.raises(ConfigError):
        policy()


def test_environment_is_scrubbed(monkeypatch: pytest.MonkeyPatch, isolated_user_dirs: Path) -> None:
    monkeypatch.setenv("AWS_SECRET_ACCESS_KEY", "leak")
    monkeypatch.setenv("GITHUB_TOKEN", "leak")
    monkeypatch.setenv("KEEP_ME", "yes")
    config = isolated_user_dirs / "config"
    config.mkdir(parents=True)
    (config / "allowlist.yaml").write_text("env_passthrough: [KEEP_ME]\n")
    env = policy().environment()
    assert "AWS_SECRET_ACCESS_KEY" not in env and "GITHUB_TOKEN" not in env
    assert env["KEEP_ME"] == "yes" and "PATH" in env


@pytest.mark.parametrize(
    ("argv", "expected"),
    [
        (["rm", "-rf", "x"], True),
        (["/bin/rm", "x"], True),
        (["git", "clean", "-fd"], True),
        (["git", "reset", "--hard"], True),
        (["git", "push", "--force"], True),
        (["git", "status"], False),
        (["pytest"], False),
    ],
)
def test_destructive_detection(argv: list[str], expected: bool) -> None:
    assert is_destructive_command(argv) is expected


# --- trust -------------------------------------------------------------------------------

SAFE_TASK = f"name: hello\nsteps:\n  - {{name: run, command: [{PYTHON_NAME}, hello.py]}}\n"


@pytest.fixture
def runnable(project: Project, write: Writer, allow_python: None) -> Project:
    write(project.root, "hello.py", "print('hello from task')\n")
    write(project.root, ".ai-optimizer/tasks/hello.yaml", SAFE_TASK)
    return project


def test_untrusted_project_refuses_to_run(runnable: Project) -> None:
    with pytest.raises(TrustError):
        services.run_task(runnable, "hello")


def test_trust_allows_run_and_change_invalidates(runnable: Project, write: Writer) -> None:
    services.trust_project(runnable)
    assert services.run_task(runnable, "hello")["status"] == "passed"
    write(
        runnable.root,
        ".ai-optimizer/tasks/hello.yaml",
        SAFE_TASK.replace("hello.py", "evil.py"),
    )
    with pytest.raises(TrustError):
        services.run_task(runnable, "hello")


def test_new_task_file_invalidates_trust(runnable: Project, write: Writer) -> None:
    services.trust_project(runnable)
    write(runnable.root, ".ai-optimizer/tasks/extra.yaml", SAFE_TASK.replace("hello", "extra"))
    assert not trust_store.is_trusted(runnable.paths)


def test_config_change_invalidates_trust(runnable: Project, write: Writer) -> None:
    services.trust_project(runnable)
    write(runnable.root, ".ai-optimizer/config.yaml", "tasks:\n  default_timeout_seconds: 5\n")
    assert not trust_store.is_trusted(runnable.paths)


def test_revoke_trust(runnable: Project) -> None:
    services.trust_project(runnable)
    assert services.revoke_trust(runnable)["revoked"] is True
    assert services.revoke_trust(runnable)["revoked"] is False
    with pytest.raises(TrustError):
        services.run_task(runnable, "hello")


def test_trust_record_is_stored_outside_repository(
    runnable: Project, isolated_user_dirs: Path
) -> None:
    services.trust_project(runnable)
    assert (isolated_user_dirs / "state" / "trust.json").is_file()
    assert not list(runnable.root.rglob("trust.json"))


def test_repository_cannot_trust_itself(runnable: Project, write: Writer) -> None:
    write(runnable.root, ".ai-optimizer/trust.json", '{"projects": {}}')
    assert not trust_store.is_trusted(runnable.paths)


def test_cannot_trust_invalid_configuration(project: Project, write: Writer) -> None:
    write(project.root, ".ai-optimizer/tasks/bad.yaml", "name: bad\nsteps: []\n")
    with pytest.raises(ConfigError):
        services.trust_project(project)


def test_trust_summary_lists_commands(runnable: Project) -> None:
    summary = services.trust_summary(runnable)
    assert summary["tasks"][0]["commands"] == [f"{PYTHON_NAME} hello.py"]
    assert summary["already_trusted"] is False


# --- execution ---------------------------------------------------------------------------


def define(project: Project, write: Writer, name: str, body: str) -> None:
    write(project.root, f".ai-optimizer/tasks/{name}.yaml", f"name: {name}\n{body}")
    services.trust_project(project)


def py(script: str) -> str:
    return f"[{PYTHON_NAME}, {script}]"


def test_successful_run_report(runnable: Project) -> None:
    services.trust_project(runnable)
    report = services.run_task(runnable, "hello")
    assert report["status"] == "passed"
    result = report["steps"][0]
    assert result["exit_code"] == 0 and "hello from task" in result["stdout"]
    files = [runnable.root / f for f in report["report_files"]]
    assert {f.suffix for f in files} == {".json", ".md"} and all(f.is_file() for f in files)
    assert "hello from task" in files[1].read_text()


def test_failure_captures_exit_code_and_stderr(runnable: Project, write: Writer) -> None:
    write(runnable.root, "fail.py", "import sys\nprint('boom', file=sys.stderr)\nsys.exit(7)\n")
    define(runnable, write, "failing", f"steps:\n  - {{name: s, command: {py('fail.py')}}}\n")
    report = services.run_task(runnable, "failing")
    assert report["status"] == "failed"
    assert report["steps"][0]["exit_code"] == 7
    assert "boom" in report["steps"][0]["stderr"]


def test_stop_and_continue_semantics(runnable: Project, write: Writer) -> None:
    write(runnable.root, "fail.py", "raise SystemExit(1)\n")
    write(runnable.root, "ok.py", "print('ran')\n")
    define(
        runnable, write, "stops",
        f"steps:\n  - {{name: bad, command: {py('fail.py')}}}\n  - {{name: after, command: {py('ok.py')}}}\n",
    )  # fmt: skip
    stops = services.run_task(runnable, "stops")
    assert [s["status"] for s in stops["steps"]] == ["failed", "skipped"]
    assert "stopped" in stops["steps"][1]["skip_reason"]
    define(
        runnable, write, "continues",
        f"steps:\n  - {{name: bad, command: {py('fail.py')}, on_failure: continue}}\n"
        f"  - {{name: after, command: {py('ok.py')}}}\n",
    )  # fmt: skip
    cont = services.run_task(runnable, "continues")
    assert [s["status"] for s in cont["steps"]] == ["failed", "passed"]
    assert cont["status"] == "failed"


def test_dependents_of_failed_steps_are_skipped(runnable: Project, write: Writer) -> None:
    write(runnable.root, "fail.py", "raise SystemExit(1)\n")
    write(runnable.root, "ok.py", "print('ran')\n")
    define(
        runnable, write, "deps",
        f"steps:\n  - {{name: a, command: {py('fail.py')}, on_failure: continue}}\n"
        f"  - {{name: b, command: {py('ok.py')}, depends_on: [a]}}\n"
        f"  - {{name: c, command: {py('ok.py')}}}\n",
    )  # fmt: skip
    report = services.run_task(runnable, "deps")
    statuses = {s["name"]: s["status"] for s in report["steps"]}
    assert statuses == {"a": "failed", "b": "skipped", "c": "passed"}


def test_timeout_kills_the_whole_process_tree(runnable: Project, write: Writer) -> None:
    pid_file = runnable.root / "child.pid"
    write(
        runnable.root,
        "hang.py",
        "import subprocess, sys, time\n"
        "child = subprocess.Popen([sys.executable, '-c', 'import time; time.sleep(60)'])\n"
        "open('child.pid', 'w').write(str(child.pid))\n"
        "time.sleep(60)\n",
    )
    define(
        runnable, write, "hang",
        f"steps:\n  - {{name: s, command: {py('hang.py')}, timeout_seconds: 2}}\n",
    )  # fmt: skip
    started = time.monotonic()
    report = services.run_task(runnable, "hang")
    assert time.monotonic() - started < 20
    assert report["steps"][0]["status"] == "timeout"
    assert report["status"] == "failed"
    child = int(pid_file.read_text())
    for _ in range(50):
        try:
            os.kill(child, 0)
        except ProcessLookupError:
            break
        time.sleep(0.1)
    else:
        pytest.fail("child process survived the timeout")


@pytest.mark.skipif(sys.platform == "win32", reason="process groups are POSIX only")
def test_secrets_in_environment_do_not_reach_children(
    runnable: Project, write: Writer, monkeypatch: pytest.MonkeyPatch
) -> None:
    monkeypatch.setenv("TOP_SECRET_TOKEN", "should-not-leak")
    write(
        runnable.root, "env.py",
        "import os\nprint('TOP_SECRET_TOKEN' in os.environ)\nprint(sorted(os.environ)[:0])\n",
    )  # fmt: skip
    define(runnable, write, "envcheck", f"steps:\n  - {{name: s, command: {py('env.py')}}}\n")
    report = services.run_task(runnable, "envcheck")
    assert report["steps"][0]["stdout"].splitlines()[0] == "False"


def test_output_is_redacted_and_truncated(runnable: Project, write: Writer) -> None:
    write(
        runnable.root, "noisy.py",
        "print('password = hunter2hunter2')\nprint('ghp_' + 'a' * 30)\nprint('x' * 5000)\n",
    )  # fmt: skip
    write(runnable.root, ".ai-optimizer/config.yaml", "tasks:\n  max_output_bytes: 1024\n")
    define(runnable, write, "noisy", f"steps:\n  - {{name: s, command: {py('noisy.py')}}}\n")
    fresh = Project(
        paths=runnable.paths,
        config=ProjectConfig.model_validate({"tasks": {"max_output_bytes": 1024}}),
    )
    report = services.run_task(fresh, "noisy")
    out = report["steps"][0]["stdout"]
    assert "hunter2" not in out and "ghp_a" not in out
    assert report["steps"][0]["truncated"] is True
    assert len(out) <= 1024
    report_text = "".join((fresh.root / f).read_text() for f in report["report_files"])
    assert "hunter2" not in report_text


def test_working_directory_cannot_escape_project(runnable: Project, write: Writer) -> None:
    define(
        runnable, write, "escape",
        f"steps:\n  - {{name: s, command: {py('hello.py')}, cwd: ..}}\n",
    )  # fmt: skip
    with pytest.raises(PermissionDeniedError, match="outside the project root"):
        services.run_task(runnable, "escape")


def test_working_directory_symlink_escape_is_denied(
    runnable: Project, write: Writer, tmp_path: Path
) -> None:
    outside = tmp_path / "outside"
    outside.mkdir()
    os.symlink(outside, runnable.root / "linked")
    define(
        runnable,
        write,
        "linkescape",
        f"steps:\n  - {{name: s, command: {py('hello.py')}, cwd: linked}}\n",
    )
    with pytest.raises(PermissionDeniedError):
        services.run_task(runnable, "linkescape")


def test_subdirectory_working_directory_is_allowed(runnable: Project, write: Writer) -> None:
    write(runnable.root, "sub/where.py", "import os\nprint(os.path.basename(os.getcwd()))\n")
    define(
        runnable, write, "sub", f"steps:\n  - {{name: s, command: {py('where.py')}, cwd: sub}}\n"
    )
    assert services.run_task(runnable, "sub")["steps"][0]["stdout"].strip() == "sub"


def test_disallowed_step_blocks_the_whole_task_before_anything_runs(
    runnable: Project, write: Writer
) -> None:
    marker = runnable.root / "marker.txt"
    write(runnable.root, "touch.py", "open('marker.txt', 'w').write('ran')\n")
    define(
        runnable, write, "mixed",
        f"steps:\n  - {{name: first, command: {py('touch.py')}}}\n"
        "  - {name: second, command: [curl, 'http://example.com']}\n",
    )  # fmt: skip
    with pytest.raises(PermissionDeniedError):
        services.run_task(runnable, "mixed")
    assert not marker.exists()


def test_shell_in_task_is_denied(runnable: Project, write: Writer) -> None:
    define(runnable, write, "shelly", "steps:\n  - {name: s, command: [sh, -c, 'echo hi']}\n")
    with pytest.raises(PermissionDeniedError):
        services.run_task(runnable, "shelly")


def test_shell_metacharacters_are_not_interpreted(runnable: Project, write: Writer) -> None:
    write(runnable.root, "echo_args.py", "import sys\nprint(sys.argv[1:])\n")
    define(
        runnable, write, "meta",
        f"steps:\n  - {{name: s, command: [{PYTHON_NAME}, echo_args.py, ';', 'touch pwned', '$(id)']}}\n",
    )  # fmt: skip
    report = services.run_task(runnable, "meta")
    assert report["status"] == "passed"
    assert "$(id)" in report["steps"][0]["stdout"]
    assert not (runnable.root / "pwned").exists()


def test_destructive_requires_explicit_flag(
    runnable: Project, write: Writer, isolated_user_dirs: Path
) -> None:
    config = isolated_user_dirs / "config"
    (config / "allowlist.yaml").write_text(f"commands:\n  - name: {PYTHON_NAME}\n  - name: rm\n")
    victim = write(runnable.root, "victim.txt", "data")
    define(runnable, write, "wipe", "steps:\n  - {name: s, command: [rm, victim.txt]}\n")
    with pytest.raises(PermissionDeniedError, match="allow-destructive"):
        services.run_task(runnable, "wipe")
    assert victim.exists()
    define(
        runnable, write, "flagged",
        f"destructive: true\nsteps:\n  - {{name: s, command: {py('hello.py')}}}\n",
    )  # fmt: skip
    with pytest.raises(PermissionDeniedError, match="destructive"):
        services.run_task(runnable, "flagged")
    assert services.run_task(runnable, "flagged", allow_destructive=True)["status"] == "passed"
    services.run_task(runnable, "wipe", allow_destructive=True)
    assert not victim.exists()


def test_project_allowed_commands_narrow_execution(runnable: Project) -> None:
    services.trust_project(runnable)
    narrowed = Project(
        paths=runnable.paths,
        config=ProjectConfig.model_validate({"tasks": {"allowed_commands": ["ruff"]}}),
    )
    with pytest.raises(PermissionDeniedError, match="project config"):
        services.run_task(narrowed, "hello")


def test_unknown_task(runnable: Project) -> None:
    services.trust_project(runnable)
    with pytest.raises(ConfigError, match="Unknown task"):
        services.run_task(runnable, "ghost")


def test_task_listing_shows_trust(runnable: Project) -> None:
    listing = services.list_tasks(runnable)
    assert listing["trusted"] is False
    assert listing["tasks"][0]["name"] == "hello"
    services.trust_project(runnable)
    assert services.list_tasks(runnable)["trusted"] is True
