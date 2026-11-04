"""Exception hierarchy. Each error carries the CLI exit code it maps to."""

from __future__ import annotations


class AIOptError(Exception):
    """Base class for expected, user-facing errors."""

    exit_code = 1


class ConfigError(AIOptError):
    """Invalid or unusable configuration, task, profile or arguments."""

    exit_code = 2


class TaskFailedError(AIOptError):
    """A task or validation run failed."""

    exit_code = 3


class TrustError(AIOptError):
    """The project has not been trusted for task execution."""

    exit_code = 4


class PermissionDeniedError(AIOptError):
    """A command or operation is not permitted by the execution policy."""

    exit_code = 4


class CacheError(AIOptError):
    """The cache is missing, corrupt or incompatible."""

    exit_code = 1
