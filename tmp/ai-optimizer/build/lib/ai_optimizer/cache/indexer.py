"""Incremental, transactional file indexing."""

from __future__ import annotations

import hashlib
import json
import sqlite3
import time
from dataclasses import asdict, dataclass, field
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

from ai_optimizer.cache.db import get_meta, open_cache, open_existing, set_meta
from ai_optimizer.cache.symbols import extract_symbols
from ai_optimizer.core.config import ProjectConfig, ProjectPaths
from ai_optimizer.core.errors import CacheError
from ai_optimizer.core.files import FileInfo, scan_project
from ai_optimizer.core.languages import classify_kind, detect_language
from ai_optimizer.core.log import get_logger

log = get_logger("indexer")

BINARY_PROBE_BYTES = 8192


@dataclass(slots=True)
class UpdateResult:
    """Outcome of an index update."""

    added: list[str] = field(default_factory=list)
    changed: list[str] = field(default_factory=list)
    removed: list[str] = field(default_factory=list)
    renamed: list[tuple[str, str]] = field(default_factory=list)
    unchanged: int = 0
    touched: int = 0
    rehashed: int = 0
    hash_skipped: int = 0
    binary: int = 0
    indexed_total: int = 0
    rebuilt: bool = False
    skipped: dict[str, int] = field(default_factory=dict)
    duration_seconds: float = 0.0

    @property
    def has_changes(self) -> bool:
        return bool(self.added or self.changed or self.removed or self.renamed)

    def to_dict(self) -> dict[str, Any]:
        data = asdict(self)
        data["renamed"] = [list(pair) for pair in self.renamed]
        data["has_changes"] = self.has_changes
        return data


@dataclass(slots=True)
class PendingChanges:
    """Differences between the filesystem and the index, using metadata only."""

    added: list[str] = field(default_factory=list)
    changed: list[str] = field(default_factory=list)
    removed: list[str] = field(default_factory=list)

    @property
    def total(self) -> int:
        return len(self.added) + len(self.changed) + len(self.removed)


def _read_file(path: Path) -> tuple[bytes | None, str | None]:
    """Return (content, sha256), or (None, None) for binary files."""
    data = path.read_bytes()
    if b"\0" in data[:BINARY_PROBE_BYTES]:
        return None, None
    return data, hashlib.sha256(data).hexdigest()


def _insert_fts(
    conn: sqlite3.Connection, file_id: int, path: str, language: str | None, data: bytes
) -> None:
    text = data.decode("utf-8", errors="replace")
    conn.execute("DELETE FROM file_fts WHERE rowid = ?", (file_id,))
    conn.execute(
        "INSERT INTO file_fts(rowid, path, symbols, content) VALUES (?, ?, ?, ?)",
        (file_id, path, extract_symbols(language, text), text),
    )


def update_index(paths: ProjectPaths, config: ProjectConfig, *, full: bool = False) -> UpdateResult:
    """Bring the index in line with the filesystem.

    Files are rehashed only when size or modification time changed (or ``full``).
    All writes happen in one transaction, so an interrupted run leaves the previous
    consistent state in place.
    """
    started = time.perf_counter()
    scan = scan_project(paths.root, config.index)
    conn, rebuilt = open_cache(paths.db_file)
    result = UpdateResult(rebuilt=rebuilt, skipped=dict(sorted(scan.skipped.items())))
    try:
        existing = {row["path"]: row for row in conn.execute("SELECT * FROM files")}
        scanned = {info.path: info for info in scan.files}
        removed_rows = {p: existing[p] for p in sorted(set(existing) - set(scanned))}
        removed_by_sha: dict[str, list[str]] = {}
        for path, row in removed_rows.items():
            if row["sha256"]:
                removed_by_sha.setdefault(row["sha256"], []).append(path)

        conn.execute("BEGIN IMMEDIATE")
        try:
            for info in scan.files:
                _process_file(
                    conn, paths.root, info, existing.get(info.path), removed_rows,
                    removed_by_sha, result, full,
                )  # fmt: skip
            for path in sorted(removed_rows):
                row = removed_rows[path]
                conn.execute("DELETE FROM file_fts WHERE rowid = ?", (row["id"],))
                conn.execute("DELETE FROM files WHERE id = ?", (row["id"],))
                result.removed.append(path)
            changes = {
                "added": result.added,
                "changed": result.changed,
                "removed": result.removed,
                "renamed": [new for _, new in result.renamed],
            }
            set_meta(conn, "last_update", datetime.now(UTC).isoformat(timespec="seconds"))
            if result.has_changes and existing:  # the first index is a baseline, not a change
                set_meta(conn, "last_changed", json.dumps(changes))
            conn.execute("COMMIT")
        except BaseException:
            conn.execute("ROLLBACK")
            raise
        row = conn.execute("SELECT count(*) AS n FROM files WHERE status = 'indexed'").fetchone()
        result.indexed_total = int(row["n"])
    finally:
        conn.close()
    result.duration_seconds = round(time.perf_counter() - started, 4)
    log.info("Index updated: %s", result.to_dict())
    return result


def _process_file(
    conn: sqlite3.Connection,
    root: Path,
    info: FileInfo,
    row: sqlite3.Row | None,
    removed_rows: dict[str, sqlite3.Row],
    removed_by_sha: dict[str, list[str]],
    result: UpdateResult,
    full: bool,
) -> None:
    if (
        row is not None
        and not full
        and row["size"] == info.size
        and row["mtime_ns"] == info.mtime_ns
    ):
        result.unchanged += 1
        result.hash_skipped += 1
        if row["status"] == "binary":
            result.binary += 1
        return
    try:
        data, sha = _read_file(root / info.path)
    except OSError as exc:
        log.warning("Cannot read %s: %s", info.path, exc)
        result.skipped["unreadable"] = result.skipped.get("unreadable", 0) + 1
        return
    result.rehashed += 1
    language = detect_language(info.path)
    kind = classify_kind(info.path, language)
    status = "binary" if data is None else "indexed"
    if status == "binary":
        result.binary += 1

    if row is not None:
        if sha == row["sha256"] and status == row["status"]:
            conn.execute(
                "UPDATE files SET size = ?, mtime_ns = ? WHERE id = ?",
                (info.size, info.mtime_ns, row["id"]),
            )
            result.touched += 1
            return
        conn.execute(
            "UPDATE files SET size=?, mtime_ns=?, sha256=?, status=?, kind=?, language=? "
            "WHERE id=?",
            (info.size, info.mtime_ns, sha, status, kind, language, row["id"]),
        )
        _sync_fts(conn, row["id"], info.path, language, data)
        result.changed.append(info.path)
        return

    renamed_from = removed_by_sha.get(sha, []) if sha else []
    if renamed_from:
        old_path = renamed_from.pop(0)
        old_row = removed_rows.pop(old_path)
        conn.execute(
            "UPDATE files SET path=?, size=?, mtime_ns=?, kind=?, language=? WHERE id=?",
            (info.path, info.size, info.mtime_ns, kind, language, old_row["id"]),
        )
        _sync_fts(conn, old_row["id"], info.path, language, data)
        result.renamed.append((old_path, info.path))
        return

    cursor = conn.execute(
        "INSERT INTO files(path, size, mtime_ns, sha256, status, kind, language) "
        "VALUES (?, ?, ?, ?, ?, ?, ?)",
        (info.path, info.size, info.mtime_ns, sha, status, kind, language),
    )
    if cursor.lastrowid is None:
        raise CacheError(f"Could not insert {info.path}")
    _sync_fts(conn, cursor.lastrowid, info.path, language, data)
    result.added.append(info.path)


def _sync_fts(
    conn: sqlite3.Connection, file_id: int, path: str, language: str | None, data: bytes | None
) -> None:
    if data is None:
        conn.execute("DELETE FROM file_fts WHERE rowid = ?", (file_id,))
    else:
        _insert_fts(conn, file_id, path, language, data)


def pending_changes(
    paths: ProjectPaths, config: ProjectConfig, conn: sqlite3.Connection | None = None
) -> PendingChanges:
    """Compare filesystem metadata with the index without reading file contents."""
    scan = scan_project(paths.root, config.index)
    own = conn is None
    if conn is None:
        if not paths.db_file.is_file():
            return PendingChanges(added=[info.path for info in scan.files])
        conn = open_existing(paths.db_file)
    try:
        existing = {
            row["path"]: (row["size"], row["mtime_ns"])
            for row in conn.execute("SELECT path, size, mtime_ns FROM files")
        }
    finally:
        if own:
            conn.close()
    pending = PendingChanges()
    scanned: set[str] = set()
    for info in scan.files:
        scanned.add(info.path)
        known = existing.get(info.path)
        if known is None:
            pending.added.append(info.path)
        elif known != (info.size, info.mtime_ns):
            pending.changed.append(info.path)
    pending.removed = sorted(set(existing) - scanned)
    return pending


def last_changed_files(conn: sqlite3.Connection) -> list[str]:
    """Files touched by the most recent update that changed anything."""
    raw = get_meta(conn, "last_changed")
    if not raw:
        return []
    try:
        data = json.loads(raw)
    except json.JSONDecodeError:
        return []
    paths = [*data.get("added", []), *data.get("changed", []), *data.get("renamed", [])]
    return sorted(set(paths))
