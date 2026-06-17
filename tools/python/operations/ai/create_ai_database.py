#!/usr/bin/env python3
"""Crée la base SQLite dédiée au module IA.

Cette base est volontairement séparée des bases core/IAM/modules métier. Elle
peut être absente lorsque le module IA est désactivé, supprimée sans casser le
CMS, puis reconstruite depuis `database/modules/ai.sql` et son seed minimal.
"""
from __future__ import annotations

import argparse
import sqlite3
import sys
from pathlib import Path

BASE = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
AI_DB = BASE / "storage" / "database" / "ai.sqlite"
AI_SCHEMA = BASE / "database" / "modules" / "ai.sql"
AI_DEFAULT_SEED = BASE / "database" / "seeds" / "default" / "ai_default_seed.sql"
SQLITE_BUSY_TIMEOUT_MS = 5000


def configure_sqlite(connection: sqlite3.Connection, *, writable: bool = True) -> None:
    connection.execute(f"PRAGMA busy_timeout = {SQLITE_BUSY_TIMEOUT_MS}")
    connection.execute("PRAGMA foreign_keys = ON")
    if writable:
        connection.execute("PRAGMA journal_mode = WAL")


def connect_sqlite(db_path: Path, *, writable: bool = True) -> sqlite3.Connection:
    db_path.parent.mkdir(parents=True, exist_ok=True)
    connection = sqlite3.connect(db_path)
    configure_sqlite(connection, writable=writable)
    return connection


def run_sql_file(connection: sqlite3.Connection, sql_path: Path) -> None:
    if not sql_path.exists():
        raise FileNotFoundError(f"Fichier SQL introuvable: {sql_path}")
    sql = sql_path.read_text(encoding="utf-8").strip()
    if not sql:
        return
    connection.executescript(sql)
    connection.commit()


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Crée storage/database/ai.sqlite depuis zéro si nécessaire.")
    parser.add_argument("--path", type=Path, default=AI_DB, help="Chemin de sortie de la base IA.")
    parser.add_argument("--schema-only", action="store_true", help="Crée uniquement le schéma, sans seed minimal.")
    parser.add_argument("--overwrite", action="store_true", help="Supprime la base existante avant création.")
    return parser.parse_args()


def remove_sqlite_family(path: Path) -> None:
    for candidate in (path, Path(str(path) + "-wal"), Path(str(path) + "-shm")):
        if candidate.exists():
            candidate.unlink()


def main() -> int:
    args = parse_args()
    db_path = args.path.resolve() if args.path.is_absolute() else (BASE / args.path).resolve()

    if args.overwrite:
        remove_sqlite_family(db_path)
    elif db_path.exists():
        print(f"Base IA déjà présente: {db_path}")

    connection = connect_sqlite(db_path)
    try:
        run_sql_file(connection, AI_SCHEMA)
        if not args.schema_only:
            run_sql_file(connection, AI_DEFAULT_SEED)
        integrity = connection.execute("PRAGMA integrity_check").fetchone()[0]
        if integrity != "ok":
            print(f"ERREUR integrity_check: {integrity}", file=sys.stderr)
            return 1
    finally:
        connection.close()

    print(f"Base IA prête: {db_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
