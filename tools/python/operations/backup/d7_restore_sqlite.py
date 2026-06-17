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

from tools.python.lib.database_inventory import native_database_names

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
DB_DIR = ROOT / "storage" / "database"
BACKUP_DIR = ROOT / "storage" / "backups" / "sqlite"
DATABASES = native_database_names(backup=True)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Restaure les bases SQLite DEC CMS depuis une archive créée par d6_backup_sqlite.py.")
    parser.add_argument("--archive", required=True, help="Archive ZIP de sauvegarde SQLite.")
    parser.add_argument("--yes", action="store_true", help="Confirme la restauration destructrice des bases courantes.")
    parser.add_argument("--no-safety-copy", action="store_true", help="Ne crée pas de copie de sécurité des bases courantes avant restauration.")
    return parser.parse_args()


def integrity_check(path: Path) -> str:
    with sqlite3.connect(path) as con:
        return str(con.execute("PRAGMA integrity_check").fetchone()[0])


def validate_archive(archive_path: Path, extract_dir: Path) -> dict:
    if not archive_path.exists() or not zipfile.is_zipfile(archive_path):
        raise RuntimeError(f"Archive invalide: {archive_path}")
    with zipfile.ZipFile(archive_path) as archive:
        names = set(archive.namelist())
        if "backup-manifest.json" not in names:
            raise RuntimeError("backup-manifest.json absent.")
        manifest = json.loads(archive.read("backup-manifest.json").decode("utf-8"))
        for name in DATABASES:
            member = f"database/{name}"
            if member not in names:
                raise RuntimeError(f"Base absente de la sauvegarde: {member}")
        archive.extractall(extract_dir)
    for name in DATABASES:
        path = extract_dir / "database" / name
        integrity = integrity_check(path)
        if integrity != "ok":
            raise RuntimeError(f"Base invalide dans la sauvegarde: {name} integrity_check={integrity}")
    return manifest


def safety_copy() -> Path:
    stamp = time.strftime("%Y%m%d-%H%M%S")
    target = BACKUP_DIR / f"pre-restore-{stamp}"
    target.mkdir(parents=True, exist_ok=True)
    for name in DATABASES:
        source = DB_DIR / name
        if source.exists():
            shutil.copy2(source, target / name)
    return target


def main() -> int:
    args = parse_args()
    if not args.yes:
        print("ERREUR: restauration non confirmée. Relancez avec --yes après avoir vérifié l'archive.", file=sys.stderr)
        return 2
    archive_path = Path(args.archive).expanduser()
    if not archive_path.is_absolute():
        archive_path = ROOT / archive_path
    DB_DIR.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="amcms-restore-") as tmp:
        tmp_path = Path(tmp)
        manifest = validate_archive(archive_path, tmp_path)
        if not args.no_safety_copy:
            safety = safety_copy()
            print(f"Copie de sécurité avant restauration: {safety}")
        for name in DATABASES:
            shutil.copy2(tmp_path / "database" / name, DB_DIR / name)
        for name in DATABASES:
            integrity = integrity_check(DB_DIR / name)
            if integrity != "ok":
                raise SystemExit(f"ERREUR: restauration invalide pour {name}: integrity_check={integrity}")
    print(f"Restore SQLite terminé depuis: {archive_path}")
    print(f"Version sauvegardée: {manifest.get('technical_version', 'inconnue')}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
