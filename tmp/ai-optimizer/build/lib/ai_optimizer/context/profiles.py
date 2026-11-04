"""Context profiles: built-in defaults plus YAML overrides."""

from __future__ import annotations

import re
from pathlib import Path

from pydantic import Field, ValidationError, field_validator

from ai_optimizer.core.config import (
    ProjectPaths,
    StrictModel,
    format_validation_error,
    load_yaml_mapping,
)
from ai_optimizer.core.errors import ConfigError
from ai_optimizer.core.globs import match_any

NAME_PATTERN = re.compile(r"^[a-z0-9][a-z0-9_-]*$")
KINDS = frozenset({"source", "test", "doc", "config", "other"})
_GIT_REF = re.compile(r"^[A-Za-z0-9_][A-Za-z0-9._/~^@-]*$")


class Profile(StrictModel):
    """A named scope over the project used to focus context."""

    name: str
    description: str = ""
    include: list[str] = Field(default_factory=list)
    exclude: list[str] = Field(default_factory=list)
    keywords: list[str] = Field(default_factory=list)
    kind_weights: dict[str, float] = Field(default_factory=dict)
    max_tokens: int | None = Field(default=None, ge=200)
    convention_files: list[str] = Field(default_factory=list)
    changed_only: bool = False
    changed_since: str | None = None

    @field_validator("name")
    @classmethod
    def _valid_name(cls, value: str) -> str:
        if not NAME_PATTERN.match(value):
            raise ValueError("must match [a-z0-9][a-z0-9_-]*")
        return value

    @field_validator("kind_weights")
    @classmethod
    def _valid_kinds(cls, value: dict[str, float]) -> dict[str, float]:
        unknown = set(value) - KINDS
        if unknown:
            raise ValueError(f"unknown kinds {sorted(unknown)}; allowed: {sorted(KINDS)}")
        if any(weight <= 0 for weight in value.values()):
            raise ValueError("weights must be positive")
        return value

    @field_validator("changed_since")
    @classmethod
    def _valid_ref(cls, value: str | None) -> str | None:
        if value is not None and not _GIT_REF.match(value):
            raise ValueError("not a valid git reference")
        return value

    def accepts(self, path: str) -> bool:
        """Return True if ``path`` passes the include/exclude globs."""
        if self.include and not match_any(self.include, path):
            return False
        return not (self.exclude and match_any(self.exclude, path))


FRONTEND_EXTENSIONS = (".ts", ".tsx", ".js", ".jsx", ".vue", ".svelte", ".css", ".scss", ".html")


def _builtin(**kwargs: object) -> Profile:
    return Profile.model_validate(kwargs)


BUILTIN_PROFILES: dict[str, Profile] = {
    p.name: p
    for p in (
        _builtin(
            name="frontend",
            description="UI code: components, styles and client-side logic.",
            include=[f"**/*{ext}" for ext in FRONTEND_EXTENSIONS],
            exclude=["**/node_modules/**"],
            keywords=["component", "view", "style", "render", "state", "route"],
        ),
        _builtin(
            name="backend",
            description="Server-side code: services, APIs and data access.",
            exclude=["**/*.css", "**/*.scss", "**/*.html", "**/*.svelte", "**/*.vue"],
            keywords=["api", "service", "model", "controller", "route", "handler", "database"],
            kind_weights={"source": 1.5, "test": 0.7, "doc": 0.5},
        ),
        _builtin(
            name="database",
            description="Schemas, migrations, models and queries.",
            keywords=["schema", "migration", "model", "table", "query", "sql", "database"],
            kind_weights={"source": 1.2, "doc": 0.5},
        ),
        _builtin(
            name="testing",
            description="Tests, fixtures and test configuration.",
            keywords=["test", "fixture", "mock", "assert", "conftest"],
            kind_weights={"test": 3.0, "source": 0.8, "doc": 0.3},
        ),
        _builtin(
            name="security",
            description="Authentication, authorization, secrets handling and input validation.",
            keywords=[
                "auth", "permission", "token", "password", "session", "crypto", "sanitize",
                "validate",
            ],
            kind_weights={"source": 1.5, "config": 1.2},
        ),
        _builtin(
            name="refactoring",
            description="Files changed since the last cache update, with related tests and docs.",
            changed_only=True,
        ),
        _builtin(
            name="code-review",
            description="Files changed since the last cache update, with related tests and docs.",
            changed_only=True,
        ),
    )
}  # fmt: skip


def parse_profile(text: str, source: str, default_name: str) -> Profile:
    """Parse and validate one profile document."""
    data = load_yaml_mapping(text, source)
    data.setdefault("name", default_name)
    try:
        return Profile.model_validate(data)
    except ValidationError as exc:
        raise ConfigError(format_validation_error(exc, source)) from exc


def load_profile_files(paths: ProjectPaths) -> dict[str, Profile]:
    """Load all valid-or-fail profile YAML files from the profiles directory."""
    profiles: dict[str, Profile] = {}
    directory = paths.profiles_dir
    if not directory.is_dir():
        return profiles
    for file in sorted(directory.glob("*.y*ml")):
        if file.suffix not in {".yaml", ".yml"}:
            continue
        profile = parse_profile(_read(file), str(file), file.stem)
        if profile.name in profiles:
            raise ConfigError(f"Duplicate profile name '{profile.name}' in {file}")
        profiles[profile.name] = profile
    return profiles


def _read(file: Path) -> str:
    try:
        return file.read_text(encoding="utf-8")
    except (OSError, UnicodeDecodeError) as exc:
        raise ConfigError(f"Cannot read {file}: {exc}") from exc


def load_profiles(paths: ProjectPaths) -> dict[str, Profile]:
    """Return built-in profiles overridden by project profile files."""
    return {**BUILTIN_PROFILES, **load_profile_files(paths)}


def get_profile(paths: ProjectPaths, name: str) -> Profile:
    profiles = load_profiles(paths)
    if name not in profiles:
        raise ConfigError(f"Unknown profile '{name}'. Available: {', '.join(sorted(profiles))}")
    return profiles[name]
