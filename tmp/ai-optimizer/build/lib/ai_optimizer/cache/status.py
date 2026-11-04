"""Cache status inspection and clearing."""

from __future__ import annotations

import shutil
from typing import Any

from ai_optimizer.cache.db import SCHEMA_VERSION, get_meta, open_existing
from ai_optimizer.cache.indexer import pending_changes
from ai_optimizer.core.config import ProjectConfig, ProjectPaths
from ai_optimizer.core.errors import CacheError


def cache_status(paths: ProjectPaths, config: ProjectConfig) -> dict[str, Any]:
    """Describe the cache: size, health and pending (unindexed) changes."""
    status: dict[str, Any] = {
        "exists": paths.db_file.is_file(),
        "healthy": True,
        "schema_version": SCHEMA_VERSION,
        "indexed_files": 0,
        "binary_files": 0,
        "last_update": None,
        "db_size_bytes": 0,
        "pending": {"added": 0, "changed": 0, "removed": 0},
        "stale": True,
        "error": None,
    }
    if not status["exists"]:
        pending = pending_changes(paths, config)
        status["pending"] = {
            "added": len(pending.added),
            "changed": len(pending.changed),
            "removed": len(pending.removed),
        }
        return status
    status["db_size_bytes"] = paths.db_file.stat().st_size
    try:
        conn = open_existing(paths.db_file)
    except CacheError as exc:
        status.update(healthy=False, error=str(exc))
        return status
    try:
        counts = {
            row["status"]: row["n"]
            for row in conn.execute("SELECT status, count(*) AS n FROM files GROUP BY status")
        }
        status["indexed_files"] = int(counts.get("indexed", 0))
        status["binary_files"] = int(counts.get("binary", 0))
        status["last_update"] = get_meta(conn, "last_update")
        pending = pending_changes(paths, config, conn)
    finally:
        conn.close()
    status["pending"] = {
        "added": len(pending.added),
        "changed": len(pending.changed),
        "removed": len(pending.removed),
    }
    status["stale"] = pending.total > 0
    return status


def clear_cache(paths: ProjectPaths) -> bool:
    """Delete the generated cache directory. Returns True if anything was removed."""
    cache_dir = paths.cache_dir
    if cache_dir.is_symlink():
        raise CacheError(f"Refusing to clear {cache_dir}: it is a symbolic link.")
    if not cache_dir.exists():
        return False
    shutil.rmtree(cache_dir)
    return True
