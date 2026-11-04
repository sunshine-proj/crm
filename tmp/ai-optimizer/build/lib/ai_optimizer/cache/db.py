"""SQLite connection handling, schema migrations and corruption recovery."""

from __future__ import annotations

import sqlite3
from pathlib import Path

from ai_optimizer.core.errors import CacheError
from ai_optimizer.core.log import get_logger

log = get_logger("cache")

# Migrations are keyed by the version they upgrade *from*.
MIGRATIONS: dict[int, str] = {
    0: """
    CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);
    CREATE TABLE files (
        id INTEGER PRIMARY KEY,
        path TEXT NOT NULL UNIQUE,
        size INTEGER NOT NULL,
        mtime_ns INTEGER NOT NULL,
        sha256 TEXT,
        status TEXT NOT NULL,
        kind TEXT NOT NULL,
        language TEXT
    );
    CREATE VIRTUAL TABLE file_fts USING fts5(
        path, symbols, content, tokenize = 'unicode61'
    );
    """,
}
SCHEMA_VERSION = len(MIGRATIONS)


class IncompatibleCacheError(CacheError):
    """The database has a schema this version cannot use."""


def _connect(db_file: Path) -> sqlite3.Connection:
    conn = sqlite3.connect(db_file, timeout=30, isolation_level=None)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode=WAL")
    conn.execute("PRAGMA busy_timeout=30000")
    return conn


def _ensure_schema(conn: sqlite3.Connection) -> None:
    version = int(conn.execute("PRAGMA user_version").fetchone()[0])
    if version > SCHEMA_VERSION:
        raise IncompatibleCacheError(f"Cache schema {version} is newer than {SCHEMA_VERSION}.")
    while version < SCHEMA_VERSION:
        conn.executescript(
            "BEGIN;" + MIGRATIONS[version] + f"PRAGMA user_version={version + 1};COMMIT;"
        )
        version += 1
    # Cheap sanity probe: raises DatabaseError on a damaged file.
    conn.execute("SELECT count(*) FROM files").fetchone()
    conn.execute("SELECT count(*) FROM file_fts").fetchone()


def _remove_db_files(db_file: Path) -> None:
    for suffix in ("", "-wal", "-shm"):
        Path(str(db_file) + suffix).unlink(missing_ok=True)


def open_cache(db_file: Path) -> tuple[sqlite3.Connection, bool]:
    """Open (creating if needed) the cache, rebuilding it if corrupt or incompatible.

    Returns the connection and whether the cache had to be rebuilt.
    """
    db_file.parent.mkdir(parents=True, exist_ok=True)
    try:
        conn = _connect(db_file)
        try:
            _ensure_schema(conn)
        except (sqlite3.DatabaseError, CacheError):
            conn.close()
            raise
        return conn, False
    except (sqlite3.DatabaseError, CacheError) as exc:
        log.warning("Rebuilding cache (%s)", exc)
        _remove_db_files(db_file)
        conn = _connect(db_file)
        _ensure_schema(conn)
        return conn, True


def open_existing(db_file: Path) -> sqlite3.Connection:
    """Open an existing cache without rebuilding; raise CacheError if missing or unusable."""
    if not db_file.is_file():
        raise CacheError("Cache has not been built. Run `aiopt cache update`.")
    try:
        conn = _connect(db_file)
        try:
            _ensure_schema(conn)
        except (sqlite3.DatabaseError, CacheError):
            conn.close()
            raise
    except (sqlite3.DatabaseError, CacheError) as exc:
        raise CacheError(
            f"Cache is corrupt or incompatible ({exc}). Run `aiopt cache update` to rebuild."
        ) from exc
    return conn


def get_meta(conn: sqlite3.Connection, key: str) -> str | None:
    row = conn.execute("SELECT value FROM meta WHERE key = ?", (key,)).fetchone()
    return None if row is None else str(row["value"])


def set_meta(conn: sqlite3.Connection, key: str, value: str) -> None:
    conn.execute(
        "INSERT INTO meta(key, value) VALUES(?, ?) "
        "ON CONFLICT(key) DO UPDATE SET value = excluded.value",
        (key, value),
    )
