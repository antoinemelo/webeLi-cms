#!/usr/bin/env python3
from __future__ import annotations

import fnmatch
import hashlib
import json
import os
import platform
import shutil
import subprocess
import time
import uuid
from dataclasses import dataclass, asdict
from pathlib import Path
from typing import Iterable

from tools.python.cms.runtime import resolve_php_binary

ROOT = Path(__file__).resolve().parents[3]
DEFAULT_PROTECTED_PREFIXES = ("vendor/", "backend/vendor/")
DEFAULT_RELEASE_MARKER_DIR = "storage/deployments"


def load_ops_env(root: Path = ROOT) -> dict[str, str]:
    env_path = root / "ops" / ".env"
    values: dict[str, str] = {}
    if not env_path.exists():
        return values
    for raw_line in env_path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        if not key:
            continue
        values[key] = value.strip().strip("\"'")
    return values


def configured_dependency_paths(root: Path = ROOT) -> dict[str, Path]:
    env_values = load_ops_env(root)
    node_value = env_values.get("APP_VUE_NODE_MODULES_PATH") or env_values.get("APP_NODE_MODULES_PATH") or "./vendor/node_modules/"
    twig_value = env_values.get("APP_TWIG_VENDOR_PATH") or env_values.get("APP_TWIG_PATH") or "./vendor/twig/"

    def resolve(value: str) -> Path:
        path = Path(value).expanduser()
        if not path.is_absolute():
            path = root / value.lstrip("./")
        return path.resolve()

    return {
        "vue_node_modules": resolve(node_value),
        "twig_vendor": resolve(twig_value),
    }


def dependency_prefixes(root: Path = ROOT) -> tuple[str, ...]:
    prefixes = {"vendor/", "backend/vendor/", "frontend/admin-vue/node_modules/"}
    for path in configured_dependency_paths(root).values():
        try:
            rel = path.relative_to(root.resolve()).as_posix().rstrip("/")
        except ValueError:
            continue
        if rel:
            prefixes.add(rel + "/")
    return tuple(sorted(prefixes))


def dependency_exclude_patterns(root: Path = ROOT) -> list[str]:
    patterns = {"vendor", "vendor/*", "vendor/**", "backend/vendor", "backend/vendor/*", "backend/vendor/**", "frontend/admin-vue/node_modules", "frontend/admin-vue/node_modules/*", "frontend/admin-vue/node_modules/**"}
    for prefix in dependency_prefixes(root):
        rel = prefix.rstrip("/")
        patterns.add(rel)
        patterns.add(rel + "/*")
        patterns.add(rel + "/**")
    return sorted(patterns)


def utc_now() -> str:
    return time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())


def ensure_parent(path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)


def read_json(path: Path, default=None):
    if not path.exists():
        return default
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, data: dict | list) -> None:
    ensure_parent(path)
    path.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def append_ndjson(path: Path, data: dict) -> None:
    ensure_parent(path)
    with path.open("a", encoding="utf-8") as handle:
        handle.write(json.dumps(data, ensure_ascii=False, sort_keys=True) + "\n")


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def should_exclude(rel_path: str, patterns: Iterable[str]) -> bool:
    normalized = rel_path.replace("\\", "/")
    return any(fnmatch.fnmatch(normalized, pattern) for pattern in patterns)


def is_protected(rel_path: str, protected_prefixes: Iterable[str] = DEFAULT_PROTECTED_PREFIXES) -> bool:
    normalized = rel_path.replace("\\", "/")
    return any(normalized.startswith(prefix) for prefix in protected_prefixes)


def collect_files(root: Path) -> dict[str, dict]:
    files: dict[str, dict] = {}
    for item in sorted(root.rglob("*")):
        if not item.is_file():
            continue
        rel = item.relative_to(root).as_posix()
        stat = item.stat()
        files[rel] = {
            "sha256": sha256_file(item),
            "size": stat.st_size,
            "mtime": int(stat.st_mtime),
        }
    return files


def compute_delta(old_files: dict[str, dict], new_files: dict[str, dict], protected_prefixes: Iterable[str] = DEFAULT_PROTECTED_PREFIXES) -> tuple[list[str], list[str], list[str]]:
    uploads_new: list[str] = []
    uploads_changed: list[str] = []
    removals: list[str] = []

    for rel, meta in new_files.items():
        if is_protected(rel, protected_prefixes):
            continue
        previous = old_files.get(rel)
        if previous is None:
            uploads_new.append(rel)
        elif previous.get("sha256") != meta.get("sha256"):
            uploads_changed.append(rel)

    for rel in old_files:
        if is_protected(rel, protected_prefixes):
            continue
        if rel not in new_files:
            removals.append(rel)

    return sorted(uploads_new), sorted(uploads_changed), sorted(removals)


def detect_git_commit(root: Path) -> str | None:
    git = shutil.which("git")
    if git is None or not (root / ".git").exists():
        return None
    proc = subprocess.run([git, "rev-parse", "--short", "HEAD"], cwd=str(root), text=True, capture_output=True)
    value = (proc.stdout or "").strip()
    return value or None


def default_release_id(name: str, root: Path) -> str:
    commit = detect_git_commit(root)
    stamp = time.strftime("%Y%m%d-%H%M%S", time.gmtime())
    suffix = commit or uuid.uuid4().hex[:8]
    return f"{name}-{stamp}-{suffix}"


@dataclass(frozen=True)
class ReleaseManifest:
    schema_version: int
    release_id: str
    package_name: str
    product_name: str | None
    product_code: str | None
    package_root: str | None
    technical_version: str | None
    release_type: str | None
    release_name: str | None
    created_at: str
    source_root: str
    stage_dir: str
    include_databases: bool
    include_vendor: bool
    file_count: int
    total_size: int
    files: dict[str, dict]
    toolchain: dict[str, str | None]
    notes: list[str]

    def to_dict(self) -> dict:
        return asdict(self)


def build_release_manifest(stage_dir: Path, *, package_name: str, release_id: str, source_root: Path, include_databases: bool, include_vendor: bool, notes: list[str] | None = None, release_metadata: dict | None = None) -> dict:
    files = collect_files(stage_dir)
    release_metadata = release_metadata or {}
    manifest = ReleaseManifest(
        schema_version=1,
        release_id=release_id,
        package_name=package_name,
        product_name=release_metadata.get("product_name"),
        product_code=release_metadata.get("product_code"),
        package_root=release_metadata.get("package_root"),
        technical_version=release_metadata.get("technical_version"),
        release_type=release_metadata.get("release_type"),
        release_name=release_metadata.get("release_name"),
        created_at=utc_now(),
        source_root=str(source_root),
        stage_dir=str(stage_dir),
        include_databases=include_databases,
        include_vendor=include_vendor,
        file_count=len(files),
        total_size=sum(int(meta.get("size", 0)) for meta in files.values()),
        files=files,
        toolchain={
            "python": platform.python_version(),
            "platform": platform.platform(),
            "git_commit": detect_git_commit(source_root),
        },
        notes=notes or [],
    )
    return manifest.to_dict()


def read_release_manifest(stage_dir: Path) -> dict | None:
    marker = stage_dir / DEFAULT_RELEASE_MARKER_DIR / "release-manifest.json"
    return read_json(marker, default=None)


def build_deploy_report(*, release_manifest: dict | None, remote_root: str, stage_dir: Path, new_files: list[str], changed_files: list[str], removed_files: list[str], dry_run: bool, status: str, error: str | None = None) -> dict:
    release_id = None
    release_fields = {
        "product_name": None,
        "product_code": None,
        "package_root": None,
        "technical_version": None,
        "release_type": None,
        "release_name": None,
    }
    if isinstance(release_manifest, dict):
        release_id = release_manifest.get("release_id")
        for key in release_fields:
            release_fields[key] = release_manifest.get(key)
    return {
        "schema_version": 1,
        "deployment_id": f"deploy-{time.strftime('%Y%m%d-%H%M%S', time.gmtime())}-{uuid.uuid4().hex[:8]}",
        "release_id": release_id,
        **release_fields,
        "created_at": utc_now(),
        "status": status,
        "dry_run": dry_run,
        "remote_root": remote_root,
        "stage_dir": str(stage_dir),
        "summary": {
            "new_files": len(new_files),
            "changed_files": len(changed_files),
            "removed_files": 0 if dry_run else len(removed_files),
        },
        "files": {
            "new": new_files,
            "changed": changed_files,
            "removed": removed_files,
        },
        "error": error,
    }


def local_deployment_paths(root: Path) -> dict[str, Path]:
    base = root / "storage" / "deployments"
    return {
        "base": base,
        "current": base / "current.json",
        "history": base / "history.ndjson",
        "releases": base / "releases",
    }


def write_local_deployment_markers(root: Path, release_manifest: dict | None, deploy_report: dict) -> None:
    paths = local_deployment_paths(root)
    paths["base"].mkdir(parents=True, exist_ok=True)
    paths["releases"].mkdir(parents=True, exist_ok=True)
    write_json(paths["current"], deploy_report)
    append_ndjson(paths["history"], deploy_report)
    if isinstance(release_manifest, dict) and release_manifest.get("release_id"):
        write_json(paths["releases"] / f"{release_manifest['release_id']}.json", release_manifest)


def run_php_console(root: Path, command: str, *args: str, capture: bool = True, timeout: int = 600) -> subprocess.CompletedProcess:
    console = root / "backend" / "bin" / "console"
    php = resolve_php_binary()
    return subprocess.run([php, str(console), command, *args], cwd=str(root), text=True, capture_output=capture, timeout=timeout, start_new_session=(os.name == "posix"))
