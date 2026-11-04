"""Project file scanning that honors exclusions, .gitignore and sensitive-file rules."""

from __future__ import annotations

import fnmatch
import os
from dataclasses import dataclass, field
from pathlib import Path

from ai_optimizer.core.config import CONFIG_DIR_NAME, IndexConfig
from ai_optimizer.core.globs import IgnoreRule, is_ignored, parse_ignore_lines

SENSITIVE_FILE_PATTERNS = (
    ".env",
    ".env.*",
    "*.pem",
    "*.key",
    "*.p12",
    "*.pfx",
    "id_rsa*",
    "id_ed25519*",
    ".npmrc",
    ".pypirc",
    ".netrc",
    "credentials*",
    "secrets.*",
    "*.kdbx",
)
SENSITIVE_DIRS = frozenset({".ssh", ".aws"})
ALWAYS_EXCLUDED_DIRS = frozenset({".git", CONFIG_DIR_NAME})


@dataclass(frozen=True, slots=True)
class FileInfo:
    """Metadata for a scanned file (posix path relative to the project root)."""

    path: str
    size: int
    mtime_ns: int


@dataclass(slots=True)
class ScanResult:
    files: list[FileInfo] = field(default_factory=list)
    skipped: dict[str, int] = field(default_factory=dict)

    def skip(self, reason: str) -> None:
        self.skipped[reason] = self.skipped.get(reason, 0) + 1


def is_sensitive_name(name: str, extra: list[str] | None = None) -> bool:
    """Return True if a file name matches a sensitive-file pattern."""
    lowered = name.lower()
    patterns = (*SENSITIVE_FILE_PATTERNS, *(extra or []))
    return any(fnmatch.fnmatch(lowered, pattern.lower()) for pattern in patterns)


def is_within(root: Path, candidate: Path) -> bool:
    """Return True if ``candidate`` resolves to a location inside ``root``."""
    try:
        candidate.resolve().relative_to(root.resolve())
    except (ValueError, OSError):
        return False
    return True


def _read_ignore_file(path: Path) -> list[str]:
    try:
        return path.read_text(encoding="utf-8", errors="replace").splitlines()
    except OSError:
        return []


def scan_project(root: Path, index: IndexConfig) -> ScanResult:
    """List indexable files under ``root`` in a deterministic order."""
    root = root.resolve()
    result = ScanResult()
    base_rules = parse_ignore_lines(index.exclude)
    _walk(root, root, "", base_rules, index, result)
    result.files.sort(key=lambda f: f.path)
    return result


def _walk(
    root: Path,
    directory: Path,
    rel_dir: str,
    inherited: list[IgnoreRule],
    index: IndexConfig,
    result: ScanResult,
) -> None:
    rules = inherited
    if index.respect_gitignore:
        gitignore = directory / ".gitignore"
        if gitignore.is_file():
            rules = [*inherited, *parse_ignore_lines(_read_ignore_file(gitignore), rel_dir)]
    try:
        entries = sorted(os.scandir(directory), key=lambda e: e.name)
    except OSError:
        result.skip("unreadable_dir")
        return
    for entry in entries:
        rel = f"{rel_dir}/{entry.name}" if rel_dir else entry.name
        entry_path = Path(entry.path)
        if entry.is_symlink():
            if not is_within(root, entry_path):
                result.skip("symlink_outside_root")
                continue
            if entry_path.is_dir():
                result.skip("symlinked_dir")
                continue
        if entry.is_dir(follow_symlinks=False):
            if entry.name in ALWAYS_EXCLUDED_DIRS:
                # Tool-owned directories are invisible so statistics stay stable across runs.
                continue
            if entry.name in SENSITIVE_DIRS or is_ignored(rules, rel, True):
                result.skip("excluded_dir")
            else:
                _walk(root, entry_path, rel, rules, index, result)
            continue
        if not entry_path.is_file():
            continue
        if is_sensitive_name(entry.name, index.extra_sensitive):
            result.skip("sensitive")
        elif is_ignored(rules, rel, False):
            result.skip("ignored")
        else:
            try:
                stat = entry_path.stat()
            except OSError:
                result.skip("unreadable")
                continue
            if stat.st_size > index.max_file_size_bytes:
                result.skip("too_large")
            else:
                result.files.append(FileInfo(rel, stat.st_size, stat.st_mtime_ns))
