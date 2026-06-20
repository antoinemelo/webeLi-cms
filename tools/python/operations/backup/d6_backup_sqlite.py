#!/usr/bin/env python3
from __future__ import annotations
import sys as _dec_sys
from pathlib import Path as _DecPath
_DEC_CMS_PROJECT_ROOT = next(parent for parent in _DecPath(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
if str(_DEC_CMS_PROJECT_ROOT) not in _dec_sys.path:
    _dec_sys.path.insert(0, str(_DEC_CMS_PROJECT_ROOT))

import argparse
import json
import sqlite3
import time
import zipfile
from pathlib import Path

from tools.python.lib.deploylib import sha256_file
from tools.python.lib.release_metadata import load_release_metadata
from tools.python.lib.database_inventory import DatabaseSpec, database_specs

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
BACKUP_DIR = ROOT / "storage" / "backups" / "sqlite"


def parse_args() -> argparse.Namespace:
    metadata = load_release_metadata()
    default_name = f"{metadata.technical_version}-sqlite-{time.strftime('%Y%m%d-%H%M%S')}.zip"
    parser = argparse.ArgumentParser(description="Sauvegarde cohérente des bases SQLite DEC CMS dans une archive ZIP.")
    parser.add_argument("--output", default=str(BACKUP_DIR / default_name), help="Archive de sauvegarde à créer.")
    return parser.parse_args()


def integrity_check(path: Path) -> str:
    with sqlite3.connect(path) as con:
        return str(con.execute("PRAGMA integrity_check").fetchone()[0])


def archive_member(spec: DatabaseSpec) -> str:
    return f"database/{spec.name}"


def manifest_entry(spec: DatabaseSpec, path: Path, integrity: str) -> dict:
    return {
        "key": spec.key,
        "path": spec.path,
        "name": spec.name,
        "kind": spec.kind,
        "module_key": spec.module_key,
        "migration_scope": spec.migration_scope,
        "sha256": sha256_file(path),
        "size": path.stat().st_size,
        "integrity_check": integrity,
    }


def main() -> int:
    args = parse_args()
    output = Path(args.output).expanduser()
    if not output.is_absolute():
        output = ROOT / output
    output.parent.mkdir(parents=True, exist_ok=True)

    specs = database_specs(root=ROOT, backup=True)
    metadata = load_release_metadata()
    manifest = {
        "schema_version": 3,
        "created_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "technical_version": metadata.technical_version,
        "databases": {},
    }
    entries: list[tuple[DatabaseSpec, Path, str]] = []
    for spec in specs:
        path = spec.absolute_path(ROOT)
        if not path.exists():
            raise SystemExit(f"ERREUR: base absente: {path}")
        integrity = integrity_check(path)
        if integrity != "ok":
            raise SystemExit(f"ERREUR: {spec.key} integrity_check={integrity}")
        manifest["databases"][spec.key] = manifest_entry(spec, path, integrity)
        entries.append((spec, path, archive_member(spec)))

    with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        archive.writestr("backup-manifest.json", json.dumps(manifest, ensure_ascii=False, indent=2) + "\n")
        used_members: set[str] = set()
        for spec, path, member in entries:
            if member in used_members:
                raise SystemExit(f"ERREUR: nom de base dupliqué dans la sauvegarde: {member}")
            used_members.add(member)
            archive.write(path, member)
    print(f"Backup SQLite créé: {output}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
