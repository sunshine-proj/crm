"""Project root discovery and the Project handle used by services."""

from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path

from ai_optimizer.core.config import (
    CONFIG_DIR_NAME,
    ProjectConfig,
    ProjectPaths,
    load_config,
)
from ai_optimizer.core.errors import ConfigError

ROOT_MARKERS = (CONFIG_DIR_NAME, ".git", "pyproject.toml", "package.json")


def find_project_root(start: Path) -> Path:
    """Walk up from ``start`` to the nearest directory with a project marker.

    Falls back to ``start`` itself when no marker is found.
    """
    start = start.resolve()
    for candidate in (start, *start.parents):
        if any((candidate / marker).exists() for marker in ROOT_MARKERS):
            return candidate
    return start


@dataclass(frozen=True, slots=True)
class Project:
    """A resolved project: its paths and validated configuration."""

    paths: ProjectPaths
    config: ProjectConfig

    @property
    def root(self) -> Path:
        return self.paths.root

    @property
    def name(self) -> str:
        return self.config.project.name or self.paths.root.name


def resolve_root(project_dir: Path | None, cwd: Path | None = None) -> Path:
    """Resolve the project root from an explicit directory or the working directory."""
    if project_dir is not None:
        root = project_dir.expanduser().resolve()
        if not root.is_dir():
            raise ConfigError(f"Project directory does not exist: {root}")
        return root
    return find_project_root(cwd or Path.cwd())


def load_project(project_dir: Path | None = None, cwd: Path | None = None) -> Project:
    """Resolve the root and load its configuration."""
    paths = ProjectPaths(resolve_root(project_dir, cwd))
    return Project(paths=paths, config=load_config(paths))
