"""Shared fixtures. Every test uses temporary directories and isolated user-level state."""

from __future__ import annotations

import os
import sys
from collections.abc import Callable
from pathlib import Path

import pytest

from ai_optimizer.core.project import Project, load_project

PYTHON_NAME = Path(sys.executable).name


@pytest.fixture(autouse=True)
def isolated_user_dirs(
    tmp_path_factory: pytest.TempPathFactory, monkeypatch: pytest.MonkeyPatch
) -> Path:
    """Point user-level config/trust at temp dirs so tests never touch the real ones."""
    base = tmp_path_factory.mktemp("user")
    monkeypatch.setenv("AIOPT_CONFIG_DIR", str(base / "config"))
    monkeypatch.setenv("AIOPT_STATE_DIR", str(base / "state"))
    monkeypatch.setenv("PATH", f"{Path(sys.executable).parent}{os.pathsep}{os.environ['PATH']}")
    return base


@pytest.fixture
def write() -> Callable[[Path, str, str], Path]:
    """Write a file (creating parent directories) relative to a root."""

    def _write(root: Path, relative: str, content: str = "") -> Path:
        target = root / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(content, encoding="utf-8")
        return target

    return _write


@pytest.fixture
def project_dir(tmp_path: Path, write: Callable[[Path, str, str], Path]) -> Path:
    """A small Python project with source, tests, docs, a secret file and conventions."""
    root = tmp_path / "shop"
    write(
        root,
        "pyproject.toml",
        '[project]\nname = "shop"\ndependencies = ["flask>=3", "requests"]\n'
        '[project.optional-dependencies]\ndev = ["pytest", "ruff"]\n'
        '[tool.pytest.ini_options]\ntestpaths = ["tests"]\n',
    )
    write(
        root,
        "src/shop/auth.py",
        "import flask\n\n\ndef reset_password(user, token):\n"
        '    """Send a password reset email."""\n    return token\n\n\n'
        "class PasswordResetService:\n    pass\n",
    )
    write(root, "src/shop/cart.py", "def add_item(cart, item):\n    cart.append(item)\n")
    write(root, "tests/test_auth.py", "def test_reset_password():\n    assert True\n")
    write(root, "docs/auth.md", "# Auth\n\nPassword reset flow documentation.\n")
    write(root, "AGENTS.md", "Use type hints.\n")
    write(root, ".env", "SECRET_KEY=hunter2\n")
    return root


@pytest.fixture
def project(project_dir: Path) -> Project:
    return load_project(project_dir)


@pytest.fixture
def allow_python(isolated_user_dirs: Path) -> None:
    """Allow running scripts with the current interpreter (inline code stays denied)."""
    config = isolated_user_dirs / "config"
    config.mkdir(parents=True, exist_ok=True)
    (config / "allowlist.yaml").write_text(
        f"commands:\n  - name: {PYTHON_NAME}\n", encoding="utf-8"
    )
