#!/usr/bin/env python3
from __future__ import annotations

import argparse
import hashlib
import json
import shutil
import sys
import zipfile
from datetime import datetime, timezone
from pathlib import Path

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())

PROTECTED_PREFIXES = (
    "storage/database/",
    "storage/media/",
    "storage/uploads/",
    "storage/logs/",
    "storage/backups/",
    "storage/cache/",
    "storage/exports/",
    "storage/operations/",
    "storage/updates/",
    "local/",
)
PROTECTED_FILES = {"ops/.env", "ops/modules.local.json", ".env"}


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def read_json(path: Path) -> dict:
    value = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(value, dict):
        raise RuntimeError(f"Objet JSON attendu: {path}")
    return value


def protected(relative: str) -> bool:
    return relative in PROTECTED_FILES or any(relative.startswith(prefix) for prefix in PROTECTED_PREFIXES)


def core_file(relative: str) -> bool:
    if protected(relative) or relative == "storage/deployments/release-manifest.json":
        return False
    if relative.startswith("backend/src/Modules/") or relative.startswith("database/modules/"):
        return False
    if relative.startswith("database/migrations/"):
        return relative.startswith("database/migrations/core/") or relative.startswith("database/migrations/iam/")
    return True


def files_under(stage: Path, prefixes: list[str]) -> list[str]:
    normalized = [prefix.strip("/") for prefix in prefixes if prefix.strip("/")]
    files: list[str] = []
    for path in sorted(stage.rglob("*")):
        if not path.is_file() or path.is_symlink():
            continue
        relative = path.relative_to(stage).as_posix()
        if protected(relative):
            continue
        if any(relative == prefix or relative.startswith(prefix + "/") for prefix in normalized):
            files.append(relative)
    return files


def module_specs(stage: Path, core_version: str) -> list[dict]:
    specs: list[dict] = []
    for manifest_path in sorted((stage / "backend" / "src" / "Modules").glob("*/module.json")):
        module = read_json(manifest_path)
        key = str(module.get("key", "")).strip()
        version = str(module.get("version", "")).strip()
        provider_file = str(module.get("provider_file", "")).strip("/")
        if not key or not version or not provider_file:
            raise RuntimeError(f"Manifeste module incomplet: {manifest_path}")
        owned = [str(Path(provider_file).parent).replace("\\", "/")]
        database_keys: list[str] = []
        for database in module.get("databases", []):
            if not isinstance(database, dict):
                continue
            database_key = str(database.get("key", "")).strip()
            if database_key:
                database_keys.append(database_key)
            for field in ("schema", "migrations"):
                value = str(database.get(field, "")).strip("/")
                if value:
                    owned.append(value)
        specs.append(
            {
                "type": "module",
                "key": key,
                "name": str(module.get("name", key)),
                "version": version,
                "requires_core": core_version,
                "database_keys": sorted(set(database_keys)),
                "owned_prefixes": sorted(set(owned)),
                # The current Vue build is monolithic. Core-first compatibility
                # makes this controlled overlap safe until module bundles become external.
                "shared_prefixes": ["admin-app"],
            }
        )
    return specs


def build_manifest(stage: Path, component: dict, files: list[str]) -> dict:
    inventory: dict[str, dict] = {}
    for relative in sorted(set(files)):
        path = stage / relative
        if not path.is_file() or path.is_symlink():
            raise RuntimeError(f"Fichier de composant introuvable: {relative}")
        inventory[relative] = {"sha256": sha256(path), "size": path.stat().st_size}
    return {
        "schema_version": 1,
        "application": "dec-cms",
        "channel": "stable",
        "generated_at": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
        "component": {key: value for key, value in component.items() if key not in {"owned_prefixes", "shared_prefixes"}},
        "owned_prefixes": component.get("owned_prefixes", []),
        "shared_prefixes": component.get("shared_prefixes", []),
        "files": inventory,
    }


def write_component_archive(stage: Path, output: Path, component: dict, files: list[str]) -> tuple[Path, str]:
    if component["type"] == "core":
        name = f"core-{component['version']}.zip"
    else:
        name = f"module-{component['key']}-{component['version']}.zip"
    archive = output / name
    root_name = f"{component['type']}-{component['key']}"
    manifest = build_manifest(stage, component, files)
    with zipfile.ZipFile(archive, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as handle:
        for relative in manifest["files"]:
            handle.write(stage / relative, f"{root_name}/{relative}")
        handle.writestr(
            f"{root_name}/component-manifest.json",
            json.dumps(manifest, ensure_ascii=False, indent=2, sort_keys=True) + "\n",
        )
    digest = sha256(archive)
    (output / f"{name}.sha256").write_text(f"{digest}  {name}\n", encoding="utf-8")
    return archive, digest


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Construit les composants stables core/modules depuis un staging qualifié.")
    parser.add_argument("--stage", default="storage/exports/release_stage")
    parser.add_argument("--output", default="storage/exports/stable-components")
    parser.add_argument("--release-tag", required=True)
    parser.add_argument("--repository", default="antoinemelo/webeLi-cms")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    stage = Path(args.stage)
    output = Path(args.output)
    if not stage.is_absolute():
        stage = ROOT / stage
    if not output.is_absolute():
        output = ROOT / output
    release = read_json(stage / "config" / "release.json")
    core_version = str(release.get("technical_version", "")).strip()
    if not core_version or args.release_tag != core_version:
        raise RuntimeError(f"Le tag {args.release_tag} doit correspondre à config/release.json ({core_version}).")
    if output.exists():
        shutil.rmtree(output)
    output.mkdir(parents=True)

    all_stage_files = [path.relative_to(stage).as_posix() for path in sorted(stage.rglob("*")) if path.is_file() and not path.is_symlink()]
    core = {"type": "core", "key": "core", "name": "DEC CMS", "version": core_version}
    core_archive, core_hash = write_component_archive(stage, output, core, [path for path in all_stage_files if core_file(path)])
    release_prefix = f"https://github.com/{args.repository}/releases/download/{args.release_tag}/"
    catalog = {
        "schema_version": 1,
        "application": "dec-cms",
        "channel": "stable",
        "repository": args.repository,
        "generated_at": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
        "core": {
            **core,
            "archive_url": release_prefix + core_archive.name,
            "sha256": core_hash,
            "size": core_archive.stat().st_size,
        },
        "modules": [],
    }
    for module in module_specs(stage, core_version):
        prefixes = module["owned_prefixes"] + module["shared_prefixes"]
        files = files_under(stage, prefixes)
        archive, digest = write_component_archive(stage, output, module, files)
        catalog["modules"].append(
            {
                key: value
                for key, value in {
                    **module,
                    "archive_url": release_prefix + archive.name,
                    "sha256": digest,
                    "size": archive.stat().st_size,
                }.items()
                if key not in {"owned_prefixes", "shared_prefixes"}
            }
        )
    (output / "dec-cms-stable.json").write_text(
        json.dumps(catalog, ensure_ascii=False, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    print(json.dumps({"status": "ok", "output": str(output), "core": core_version, "modules": len(catalog["modules"])}, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
