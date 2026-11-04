"""Cache tests: incremental updates, invalidation, recovery and status."""

from __future__ import annotations

import os
import sqlite3
from collections.abc import Callable
from pathlib import Path

import pytest

from ai_optimizer.cache import indexer
from ai_optimizer.cache.db import SCHEMA_VERSION, open_cache, open_existing
from ai_optimizer.cache.indexer import last_changed_files, pending_changes, update_index
from ai_optimizer.cache.status import cache_status, clear_cache
from ai_optimizer.core.config import ProjectConfig, ProjectPaths
from ai_optimizer.core.errors import CacheError

Writer = Callable[[Path, str, str], Path]


@pytest.fixture
def paths(project_dir: Path) -> ProjectPaths:
    return ProjectPaths(project_dir)


@pytest.fixture
def config() -> ProjectConfig:
    return ProjectConfig()


def indexed_paths(paths: ProjectPaths) -> set[str]:
    conn = open_existing(paths.db_file)
    try:
        return {r["path"] for r in conn.execute("SELECT path FROM files WHERE status='indexed'")}
    finally:
        conn.close()


def test_initial_index(paths: ProjectPaths, config: ProjectConfig) -> None:
    result = update_index(paths, config)
    assert sorted(result.added) == [
        "AGENTS.md", "docs/auth.md", "pyproject.toml", "src/shop/auth.py", "src/shop/cart.py",
        "tests/test_auth.py",
    ]  # fmt: skip
    assert result.rehashed == 6 and result.hash_skipped == 0
    assert result.skipped["sensitive"] == 1


def test_sensitive_files_are_never_indexed(paths: ProjectPaths, config: ProjectConfig) -> None:
    update_index(paths, config)
    assert ".env" not in indexed_paths(paths)
    conn = open_existing(paths.db_file)
    try:
        hits = conn.execute("SELECT * FROM file_fts WHERE file_fts MATCH 'hunter2'").fetchall()
    finally:
        conn.close()
    assert hits == []


def test_unchanged_update_rehashes_nothing(paths: ProjectPaths, config: ProjectConfig) -> None:
    update_index(paths, config)
    second = update_index(paths, config)
    assert second.rehashed == 0
    assert second.hash_skipped == 6
    assert not second.has_changes


def test_changed_added_removed_detected(
    paths: ProjectPaths, config: ProjectConfig, write: Writer
) -> None:
    update_index(paths, config)
    write(paths.root, "src/shop/cart.py", "def add_item(cart, item):\n    cart.insert(0, item)\n")
    write(paths.root, "src/shop/new.py", "def new(): pass\n")
    (paths.root / "docs/auth.md").unlink()
    result = update_index(paths, config)
    assert result.changed == ["src/shop/cart.py"]
    assert result.added == ["src/shop/new.py"]
    assert result.removed == ["docs/auth.md"]
    assert result.rehashed == 2
    assert "docs/auth.md" not in indexed_paths(paths)
    assert sorted(last_changed_files(open_existing(paths.db_file))) == [
        "src/shop/cart.py", "src/shop/new.py",
    ]  # fmt: skip


def test_rename_detected_by_hash(paths: ProjectPaths, config: ProjectConfig) -> None:
    update_index(paths, config)
    os.rename(paths.root / "src/shop/cart.py", paths.root / "src/shop/basket.py")
    result = update_index(paths, config)
    assert result.renamed == [("src/shop/cart.py", "src/shop/basket.py")]
    assert not result.added and not result.removed
    assert "src/shop/basket.py" in indexed_paths(paths)
    conn = open_existing(paths.db_file)
    try:
        row = conn.execute("SELECT path FROM file_fts WHERE file_fts MATCH 'add_item'").fetchone()
    finally:
        conn.close()
    assert row["path"] == "src/shop/basket.py"


def test_mtime_only_change_is_touched_not_changed(
    paths: ProjectPaths, config: ProjectConfig
) -> None:
    update_index(paths, config)
    target = paths.root / "src/shop/cart.py"
    stat = target.stat()
    os.utime(target, ns=(stat.st_atime_ns, stat.st_mtime_ns + 5_000_000_000))
    result = update_index(paths, config)
    assert result.rehashed == 1
    assert result.touched == 1
    assert not result.has_changes
    assert update_index(paths, config).rehashed == 0


def test_full_update_rehashes_everything(paths: ProjectPaths, config: ProjectConfig) -> None:
    update_index(paths, config)
    assert update_index(paths, config, full=True).rehashed == 6


def test_binary_files_are_recorded_but_not_indexed(
    paths: ProjectPaths, config: ProjectConfig
) -> None:
    (paths.root / "logo.png").write_bytes(b"\x89PNG\0\0data")
    first = update_index(paths, config)
    assert "logo.png" in first.added
    assert "logo.png" not in indexed_paths(paths)
    assert update_index(paths, config).rehashed == 0


def test_interrupted_update_leaves_previous_state(
    paths: ProjectPaths, config: ProjectConfig, write: Writer, monkeypatch: pytest.MonkeyPatch
) -> None:
    update_index(paths, config)
    before = indexed_paths(paths)
    write(paths.root, "a_new.py", "x = 1\n")
    write(paths.root, "b_new.py", "y = 2\n")
    calls = {"n": 0}
    original = indexer._sync_fts

    def flaky(*args: object, **kwargs: object) -> None:
        calls["n"] += 1
        if calls["n"] == 2:
            raise RuntimeError("simulated crash")
        original(*args, **kwargs)  # type: ignore[arg-type]

    monkeypatch.setattr(indexer, "_sync_fts", flaky)
    with pytest.raises(RuntimeError):
        update_index(paths, config)
    monkeypatch.undo()
    assert indexed_paths(paths) == before
    recovered = update_index(paths, config)
    assert sorted(recovered.added) == ["a_new.py", "b_new.py"]


def test_corrupt_database_is_rebuilt(paths: ProjectPaths, config: ProjectConfig) -> None:
    update_index(paths, config)
    paths.db_file.write_bytes(b"this is not a sqlite database" * 100)
    (paths.db_file.parent / "index.db-wal").unlink(missing_ok=True)
    status = cache_status(paths, config)
    assert status["healthy"] is False
    result = update_index(paths, config)
    assert result.rebuilt is True
    assert len(result.added) == 6
    assert cache_status(paths, config)["healthy"] is True


def test_incompatible_schema_is_rebuilt(paths: ProjectPaths, config: ProjectConfig) -> None:
    update_index(paths, config)
    conn = sqlite3.connect(paths.db_file)
    conn.execute(f"PRAGMA user_version={SCHEMA_VERSION + 5}")
    conn.close()
    with pytest.raises(CacheError):
        open_existing(paths.db_file)
    conn2, rebuilt = open_cache(paths.db_file)
    conn2.close()
    assert rebuilt is True


def test_status_and_pending_changes(
    paths: ProjectPaths, config: ProjectConfig, write: Writer
) -> None:
    missing = cache_status(paths, config)
    assert missing["exists"] is False and missing["stale"] is True
    update_index(paths, config)
    fresh = cache_status(paths, config)
    assert fresh["indexed_files"] == 6 and fresh["stale"] is False
    write(paths.root, "extra.py", "z = 3\n")
    (paths.root / "src/shop/cart.py").write_text("changed\n")
    pending = pending_changes(paths, config)
    assert pending.added == ["extra.py"]
    assert pending.changed == ["src/shop/cart.py"]
    assert cache_status(paths, config)["stale"] is True


def test_clear_cache(paths: ProjectPaths, config: ProjectConfig) -> None:
    update_index(paths, config)
    assert clear_cache(paths) is True
    assert not paths.cache_dir.exists()
    assert clear_cache(paths) is False
    assert (paths.root / "src/shop/auth.py").exists()


def test_clear_cache_refuses_symlinked_directory(paths: ProjectPaths, tmp_path: Path) -> None:
    target = tmp_path / "precious"
    target.mkdir()
    (target / "keep.txt").write_text("keep")
    paths.base.mkdir()
    os.symlink(target, paths.cache_dir)
    with pytest.raises(CacheError):
        clear_cache(paths)
    assert (target / "keep.txt").exists()


def test_concurrent_connections_do_not_conflict(paths: ProjectPaths, config: ProjectConfig) -> None:
    update_index(paths, config)
    reader = open_existing(paths.db_file)
    try:
        reader.execute("SELECT count(*) FROM files").fetchone()
        update_index(paths, config, full=True)
        assert reader.execute("SELECT count(*) FROM files").fetchone()[0] == 6
    finally:
        reader.close()
