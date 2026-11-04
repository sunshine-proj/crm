"""Trust records binding a project to a fingerprint of its executable configuration.

Records live in the user's state directory, not in the repository, so a cloned
project cannot trust itself. Any change to config, tasks or profiles invalidates trust.
"""

from __future__ import annotations

import hashlib
import json
import os
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

from ai_optimizer.core.config import ProjectPaths
from ai_optimizer.core.errors import ConfigError
from ai_optimizer.tasks.permissions import user_state_dir

TRUST_FILE = "trust.json"
TRUST_VERSION = 1


def project_fingerprint(paths: ProjectPaths) -> str:
    """SHA-256 over the relative path and content hash of every governing file."""
    files: list[Path] = []
    if paths.config_file.is_file():
        files.append(paths.config_file)
    for directory in (paths.tasks_dir, paths.profiles_dir):
        if directory.is_dir():
            files += sorted(
                f for f in directory.iterdir() if f.is_file() and f.suffix in {".yaml", ".yml"}
            )
    digest = hashlib.sha256()
    for file in files:
        relative = file.relative_to(paths.root).as_posix()
        digest.update(relative.encode() + b"\0")
        digest.update(hashlib.sha256(file.read_bytes()).hexdigest().encode() + b"\n")
    return digest.hexdigest()


def _store_file() -> Path:
    return user_state_dir() / TRUST_FILE


def _load_store() -> dict[str, Any]:
    file = _store_file()
    if not file.is_file():
        return {"version": TRUST_VERSION, "projects": {}}
    try:
        data = json.loads(file.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {"version": TRUST_VERSION, "projects": {}}
    if not isinstance(data, dict) or not isinstance(data.get("projects"), dict):
        return {"version": TRUST_VERSION, "projects": {}}
    return data


def _save_store(data: dict[str, Any]) -> None:
    file = _store_file()
    try:
        file.parent.mkdir(parents=True, exist_ok=True)
        temp = file.with_suffix(".tmp")
        temp.write_text(json.dumps(data, indent=2, sort_keys=True) + "\n", encoding="utf-8")
        os.replace(temp, file)
    except OSError as exc:
        raise ConfigError(f"Cannot write trust record {file}: {exc}") from exc


def is_trusted(paths: ProjectPaths) -> bool:
    """True if the current configuration fingerprint matches a recorded trust."""
    record = _load_store()["projects"].get(str(paths.root))
    return isinstance(record, dict) and record.get("fingerprint") == project_fingerprint(paths)


def trust_project(paths: ProjectPaths) -> str:
    """Record trust for the project's current configuration; returns the fingerprint."""
    fingerprint = project_fingerprint(paths)
    store = _load_store()
    store["projects"][str(paths.root)] = {
        "fingerprint": fingerprint,
        "trusted_at": datetime.now(UTC).isoformat(timespec="seconds"),
    }
    _save_store(store)
    return fingerprint


def revoke_trust(paths: ProjectPaths) -> bool:
    """Remove trust for the project. Returns True if a record existed."""
    store = _load_store()
    existed = store["projects"].pop(str(paths.root), None) is not None
    if existed:
        _save_store(store)
    return existed
