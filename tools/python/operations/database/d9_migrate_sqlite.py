#!/usr/bin/env python3
"""Migrateur SQLite idempotent pour DEC CMS.

Les bases de production ne sont jamais remplacees par une release. Quand une
version apporte des changements SQL, ils doivent etre deposes dans
`database/migrations/<scope>/*.sql` puis appliques avec ce script::

    python3 tools/python/operations/database/d9_migrate_sqlite.py --all --backup --yes

Le journal `schema_migrations` est cree ou normalise dans chaque base afin que
les migrations soient rejouables sans doublon. Aucune migration n'est executee
si aucun fichier SQL n'est present pour le scope demande.
"""
from __future__ import annotations

import argparse
import shutil
import sqlite3
import subprocess
import sys
import time
from dataclasses import dataclass
from pathlib import Path

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
DB_DIR = ROOT / "storage" / "database"
MIGRATION_DIR = ROOT / "database" / "migrations"
BACKUP_SCRIPT = ROOT / "tools" / "python" / "operations" / "backup" / "d6_backup_sqlite.py"
SQLITE_BUSY_TIMEOUT_MS = 5000


def configure_sqlite(connection: sqlite3.Connection, *, writable: bool = True) -> None:
    connection.execute(f"PRAGMA busy_timeout = {SQLITE_BUSY_TIMEOUT_MS}")
    connection.execute("PRAGMA foreign_keys = ON")
    if writable:
        connection.execute("PRAGMA journal_mode = WAL")


def connect_sqlite(path: Path, *, writable: bool = True) -> sqlite3.Connection:
    connection = sqlite3.connect(path)
    configure_sqlite(connection, writable=writable)
    return connection


@dataclass(frozen=True)
class DatabaseScope:
    name: str
    path: Path
    migrations: Path


SCOPES = {
    "core": DatabaseScope("core", DB_DIR / "core.sqlite", MIGRATION_DIR / "core"),
    "iam": DatabaseScope("iam", DB_DIR / "iam.sqlite", MIGRATION_DIR / "iam"),
    "forms": DatabaseScope("forms", DB_DIR / "forms.sqlite", MIGRATION_DIR / "forms"),
    "cookies": DatabaseScope("cookies", DB_DIR / "cookies.sqlite", MIGRATION_DIR / "cookies"),
}


def migration_log_columns(connection: sqlite3.Connection) -> set[str]:
    return {row[1] for row in connection.execute("PRAGMA table_info(schema_migrations)").fetchall()}


def ensure_migration_log(connection: sqlite3.Connection) -> None:
    connection.execute(
        """
        CREATE TABLE IF NOT EXISTS schema_migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            migration TEXT NOT NULL UNIQUE,
            migrated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
        """
    )
    columns = migration_log_columns(connection)
    if "migration" not in columns:
        connection.execute("ALTER TABLE schema_migrations ADD COLUMN migration TEXT")
        columns.add("migration")
    if "migrated_at" not in columns:
        connection.execute("ALTER TABLE schema_migrations ADD COLUMN migrated_at TEXT")
        columns.add("migrated_at")
    if "migration_name" in columns:
        connection.execute("UPDATE schema_migrations SET migration = migration_name WHERE migration IS NULL OR migration = ''")
    if "applied_at" in columns:
        connection.execute("UPDATE schema_migrations SET migrated_at = applied_at WHERE migrated_at IS NULL OR migrated_at = ''")
    connection.execute("UPDATE schema_migrations SET migrated_at = CURRENT_TIMESTAMP WHERE migrated_at IS NULL OR migrated_at = ''")
    connection.commit()


def applied_migrations(connection: sqlite3.Connection, scope: str) -> set[str]:
    columns = migration_log_columns(connection)
    names: set[str] = set()
    if "migration" in columns:
        names.update(
            row[0]
            for row in connection.execute("SELECT migration FROM schema_migrations WHERE migration IS NOT NULL AND migration != ''")
        )
    if {"scope", "migration_name"}.issubset(columns):
        names.update(
            row[0]
            for row in connection.execute(
                "SELECT migration_name FROM schema_migrations WHERE scope=? AND migration_name IS NOT NULL AND migration_name != ''",
                (scope,),
            )
        )
    return names


def mark_applied(connection: sqlite3.Connection, scope: str, migration: str) -> None:
    columns = migration_log_columns(connection)
    if {"scope", "migration_name"}.issubset(columns):
        connection.execute(
            """
            INSERT OR IGNORE INTO schema_migrations(scope, migration_name, migration, applied_at, migrated_at)
            VALUES(?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            """,
            (scope, migration, migration),
        )
    else:
        connection.execute(
            "INSERT OR IGNORE INTO schema_migrations(migration, migrated_at) VALUES(?, CURRENT_TIMESTAMP)",
            (migration,),
        )


def integrity_check(path: Path) -> str:
    with connect_sqlite(path) as connection:
        return str(connection.execute("PRAGMA integrity_check").fetchone()[0])


def sql_files(scope: DatabaseScope) -> list[Path]:
    if not scope.migrations.exists():
        return []
    return sorted(path for path in scope.migrations.glob("*.sql") if path.is_file())


def plan_scope(scope: DatabaseScope) -> tuple[list[str], list[str]]:
    if not scope.path.exists():
        raise RuntimeError(f"Base absente pour {scope.name}: {scope.path}")
    with connect_sqlite(scope.path) as connection:
        ensure_migration_log(connection)
        applied = applied_migrations(connection, scope.name)
    files = [path.name for path in sql_files(scope)]
    pending = [name for name in files if name not in applied]
    return files, pending


def apply_scope(scope: DatabaseScope, dry_run: bool = False) -> list[str]:
    if not scope.path.exists():
        raise RuntimeError(f"Base absente pour {scope.name}: {scope.path}")
    applied_now: list[str] = []
    with connect_sqlite(scope.path) as connection:
        ensure_migration_log(connection)
        before = integrity_check(scope.path)
        if before != "ok":
            raise RuntimeError(f"{scope.name}: integrity_check avant migration = {before}")
        applied = applied_migrations(connection, scope.name)
        for path in sql_files(scope):
            if path.name in applied:
                continue
            if dry_run:
                applied_now.append(path.name)
                continue
            sql = path.read_text(encoding="utf-8").strip()
            try:
                connection.execute("BEGIN")
                if sql:
                    connection.executescript(sql)
                mark_applied(connection, scope.name, path.name)
                connection.commit()
                applied_now.append(path.name)
            except Exception:
                connection.rollback()
                raise
        after = integrity_check(scope.path)
        if after != "ok":
            raise RuntimeError(f"{scope.name}: integrity_check apres migration = {after}")
    return applied_now


def make_sqlite_backup() -> None:
    if not BACKUP_SCRIPT.exists():
        raise RuntimeError("Script de backup SQLite introuvable: d6_backup_sqlite.py")
    subprocess.run([sys.executable, str(BACKUP_SCRIPT)], cwd=str(ROOT), check=True)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Applique les migrations SQLite DEC CMS non encore appliquees.")
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--all", action="store_true", help="Traite core, iam, forms et cookies.")
    group.add_argument("--database", choices=sorted(SCOPES), help="Traite une seule base.")
    parser.add_argument("--plan", action="store_true", help="Affiche les migrations disponibles et manquantes sans les appliquer.")
    parser.add_argument("--backup", action="store_true", help="Cree une sauvegarde SQLite via d6_backup_sqlite.py avant application.")
    parser.add_argument("--yes", action="store_true", help="Confirmation obligatoire pour appliquer des migrations.")
    return parser.parse_args()


def selected_scopes(args: argparse.Namespace) -> list[DatabaseScope]:
    if args.all:
        return [SCOPES[name] for name in ("core", "iam", "forms", "cookies")]
    return [SCOPES[args.database]]


def main() -> int:
    args = parse_args()
    scopes = selected_scopes(args)
    for scope in scopes:
        files, pending = plan_scope(scope)
        print(f"[{scope.name}] migrations disponibles: {len(files)} ; a appliquer: {len(pending)}")
        for name in pending:
            print(f"PENDING {scope.name}/{name}")
    if args.plan:
        return 0
    if not args.yes:
        print("ERREUR: application non confirmee. Relancez avec --yes apres verification du plan.", file=sys.stderr)
        return 2
    if args.backup:
        make_sqlite_backup()
    total = 0
    for scope in scopes:
        applied = apply_scope(scope)
        total += len(applied)
        for name in applied:
            print(f"APPLIED {scope.name}/{name}")
    print(f"Migrations terminees: {total} migration(s) appliquee(s).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
