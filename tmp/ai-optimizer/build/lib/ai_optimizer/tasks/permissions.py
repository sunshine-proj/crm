"""User-level command allowlist, destructive-command detection and path resolution.

The allowlist lives outside the repository so that a project cannot grant itself
permissions. A project may only narrow it (``tasks.allowed_commands``).
"""

from __future__ import annotations

import os
import shutil
from dataclasses import dataclass, field
from pathlib import Path

import yaml
from pydantic import Field, ValidationError

from ai_optimizer.core.config import StrictModel, format_validation_error
from ai_optimizer.core.errors import ConfigError, PermissionDeniedError

ALLOWLIST_FILE = "allowlist.yaml"

# Never runnable unless the user allowlist names them explicitly.
DENIED_EXECUTABLES = frozenset(
    {
        "sh", "bash", "zsh", "fish", "dash", "ksh", "csh", "tcsh", "cmd", "powershell", "pwsh",
        "env", "sudo", "su", "doas", "xargs", "nohup", "npx", "eval", "exec", "busybox",
        "wscript", "cscript",
    }
)  # fmt: skip
INTERPRETER_PREFIXES = ("python", "node", "ruby", "perl", "php", "deno", "bun")
INLINE_CODE_FLAGS = frozenset({"-c", "-e", "-p", "--eval", "--print", "-r", "--require"})
DESTRUCTIVE_EXECUTABLES = frozenset(
    {
        "rm", "rmdir", "del", "rd", "mkfs", "dd", "shred", "format", "truncate",
        "chmod", "chown", "mv",
    }
)  # fmt: skip
BASE_ENV_VARS = ("PATH", "HOME", "LANG", "LC_ALL", "TERM", "TMPDIR", "SYSTEMROOT", "USERPROFILE")


class AllowedCommand(StrictModel):
    """An allowlist entry: an executable name and an optional required argument prefix."""

    name: str
    args_prefix: list[str] = Field(default_factory=list)


class UserPolicyFile(StrictModel):
    commands: list[AllowedCommand] = Field(default_factory=list)
    env_passthrough: list[str] = Field(default_factory=list)


DEFAULT_COMMANDS = [
    AllowedCommand(name="pytest"),
    AllowedCommand(name="ruff"),
    AllowedCommand(name="mypy"),
    *(
        AllowedCommand(name=py, args_prefix=["-m", module])
        for py in ("python", "python3")
        for module in ("pytest", "ruff", "mypy")
    ),
]
DEFAULT_ALLOWED_NAMES = frozenset(c.name for c in DEFAULT_COMMANDS)


def user_config_dir() -> Path:
    """Directory holding the user-level allowlist."""
    override = os.environ.get("AIOPT_CONFIG_DIR")
    if override:
        return Path(override)
    base = os.environ.get("XDG_CONFIG_HOME") or str(Path.home() / ".config")
    return Path(base) / "ai-optimizer"


def user_state_dir() -> Path:
    """Directory holding trust records."""
    override = os.environ.get("AIOPT_STATE_DIR")
    if override:
        return Path(override)
    base = os.environ.get("XDG_STATE_HOME") or str(Path.home() / ".local" / "state")
    return Path(base) / "ai-optimizer"


@dataclass(frozen=True, slots=True)
class CommandPolicy:
    """Effective rules for which commands may run."""

    commands: tuple[AllowedCommand, ...]
    explicit_names: frozenset[str] = frozenset()
    env_passthrough: tuple[str, ...] = ()
    project_allowed: frozenset[str] | None = None
    search_path: str = field(default_factory=lambda: os.environ.get("PATH", os.defpath))

    def check(self, argv: list[str]) -> str:
        """Validate ``argv`` and return the absolute path of the executable."""
        name = argv[0]
        if "/" in name or "\\" in name or name.startswith("."):
            raise PermissionDeniedError(
                f"Command '{name}' must be a bare executable name resolved via PATH."
            )
        base = name.lower().removesuffix(".exe")
        if base in DENIED_EXECUTABLES and name not in self.explicit_names:
            raise PermissionDeniedError(
                f"Command '{name}' (shell or command runner) is denied by default."
            )
        if base.startswith(INTERPRETER_PREFIXES) and len(argv) > 1 and argv[1] in INLINE_CODE_FLAGS:
            raise PermissionDeniedError(
                f"Inline code execution ('{name} {argv[1]}') is not allowed."
            )
        candidates = [c for c in self.commands if c.name == name]
        if not candidates:
            raise PermissionDeniedError(
                f"Command '{name}' is not in the allowlist. "
                f"Add it to {user_config_dir() / ALLOWLIST_FILE} to permit it."
            )
        if self.project_allowed is not None and name not in self.project_allowed:
            raise PermissionDeniedError(f"Command '{name}' is not permitted by the project config.")
        if not any(argv[1 : 1 + len(c.args_prefix)] == c.args_prefix for c in candidates):
            raise PermissionDeniedError(
                f"Arguments for '{name}' do not match an allowed pattern: {' '.join(argv)}"
            )
        resolved = shutil.which(name, path=self.search_path)
        if resolved is None:
            raise ConfigError(f"Executable '{name}' was not found on PATH.")
        return resolved

    def environment(self) -> dict[str, str]:
        """Build the scrubbed environment passed to child processes."""
        wanted = (*BASE_ENV_VARS, *self.env_passthrough)
        return {key: os.environ[key] for key in wanted if key in os.environ}


def load_policy(project_allowed: list[str] | None = None) -> CommandPolicy:
    """Combine built-in defaults with the user's allowlist file."""
    file = user_config_dir() / ALLOWLIST_FILE
    user = UserPolicyFile()
    if file.is_file():
        try:
            raw = yaml.safe_load(file.read_text(encoding="utf-8")) or {}
            user = UserPolicyFile.model_validate(raw)
        except (OSError, yaml.YAMLError) as exc:
            raise ConfigError(f"Cannot read {file}: {exc}") from exc
        except ValidationError as exc:
            raise ConfigError(format_validation_error(exc, str(file))) from exc
    return CommandPolicy(
        commands=(*DEFAULT_COMMANDS, *user.commands),
        explicit_names=frozenset(c.name for c in user.commands),
        env_passthrough=tuple(user.env_passthrough),
        project_allowed=None if project_allowed is None else frozenset(project_allowed),
    )


def is_destructive_command(argv: list[str]) -> bool:
    """Heuristically detect commands that delete, overwrite or rewrite history."""
    name = Path(argv[0]).name.lower().removesuffix(".exe")
    if name in DESTRUCTIVE_EXECUTABLES:
        return True
    if name == "git" and len(argv) > 1:
        args = set(argv[1:])
        if "clean" in args or ("reset" in args and "--hard" in args):
            return True
        if "push" in args and args & {"--force", "-f", "--force-with-lease"}:
            return True
    return False
