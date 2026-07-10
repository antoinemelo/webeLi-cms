from __future__ import annotations

import hashlib
import json
from datetime import datetime, timezone
from pathlib import Path
from typing import Iterable


IGNORED_DIRS = {
    ".git",
    "__pycache__",
    "node_modules",
    "test-results",
    "playwright-report",
}


def fingerprint_paths(root: Path, paths: Iterable[str | Path], *, extra: Iterable[str] = ()) -> str:
    """Return a deterministic content fingerprint for files under ``paths``."""
    digest = hashlib.sha256()
    for marker in sorted(extra):
        digest.update(b"extra\0")
        digest.update(marker.encode("utf-8"))
        digest.update(b"\0")
    for relative in sorted(str(path).replace("\\", "/").strip("/") for path in paths):
        path = root / relative
        if path.is_dir():
            for file_path in _iter_files(path):
                _update_digest(root, file_path, digest)
        elif path.is_file():
            _update_digest(root, path, digest)
        else:
            digest.update(b"missing\0")
            digest.update(relative.encode("utf-8"))
            digest.update(b"\0")
    return digest.hexdigest()


def read_success(cache_file: Path, fingerprint: str) -> dict[str, object] | None:
    try:
        payload = json.loads(cache_file.read_text(encoding="utf-8"))
    except (FileNotFoundError, json.JSONDecodeError, OSError):
        return None
    if payload.get("fingerprint") != fingerprint:
        return None
    if payload.get("status") != "passed":
        return None
    return payload if isinstance(payload, dict) else None


def write_success(cache_file: Path, *, fingerprint: str, step_id: str, command: list[str]) -> None:
    cache_file.parent.mkdir(parents=True, exist_ok=True)
    payload = {
        "schema_version": 1,
        "step_id": step_id,
        "status": "passed",
        "fingerprint": fingerprint,
        "command": command,
        "completed_at": datetime.now(timezone.utc).isoformat(),
    }
    cache_file.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def _iter_files(directory: Path) -> Iterable[Path]:
    for path in sorted(directory.rglob("*")):
        if not path.is_file():
            continue
        if any(part in IGNORED_DIRS for part in path.parts):
            continue
        yield path


def _update_digest(root: Path, path: Path, digest: "hashlib._Hash") -> None:
    relative = path.relative_to(root).as_posix()
    digest.update(b"file\0")
    digest.update(relative.encode("utf-8"))
    digest.update(b"\0")
    digest.update(str(path.stat().st_size).encode("ascii"))
    digest.update(b"\0")
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    digest.update(b"\0")
