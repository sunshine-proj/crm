"""Task definition models and registry loading."""

from __future__ import annotations

import re
from pathlib import Path
from typing import Literal

from pydantic import Field, ValidationError, field_validator, model_validator

from ai_optimizer.core.config import (
    ProjectPaths,
    StrictModel,
    format_validation_error,
    load_yaml_mapping,
)
from ai_optimizer.core.errors import ConfigError

NAME_PATTERN = re.compile(r"^[a-z0-9][a-z0-9_-]*$")
MAX_TIMEOUT_SECONDS = 3600


class Step(StrictModel):
    """One command in a task."""

    name: str
    command: list[str] = Field(min_length=1)
    depends_on: list[str] = Field(default_factory=list)
    timeout_seconds: int | None = Field(default=None, gt=0, le=MAX_TIMEOUT_SECONDS)
    on_failure: Literal["stop", "continue"] = "stop"
    cwd: str = "."

    @field_validator("name")
    @classmethod
    def _valid_name(cls, value: str) -> str:
        if not NAME_PATTERN.match(value):
            raise ValueError("must match [a-z0-9][a-z0-9_-]*")
        return value

    @field_validator("command")
    @classmethod
    def _valid_command(cls, value: list[str]) -> list[str]:
        if any(not part or "\0" in part for part in value):
            raise ValueError("command parts must be non-empty strings")
        return value


class TaskDefinition(StrictModel):
    """A named, reusable sequence of steps."""

    name: str
    description: str = ""
    destructive: bool = False
    steps: list[Step] = Field(min_length=1)

    @field_validator("name")
    @classmethod
    def _valid_name(cls, value: str) -> str:
        if not NAME_PATTERN.match(value):
            raise ValueError("must match [a-z0-9][a-z0-9_-]*")
        return value

    @model_validator(mode="after")
    def _valid_graph(self) -> TaskDefinition:
        names = [step.name for step in self.steps]
        if len(set(names)) != len(names):
            raise ValueError("step names must be unique")
        for step in self.steps:
            missing = set(step.depends_on) - set(names)
            if missing:
                raise ValueError(f"step '{step.name}' depends on unknown steps {sorted(missing)}")
        self.ordered_steps()
        return self

    def ordered_steps(self) -> list[Step]:
        """Return steps in a stable topological order; raise ValueError on cycles."""
        remaining = list(self.steps)
        done: set[str] = set()
        ordered: list[Step] = []
        while remaining:
            ready = next((s for s in remaining if set(s.depends_on) <= done), None)
            if ready is None:
                raise ValueError("steps contain a dependency cycle")
            remaining.remove(ready)
            done.add(ready.name)
            ordered.append(ready)
        return ordered


def parse_task(text: str, source: str, default_name: str) -> TaskDefinition:
    """Parse and validate one task document."""
    data = load_yaml_mapping(text, source)
    data.setdefault("name", default_name)
    try:
        return TaskDefinition.model_validate(data)
    except ValidationError as exc:
        raise ConfigError(format_validation_error(exc, source)) from exc


def load_tasks(paths: ProjectPaths) -> dict[str, TaskDefinition]:
    """Load and validate every task file; any invalid file raises ConfigError."""
    tasks: dict[str, TaskDefinition] = {}
    directory = paths.tasks_dir
    if not directory.is_dir():
        return tasks
    for file in sorted(directory.iterdir()):
        if file.suffix not in {".yaml", ".yml"} or not file.is_file():
            continue
        task = parse_task(_read(file), str(file), file.stem)
        if task.name in tasks:
            raise ConfigError(f"Duplicate task name '{task.name}' in {file}")
        tasks[task.name] = task
    return tasks


def _read(file: Path) -> str:
    try:
        return file.read_text(encoding="utf-8")
    except (OSError, UnicodeDecodeError) as exc:
        raise ConfigError(f"Cannot read {file}: {exc}") from exc


def get_task(paths: ProjectPaths, name: str) -> TaskDefinition:
    tasks = load_tasks(paths)
    if name not in tasks:
        available = ", ".join(sorted(tasks)) or "none"
        raise ConfigError(f"Unknown task '{name}'. Available tasks: {available}")
    return tasks[name]
