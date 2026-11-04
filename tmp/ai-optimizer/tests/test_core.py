"""Unit tests: globs, configuration, project discovery, file scanning, redaction."""

from __future__ import annotations

import os
from collections.abc import Callable
from pathlib import Path

import pytest

from ai_optimizer.core.config import (
    IndexConfig,
    ProjectConfig,
    ProjectPaths,
    default_config_yaml,
    load_config,
    parse_config,
)
from ai_optimizer.core.errors import ConfigError
from ai_optimizer.core.files import is_sensitive_name, scan_project
from ai_optimizer.core.globs import is_ignored, match_glob, parse_ignore_lines
from ai_optimizer.core.languages import classify_kind, detect_language
from ai_optimizer.core.project import find_project_root, resolve_root
from ai_optimizer.core.redact import redact

Writer = Callable[[Path, str, str], Path]


# --- globs -------------------------------------------------------------------------------


@pytest.mark.parametrize(
    ("pattern", "path", "expected"),
    [
        ("**/*.py", "a.py", True),
        ("**/*.py", "x/y/a.py", True),
        ("*.py", "x/a.py", False),
        ("src/**", "src/a/b.txt", True),
        ("**/migrations/**", "app/migrations/0001.sql", True),
        ("a?c", "abc", True),
        ("a?c", "a/c", False),
        ("[ab].txt", "a.txt", True),
        ("[!ab].txt", "a.txt", False),
    ],
)
def test_match_glob(pattern: str, path: str, expected: bool) -> None:
    assert match_glob(pattern, path) is expected


def test_gitignore_rules_negation_and_dir_only() -> None:
    rules = parse_ignore_lines(["*.log", "!keep.log", "build/", "/root-only.txt", "# comment", ""])
    assert is_ignored(rules, "a/debug.log", False)
    assert not is_ignored(rules, "keep.log", False)
    assert is_ignored(rules, "build", True)
    assert not is_ignored(rules, "build", False)
    assert is_ignored(rules, "root-only.txt", False)
    assert not is_ignored(rules, "sub/root-only.txt", False)


def test_nested_gitignore_base() -> None:
    rules = parse_ignore_lines(["*.tmp"], base="pkg")
    assert is_ignored(rules, "pkg/a.tmp", False)
    assert not is_ignored(rules, "other/a.tmp", False)


# --- configuration -----------------------------------------------------------------------


def test_default_config_round_trips() -> None:
    config = parse_config(default_config_yaml("demo"))
    assert config.project.name == "demo"
    assert config.cache.auto_refresh == "refresh"


def test_empty_config_uses_defaults() -> None:
    assert parse_config("") == ProjectConfig()


@pytest.mark.parametrize(
    "text",
    [
        "unknown_key: 1",
        "index:\n  max_file_size_bytes: -5",
        "cache:\n  auto_refresh: sometimes",
        "tasks:\n  default_timeout_seconds: 0",
        "schema_version: 2",
        "- a list",
        "key: [unclosed",
    ],
)
def test_invalid_config_is_rejected(text: str) -> None:
    with pytest.raises(ConfigError):
        parse_config(text)


def test_config_error_names_the_field() -> None:
    with pytest.raises(ConfigError, match=r"cache\.auto_refresh"):
        parse_config("cache:\n  auto_refresh: nope")


def test_load_config_without_file_returns_defaults(tmp_path: Path) -> None:
    assert load_config(ProjectPaths(tmp_path)) == ProjectConfig()


# --- project discovery -------------------------------------------------------------------


def test_find_project_root_walks_up(tmp_path: Path) -> None:
    (tmp_path / "pyproject.toml").write_text("")
    nested = tmp_path / "a" / "b"
    nested.mkdir(parents=True)
    assert find_project_root(nested) == tmp_path.resolve()


def test_find_project_root_falls_back_to_start(tmp_path: Path) -> None:
    nested = tmp_path / "empty"
    nested.mkdir()
    assert find_project_root(nested) == nested.resolve()


def test_resolve_root_rejects_missing_directory(tmp_path: Path) -> None:
    with pytest.raises(ConfigError):
        resolve_root(tmp_path / "missing")


# --- scanning ----------------------------------------------------------------------------


def paths_of(root: Path, **overrides: object) -> list[str]:
    index = IndexConfig.model_validate(overrides)
    return [f.path for f in scan_project(root, index).files]


def test_scan_skips_sensitive_files_and_dirs(tmp_path: Path, write: Writer) -> None:
    write(tmp_path, "app.py", "x = 1")
    for name in (".env", ".env.local", "server.pem", "id_rsa", "credentials.json", "my.key"):
        write(tmp_path, name, "secret")
    write(tmp_path, ".ssh/config", "Host x")
    write(tmp_path, ".aws/credentials", "[default]")
    assert paths_of(tmp_path) == ["app.py"]


def test_scan_honors_gitignore_including_nested(tmp_path: Path, write: Writer) -> None:
    write(tmp_path, ".gitignore", "*.log\nout/\n")
    write(tmp_path, "a.py", "")
    write(tmp_path, "run.log", "")
    write(tmp_path, "out/b.py", "")
    write(tmp_path, "pkg/.gitignore", "local.py\n")
    write(tmp_path, "pkg/local.py", "")
    write(tmp_path, "pkg/keep.py", "")
    write(tmp_path, "other/local.py", "")
    assert paths_of(tmp_path) == [
        ".gitignore",
        "a.py",
        "other/local.py",
        "pkg/.gitignore",
        "pkg/keep.py",
    ]
    assert "run.log" in paths_of(tmp_path, respect_gitignore=False)


def test_scan_never_includes_tool_directories(tmp_path: Path, write: Writer) -> None:
    write(tmp_path, ".git/config", "")
    write(tmp_path, ".ai-optimizer/config.yaml", "")
    write(tmp_path, "node_modules/x/index.js", "")
    write(tmp_path, "a.py", "")
    assert paths_of(tmp_path) == ["a.py"]
    assert paths_of(tmp_path, exclude=[]) == ["a.py", "node_modules/x/index.js"]


def test_scan_skips_large_files(tmp_path: Path, write: Writer) -> None:
    write(tmp_path, "big.txt", "x" * 2000)
    write(tmp_path, "small.txt", "x")
    result = scan_project(tmp_path, IndexConfig(max_file_size_bytes=1000))
    assert [f.path for f in result.files] == ["small.txt"]
    assert result.skipped["too_large"] == 1


def test_scan_does_not_follow_symlinks_outside_root(tmp_path: Path, write: Writer) -> None:
    outside = tmp_path / "outside"
    write(outside, "secret.txt", "top secret")
    root = tmp_path / "root"
    write(root, "a.py", "")
    os.symlink(outside / "secret.txt", root / "link.txt")
    os.symlink(outside, root / "linkdir")
    inside = root / "inside.txt"
    inside.write_text("ok")
    os.symlink(inside, root / "inside-link.txt")
    result = scan_project(root, IndexConfig())
    found = [f.path for f in result.files]
    assert "link.txt" not in found
    assert not any(p.startswith("linkdir") for p in found)
    assert "inside-link.txt" in found
    assert result.skipped["symlink_outside_root"] >= 2


def test_extra_sensitive_patterns(tmp_path: Path, write: Writer) -> None:
    write(tmp_path, "tokens.cfg", "")
    write(tmp_path, "a.py", "")
    assert paths_of(tmp_path, extra_sensitive=["tokens.*"]) == ["a.py"]
    assert is_sensitive_name("SECRETS.yaml")


def test_language_and_kind_classification() -> None:
    assert detect_language("a/b.ts") == "typescript"
    assert detect_language("LICENSE") is None
    assert classify_kind("tests/test_a.py", "python") == "test"
    assert classify_kind("web/app.spec.ts", "typescript") == "test"
    assert classify_kind("docs/guide.md", "markdown") == "doc"
    assert classify_kind("pyproject.toml", "toml") == "config"
    assert classify_kind("src/a.py", "python") == "source"


# --- redaction ---------------------------------------------------------------------------


@pytest.mark.parametrize(
    ("text", "secret"),
    [
        ("password = 'hunter2'", "hunter2"),
        ("API_KEY=abcdef123456", "abcdef123456"),
        ("SECRET_KEY=abc123", "abc123"),
        ("token: ghp_abcdefghijklmnopqrstuvwxyz0123", "ghp_abcdefghijklmnopqrstuvwxyz0123"),
        ("key AKIAABCDEFGHIJKLMNOP here", "AKIAABCDEFGHIJKLMNOP"),
        ("Authorization: Bearer abcdefgh12345678", "abcdefgh12345678"),
        ("-----BEGIN RSA PRIVATE KEY-----\nMIIabc\n-----END RSA PRIVATE KEY-----", "MIIabc"),
    ],
)
def test_redact_removes_secrets(text: str, secret: str) -> None:
    assert secret not in redact(text)


@pytest.mark.parametrize(
    "text",
    ["class PasswordResetService: pass", "def reset_password(user, token):", "plain text"],
)
def test_redact_leaves_identifiers_alone(text: str) -> None:
    assert redact(text) == text
