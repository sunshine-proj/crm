"""Project configuration models and loading."""

from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from typing import Any, Literal

import yaml
from pydantic import BaseModel, ConfigDict, Field, ValidationError

from ai_optimizer.core.errors import ConfigError

CONFIG_DIR_NAME = ".ai-optimizer"

DEFAULT_EXCLUDES = [
    ".git/",
    ".hg/",
    ".svn/",
    ".ai-optimizer/",
    "node_modules/",
    ".venv/",
    "venv/",
    "__pycache__/",
    ".mypy_cache/",
    ".ruff_cache/",
    ".pytest_cache/",
    ".tox/",
    "dist/",
    "build/",
    "*.egg-info/",
    ".idea/",
    ".vscode/",
]


class StrictModel(BaseModel):
    """Base model that rejects unknown keys so typos are reported."""

    model_config = ConfigDict(extra="forbid")


class ProjectSettings(StrictModel):
    name: str | None = None


class IndexConfig(StrictModel):
    exclude: list[str] = Field(default_factory=lambda: list(DEFAULT_EXCLUDES))
    max_file_size_bytes: int = Field(default=1_048_576, gt=0)
    respect_gitignore: bool = True
    extra_sensitive: list[str] = Field(default_factory=list)


class CacheConfig(StrictModel):
    auto_refresh: Literal["refresh", "warn", "off"] = "refresh"


class ContextConfig(StrictModel):
    default_limit: int = Field(default=10, ge=1, le=100)
    max_output_bytes: int = Field(default=65_536, ge=1024)
    default_max_tokens: int = Field(default=8000, ge=200)
    convention_files: list[str] = Field(
        default_factory=lambda: ["AGENTS.md", "CLAUDE.md", "CONTRIBUTING.md"]
    )


class TasksConfig(StrictModel):
    default_timeout_seconds: int = Field(default=300, gt=0, le=3600)
    max_output_bytes: int = Field(default=65_536, ge=1024)
    allowed_commands: list[str] | None = None


class ProjectConfig(StrictModel):
    schema_version: Literal[1] = 1
    project: ProjectSettings = Field(default_factory=ProjectSettings)
    index: IndexConfig = Field(default_factory=IndexConfig)
    cache: CacheConfig = Field(default_factory=CacheConfig)
    context: ContextConfig = Field(default_factory=ContextConfig)
    tasks: TasksConfig = Field(default_factory=TasksConfig)


@dataclass(frozen=True, slots=True)
class ProjectPaths:
    """Well-known locations inside a managed project."""

    root: Path

    @property
    def base(self) -> Path:
        return self.root / CONFIG_DIR_NAME

    @property
    def config_file(self) -> Path:
        return self.base / "config.yaml"

    @property
    def cache_dir(self) -> Path:
        return self.base / "cache"

    @property
    def db_file(self) -> Path:
        return self.cache_dir / "index.db"

    @property
    def tasks_dir(self) -> Path:
        return self.base / "tasks"

    @property
    def profiles_dir(self) -> Path:
        return self.base / "profiles"

    @property
    def reports_dir(self) -> Path:
        return self.base / "reports"


def format_validation_error(error: ValidationError, source: str) -> str:
    """Render a pydantic error as a short, readable message."""
    lines = [f"Invalid configuration in {source}:"]
    for item in error.errors():
        location = ".".join(str(part) for part in item["loc"]) or "(root)"
        lines.append(f"  - {location}: {item['msg']}")
    return "\n".join(lines)


def load_yaml_mapping(text: str, source: str) -> dict[str, Any]:
    """Parse YAML safely and require a mapping (or empty document)."""
    try:
        data = yaml.safe_load(text)
    except yaml.YAMLError as exc:
        raise ConfigError(f"Cannot parse {source}: {exc}") from exc
    if data is None:
        return {}
    if not isinstance(data, dict):
        raise ConfigError(f"{source} must contain a YAML mapping at the top level.")
    return data


def parse_config(text: str, source: str = "config.yaml") -> ProjectConfig:
    """Parse and validate configuration text."""
    data = load_yaml_mapping(text, source)
    try:
        return ProjectConfig.model_validate(data)
    except ValidationError as exc:
        raise ConfigError(format_validation_error(exc, source)) from exc


def load_config(paths: ProjectPaths) -> ProjectConfig:
    """Load project configuration, falling back to defaults when no file exists."""
    if not paths.config_file.is_file():
        return ProjectConfig()
    try:
        text = paths.config_file.read_text(encoding="utf-8")
    except (OSError, UnicodeDecodeError) as exc:
        raise ConfigError(f"Cannot read {paths.config_file}: {exc}") from exc
    return parse_config(text, str(paths.config_file))


def default_config_yaml(project_name: str) -> str:
    """Return the default config.yaml content for a new project."""
    data: dict[str, Any] = {
        "schema_version": 1,
        "project": {"name": project_name},
        "index": {"max_file_size_bytes": 1_048_576, "respect_gitignore": True},
        "cache": {"auto_refresh": "refresh"},
        "context": {"default_limit": 10, "default_max_tokens": 8000},
        "tasks": {"default_timeout_seconds": 300},
    }
    return yaml.safe_dump(data, sort_keys=False)
