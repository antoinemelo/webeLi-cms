#!/usr/bin/env python3
from __future__ import annotations
import sys as _dec_sys
from pathlib import Path as _DecPath
_DEC_CMS_PROJECT_ROOT = next(parent for parent in _DecPath(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
if str(_DEC_CMS_PROJECT_ROOT) not in _dec_sys.path:
    _dec_sys.path.insert(0, str(_DEC_CMS_PROJECT_ROOT))

import argparse
import json
import shutil
import sqlite3
import sys
import tempfile
import time
import zipfile
from pathlib import Path

from tools.python.lib.database_inventory import database_specs, native_database_specs

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
BACKUP_DIR = ROOT / "storage" / "backups" / "sqlite"


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Restaure les bases SQLite DEC CMS depuis une archive créée par d6_backup_sqlite.py.")
    parser.add_argument("--archive", required=True, help="Archive ZIP de sauvegarde SQLite.")
    parser.add_argument("--yes", action="store_true", help="Confirme la restauration destructrice des bases courantes.")
    parser.add_argument("--no-safety-copy", action="store_true", help="Ne crée pas de copie de sécurité des bases courantes avant restauration.")
    return parser.parse_args()


def integrity_check(path: Path) -> str:
    with sqlite3.connect(path) as con:
        return str(con.execute("PRAGMA integrity_check").fetchone()[0])


def _legacy_entries() -> dict[str, dict]:
    return {
        spec.name: {
            "key": spec.key,
            "name": spec.name,
            "path": spec.path,
            "kind": spec.kind,
            "module_key": spec.module_key,
            "member": f"database/{spec.name}",
        }
        for spec in native_database_specs(backup=True)
    }


def _entries_from_manifest(manifest: dict) -> dict[str, dict]:
    databases = manifest.get("databases", {})
    if not isinstance(databases, dict):
        raise RuntimeError("backup-manifest.json invalide: databases doit être un objet.")

    entries: dict[str, dict] = {}
    if int(manifest.get("schema_version", 1)) < 3:
        legacy = _legacy_entries()
        for name, meta in databases.items():
            if name not in legacy:
                raise RuntimeError(f"Base inconnue dans une ancienne sauvegarde: {name}")
            entries[name] = legacy[name]
        return entries

    for key, meta in databases.items():
        if not isinstance(meta, dict):
            raise RuntimeError(f"Entrée de base invalide dans la sauvegarde: {key}")
        rel_path = str(meta.get("path", "")).replace("\\", "/").strip().lstrip("/")
        name = str(meta.get("name") or Path(rel_path).name).strip()
        if not key or not rel_path or not name:
            raise RuntimeError(f"Entrée de base incomplète dans la sauvegarde: {key}")
        if not rel_path.startswith("storage/database/"):
            raise RuntimeError(f"Chemin de restauration refusé hors storage/database: {rel_path}")
        entries[str(key)] = {
            "key": str(key),
            "name": name,
            "path": rel_path,
            "kind": meta.get("kind"),
            "module_key": meta.get("module_key"),
            "member": f"database/{name}",
        }
    return entries


def validate_archive(archive_path: Path, extract_dir: Path) -> tuple[dict, dict[str, dict]]:
    if not archive_path.exists() or not zipfile.is_zipfile(archive_path):
        raise RuntimeError(f"Archive invalide: {archive_path}")
    with zipfile.ZipFile(archive_path) as archive:
        names = set(archive.namelist())
        if "backup-manifest.json" not in names:
            raise RuntimeError("backup-manifest.json absent.")
        manifest = json.loads(archive.read("backup-manifest.json").decode("utf-8"))
        entries = _entries_from_manifest(manifest)
        for entry in entries.values():
            member = entry["member"]
            if member not in names:
                raise RuntimeError(f"Base absente de la sauvegarde: {member}")
        archive.extractall(extract_dir)
    for entry in entries.values():
        path = extract_dir / entry["member"]
        integrity = integrity_check(path)
        if integrity != "ok":
            raise RuntimeError(f"Base invalide dans la sauvegarde: {entry['key']} integrity_check={integrity}")
    return manifest, entries


def safety_copy(entries: dict[str, dict]) -> Path:
    stamp = time.strftime("%Y%m%d-%H%M%S")
    target = BACKUP_DIR / f"pre-restore-{stamp}"
    target.mkdir(parents=True, exist_ok=True)
    for entry in entries.values():
        source = ROOT / entry["path"]
        if source.exists():
            destination = target / entry["path"]
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source, destination)
    return target


def main() -> int:
    args = parse_args()
    if not args.yes:
        print("ERREUR: restauration non confirmée. Relancez avec --yes après avoir vérifié l'archive.", file=sys.stderr)
        return 2
    archive_path = Path(args.archive).expanduser()
    if not archive_path.is_absolute():
        archive_path = ROOT / archive_path
    (ROOT / "storage" / "database").mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="amcms-restore-") as tmp:
        tmp_path = Path(tmp)
        manifest, entries = validate_archive(archive_path, tmp_path)
        if not args.no_safety_copy:
            safety = safety_copy(entries)
            print(f"Copie de sécurité avant restauration: {safety}")
        for entry in entries.values():
            destination = ROOT / entry["path"]
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(tmp_path / entry["member"], destination)
        for entry in entries.values():
            integrity = integrity_check(ROOT / entry["path"])
            if integrity != "ok":
                raise SystemExit(f"ERREUR: restauration invalide pour {entry['key']}: integrity_check={integrity}")
    print(f"Restore SQLite terminé depuis: {archive_path}")
    print(f"Version sauvegardée: {manifest.get('technical_version', 'inconnue')}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
