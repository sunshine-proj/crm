"""Safe file operations for installing integrations into a user's project.

Rules: never overwrite files we do not own, never rewrite JSON that contains comments,
never write outside the project root, and make every operation idempotent and reversible.
"""

from __future__ import annotations

import json
import re
import tomllib
from collections.abc import Callable
from dataclasses import asdict, dataclass, field
from pathlib import Path
from typing import Any, Literal

from ai_optimizer.core.files import is_within

Status = Literal["created", "updated", "unchanged", "removed", "skipped"]

OWNED_MARKER = (
    "<!-- managed by aiopt: update with `aiopt integrations install`, "
    "remove with `aiopt integrations uninstall` -->"
)
MARKDOWN_BLOCK = ("<!-- aiopt:begin -->", "<!-- aiopt:end -->")
TOML_BLOCK = ("# aiopt:begin", "# aiopt:end")


@dataclass(slots=True)
class FileChange:
    """The outcome of one file operation."""

    path: str
    status: Status
    detail: str = ""


@dataclass(slots=True)
class InstallReport:
    """Everything an install or uninstall did (or would do, in dry-run mode)."""

    provider: str
    dry_run: bool
    changes: list[FileChange] = field(default_factory=list)
    notes: list[str] = field(default_factory=list)

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)


def strip_jsonc(text: str) -> str:
    """Remove ``//`` and ``/* */`` comments and trailing commas outside of strings."""
    out: list[str] = []
    i, n = 0, len(text)
    in_string = False
    while i < n:
        char = text[i]
        if in_string:
            out.append(char)
            if char == "\\" and i + 1 < n:
                out.append(text[i + 1])
                i += 1
            elif char == '"':
                in_string = False
        elif char == '"':
            in_string = True
            out.append(char)
        elif text.startswith("//", i):
            while i < n and text[i] != "\n":
                i += 1
            continue
        elif text.startswith("/*", i):
            end = text.find("*/", i + 2)
            i = n if end == -1 else end + 2
            continue
        else:
            out.append(char)
        i += 1
    return re.sub(r",(\s*[}\]])", r"\1", "".join(out))


class Workspace:
    """File operations scoped to one project root."""

    def __init__(self, root: Path, *, dry_run: bool = False) -> None:
        self.root = root.resolve()
        self.dry_run = dry_run
        self.changes: list[FileChange] = []

    # --- plumbing ------------------------------------------------------------------------

    def _path(self, relative: str) -> Path:
        path = self.root / relative
        if (
            path.is_symlink()
            or not is_within(self.root, path.parent)
            or not is_within(self.root, path)
        ):
            raise ValueError(f"Refusing to touch {relative}: it resolves outside the project.")
        return path

    def _record(self, relative: str, status: Status, detail: str = "") -> FileChange:
        change = FileChange(relative, status, detail)
        self.changes.append(change)
        return change

    def _write(self, path: Path, text: str) -> None:
        if not self.dry_run:
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(text, encoding="utf-8")

    def _delete(self, path: Path) -> None:
        if self.dry_run:
            return
        path.unlink()
        parent = path.parent
        while parent != self.root and parent.is_dir() and not any(parent.iterdir()):
            parent.rmdir()
            parent = parent.parent

    def read(self, relative: str) -> str | None:
        path = self._path(relative)
        return path.read_text(encoding="utf-8") if path.is_file() else None

    # --- files we own --------------------------------------------------------------------

    def write_owned(self, relative: str, content: str, *, force: bool = False) -> FileChange:
        """Create or update a file that carries the managed marker."""
        path = self._path(relative)
        if path.is_file():
            existing = path.read_text(encoding="utf-8")
            if existing == content:
                return self._record(relative, "unchanged")
            if OWNED_MARKER not in existing and not force:
                return self._record(
                    relative, "skipped", "exists and is not managed by aiopt (use --force)"
                )
            self._write(path, content)
            return self._record(relative, "updated")
        self._write(path, content)
        return self._record(relative, "created")

    def remove_owned(self, relative: str) -> FileChange:
        """Delete a file only if it carries the managed marker."""
        path = self._path(relative)
        if not path.is_file():
            return self._record(relative, "unchanged", "not present")
        if OWNED_MARKER not in path.read_text(encoding="utf-8"):
            return self._record(relative, "skipped", "not managed by aiopt; left in place")
        self._delete(path)
        return self._record(relative, "removed")

    # --- managed blocks inside shared files ----------------------------------------------

    def set_block(
        self,
        relative: str,
        body: str,
        markers: tuple[str, str] = MARKDOWN_BLOCK,
        *,
        validate: Callable[[str], str | None] | None = None,
    ) -> FileChange:
        """Insert or replace a delimited block, leaving the rest of the file untouched."""
        begin, end = markers
        block = f"{begin}\n{body.strip()}\n{end}"
        path = self._path(relative)
        existing = path.read_text(encoding="utf-8") if path.is_file() else None
        if existing is None:
            new, status = block + "\n", "created"
        elif begin in existing:
            if (
                existing.count(begin) != 1
                or existing.count(end) != 1
                or existing.index(end) < existing.index(begin)
            ):
                return self._record(relative, "skipped", "malformed aiopt markers; fix manually")
            start = existing.index(begin)
            stop = existing.index(end) + len(end)
            new = existing[:start] + block + existing[stop:]
            status = "unchanged" if new == existing else "updated"
        else:
            new, status = (
                existing.rstrip("\n") + ("\n\n" if existing.strip() else "") + block + "\n",
                "updated",
            )
        if validate is not None and (problem := validate(new)):
            return self._record(relative, "skipped", problem)
        if status != "unchanged":
            self._write(path, new)
        return self._record(relative, status)  # type: ignore[arg-type]

    def remove_block(self, relative: str, markers: tuple[str, str] = MARKDOWN_BLOCK) -> FileChange:
        """Remove a delimited block; delete the file if nothing else remains."""
        begin, end = markers
        path = self._path(relative)
        if not path.is_file():
            return self._record(relative, "unchanged", "not present")
        existing = path.read_text(encoding="utf-8")
        if begin not in existing or end not in existing:
            return self._record(relative, "unchanged", "no aiopt block")
        start = existing.index(begin)
        stop = existing.index(end) + len(end)
        remaining = (existing[:start].rstrip("\n") + "\n\n" + existing[stop:].lstrip("\n")).strip(
            "\n"
        )
        if not remaining:
            self._delete(path)
            return self._record(relative, "removed")
        self._write(path, remaining + "\n")
        return self._record(relative, "updated", "block removed")

    # --- JSON configuration --------------------------------------------------------------

    def edit_json(
        self,
        relative: str,
        mutate: Callable[[dict[str, Any]], bool],
        *,
        delete_if_empty: bool = False,
    ) -> FileChange:
        """Apply ``mutate`` to a JSON object file; ``mutate`` returns True if it changed it."""
        path = self._path(relative)
        existed = path.is_file()
        data: dict[str, Any] = {}
        if existed:
            text = path.read_text(encoding="utf-8")
            stripped = strip_jsonc(text)
            try:
                loaded = json.loads(stripped) if stripped.strip() else {}
            except json.JSONDecodeError as exc:
                return self._record(relative, "skipped", f"not valid JSON ({exc}); edit manually")
            if not isinstance(loaded, dict):
                return self._record(relative, "skipped", "top-level value is not an object")
            if stripped != text and stripped.strip():
                # Rewriting would silently drop the user's comments.
                return self._record(
                    relative, "skipped", "contains comments or trailing commas; edit manually"
                )
            data = loaded
        try:
            changed = mutate(data)
        except ValueError as exc:
            return self._record(relative, "skipped", str(exc))
        if not changed:
            return self._record(relative, "unchanged")
        if delete_if_empty and not data:
            if existed:
                self._delete(path)
            return self._record(relative, "removed")
        self._write(path, json.dumps(data, indent=2) + "\n")
        return self._record(relative, "updated" if existed else "created")


def validate_toml(text: str) -> str | None:
    """Return a problem description if ``text`` is not valid TOML, else None."""
    try:
        tomllib.loads(text)
    except tomllib.TOMLDecodeError as exc:
        return f"resulting TOML would be invalid ({exc}); add the server manually"
    return None
