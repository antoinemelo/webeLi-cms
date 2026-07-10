#!/usr/bin/env python3
"""Migrateur SQLite idempotent pour DEC CMS.

Les bases de production ne sont jamais remplacées par une release. Quand une
version apporte des changements SQL, ils doivent être déposés dans un dossier de
migrations puis appliqués avec ce script via la façade publique::

    python3 tools/cms.py migrate --apply --backup --yes

Le journal ``schema_migrations`` reste local à chaque base. Le mode ``--plan``
est strictement non mutatif: il ouvre les bases en lecture seule et ne crée ni
fichier, ni table, ni colonne.
"""
from __future__ import annotations

import argparse
import hashlib
import sqlite3
import subprocess
import sys
from dataclasses import dataclass
from pathlib import Path


ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))
from tools.python.lib.database_inventory import DatabaseSpec, database_specs, module_keys

BACKUP_SCRIPT = ROOT / "tools" / "python" / "operations" / "backup" / "d6_backup_sqlite.py"
SQLITE_BUSY_TIMEOUT_MS = 5000


class MigrationChecksumError(RuntimeError):
    pass


def configure_sqlite(connection: sqlite3.Connection, *, writable: bool = True) -> None:
    connection.execute(f"PRAGMA busy_timeout = {SQLITE_BUSY_TIMEOUT_MS}")
    connection.execute("PRAGMA foreign_keys = ON")
    if writable:
        connection.execute("PRAGMA journal_mode = WAL")


def connect_sqlite(path: Path, *, writable: bool = True) -> sqlite3.Connection:
    if writable:
        connection = sqlite3.connect(path)
    else:
        # mode=ro garantit qu'un plan ne crée ni fichier, ni journal, ni table.
        connection = sqlite3.connect(f"file:{path.as_posix()}?mode=ro", uri=True)
    configure_sqlite(connection, writable=writable)
    return connection


@dataclass(frozen=True)
class DatabaseScope:
    name: str
    path: Path
    migrations: Path
    # ``key`` and ``kind`` stay optional to keep compatibility with
    # older tests and local scripts that instantiate DatabaseScope(name, path, migrations).
    key: str | None = None
    kind: str = "core"
    module_key: str | None = None

    def __post_init__(self) -> None:
        if self.key is None:
            object.__setattr__(self, "key", self.name)


def build_scopes() -> dict[str, DatabaseScope]:
    scopes: dict[str, DatabaseScope] = {}
    for spec in database_specs(root=ROOT, migratable=True):
        scope_name = spec.migration_scope or spec.key
        migrations = spec.absolute_migrations_path(ROOT)
        if migrations is None:
            continue
        scopes[scope_name] = DatabaseScope(
            name=scope_name,
            path=spec.absolute_path(ROOT),
            migrations=migrations,
            key=spec.key,
            kind=spec.kind,
            module_key=spec.module_key,
        )
    return scopes


SCOPES = build_scopes()


def migration_log_exists(connection: sqlite3.Connection) -> bool:
    row = connection.execute(
        "SELECT 1 FROM sqlite_master WHERE type='table' AND name='schema_migrations'"
    ).fetchone()
    return row is not None


def migration_log_columns(connection: sqlite3.Connection) -> set[str]:
    if not migration_log_exists(connection):
        return set()
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
    if "checksum" not in columns:
        connection.execute("ALTER TABLE schema_migrations ADD COLUMN checksum TEXT")
        columns.add("checksum")
    if "source" not in columns:
        connection.execute("ALTER TABLE schema_migrations ADD COLUMN source TEXT")
        columns.add("source")
    if "migration_name" in columns:
        connection.execute("UPDATE schema_migrations SET migration = migration_name WHERE migration IS NULL OR migration = ''")
    if "applied_at" in columns:
        connection.execute("UPDATE schema_migrations SET migrated_at = applied_at WHERE migrated_at IS NULL OR migrated_at = ''")
    connection.execute("UPDATE schema_migrations SET migrated_at = CURRENT_TIMESTAMP WHERE migrated_at IS NULL OR migrated_at = ''")
    connection.commit()


def file_checksum(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def applied_migrations(connection: sqlite3.Connection, scope: str) -> dict[str, str | None]:
    if not migration_log_exists(connection):
        return {}
    columns = migration_log_columns(connection)
    rows: dict[str, str | None] = {}
    checksum_expr = "checksum" if "checksum" in columns else "NULL"
    if "migration" in columns:
        for migration, checksum in connection.execute(
            f"SELECT migration, {checksum_expr} FROM schema_migrations WHERE migration IS NOT NULL AND migration != ''"
        ):
            rows[str(migration)] = str(checksum) if checksum else None
    if {"scope", "migration_name"}.issubset(columns):
        for migration, checksum in connection.execute(
            f"""
            SELECT migration_name, {checksum_expr}
            FROM schema_migrations
            WHERE scope=? AND migration_name IS NOT NULL AND migration_name != ''
            """,
            (scope,),
        ):
            rows[str(migration)] = str(checksum) if checksum else None
    return rows


def mark_applied(connection: sqlite3.Connection, scope: str, migration: str, checksum: str, source: str) -> None:
    columns = migration_log_columns(connection)
    if {"scope", "migration_name"}.issubset(columns):
        connection.execute(
            """
            INSERT OR IGNORE INTO schema_migrations(scope, migration_name, migration, applied_at, migrated_at, checksum, source)
            VALUES(?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?, ?)
            """,
            (scope, migration, migration, checksum, source),
        )
        connection.execute(
            """
            UPDATE schema_migrations
            SET checksum=COALESCE(checksum, ?), source=COALESCE(source, ?)
            WHERE scope=? AND migration_name=?
            """,
            (checksum, source, scope, migration),
        )
    else:
        connection.execute(
            """
            INSERT OR IGNORE INTO schema_migrations(migration, migrated_at, checksum, source)
            VALUES(?, CURRENT_TIMESTAMP, ?, ?)
            """,
            (migration, checksum, source),
        )
        connection.execute(
            """
            UPDATE schema_migrations
            SET checksum=COALESCE(checksum, ?), source=COALESCE(source, ?)
            WHERE migration=?
            """,
            (checksum, source, migration),
        )


def integrity_check(path: Path) -> str:
    with connect_sqlite(path) as connection:
        return str(connection.execute("PRAGMA integrity_check").fetchone()[0])


def sql_files(scope: DatabaseScope) -> list[Path]:
    if not scope.migrations.exists():
        return []
    return sorted(path for path in scope.migrations.glob("*.sql") if path.is_file())


def assert_applied_checksums_are_stable(scope: DatabaseScope, applied: dict[str, str | None]) -> None:
    for path in sql_files(scope):
        recorded = applied.get(path.name)
        if not recorded:
            continue
        current = file_checksum(path)
        if recorded != current:
            raise MigrationChecksumError(
                f"{scope.name}/{path.name}: checksum différent de celui déjà appliqué. "
                "Ne modifiez pas une migration appliquée; ajoutez une migration corrective."
            )


def plan_scope(scope: DatabaseScope) -> tuple[list[str], list[str], bool]:
    if not scope.path.exists():
        raise RuntimeError(f"Base absente pour {scope.name}: {scope.path}")
    with connect_sqlite(scope.path, writable=False) as connection:
        has_log = migration_log_exists(connection)
        applied = applied_migrations(connection, scope.name)
        assert_applied_checksums_are_stable(scope, applied)
    files = [path.name for path in sql_files(scope)]
    pending = [name for name in files if name not in applied]
    return files, pending, has_log


def apply_scope(scope: DatabaseScope) -> list[str]:
    if not scope.path.exists():
        raise RuntimeError(f"Base absente pour {scope.name}: {scope.path}")
    applied_now: list[str] = []
    with connect_sqlite(scope.path) as connection:
        ensure_migration_log(connection)
        before = integrity_check(scope.path)
        if before != "ok":
            raise RuntimeError(f"{scope.name}: integrity_check avant migration = {before}")
        applied = applied_migrations(connection, scope.name)
        assert_applied_checksums_are_stable(scope, applied)
        for path in sql_files(scope):
            checksum = file_checksum(path)
            if path.name in applied:
                continue
            sql = path.read_text(encoding="utf-8").strip()
            try:
                connection.execute("BEGIN")
                if sql:
                    connection.executescript(sql)
                mark_applied(connection, scope.name, path.name, checksum, path.relative_to(ROOT).as_posix())
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
    parser = argparse.ArgumentParser(description="Applique les migrations SQLite DEC CMS non encore appliquées.")
    target = parser.add_mutually_exclusive_group(required=True)
    target.add_argument("--all", action="store_true", help="Traite toutes les bases SQLite migratables connues.")
    target.add_argument("--database", choices=sorted(SCOPES), help="Traite une seule base par clé ou scope.")
    target.add_argument("--module", choices=module_keys(ROOT), help="Traite les bases déclarées par un module.")
    parser.add_argument("--plan", action="store_true", help="Affiche les migrations disponibles et manquantes sans les appliquer.")
    parser.add_argument("--backup", action="store_true", help="Crée une sauvegarde SQLite via d6_backup_sqlite.py avant application.")
    parser.add_argument("--no-backup-i-understand-the-risk", action="store_true", help="Applique sans sauvegarde préalable; option volontairement explicite et déconseillée.")
    parser.add_argument("--yes", action="store_true", help="Confirmation obligatoire pour appliquer des migrations.")
    return parser.parse_args()


def selected_scopes(args: argparse.Namespace) -> list[DatabaseScope]:
    if args.all:
        return [SCOPES[name] for name in sorted(SCOPES)]
    if args.module:
        selected = [scope for scope in SCOPES.values() if scope.module_key == args.module]
        if not selected:
            raise RuntimeError(f"Aucune base migratable déclarée pour le module: {args.module}")
        return sorted(selected, key=lambda scope: scope.name)
    return [SCOPES[args.database]]


def main() -> int:
    args = parse_args()
    try:
        scopes = selected_scopes(args)
        for scope in scopes:
            files, pending, has_log = plan_scope(scope)
            module = f" ; module={scope.module_key}" if scope.module_key else ""
            print(f"[{scope.name}] migrations disponibles: {len(files)} ; a appliquer: {len(pending)} ; kind={scope.kind}{module}")
            if not has_log:
                print(f"INFO {scope.name}: schema_migrations absent ; le journal serait créé uniquement en mode --apply.")
            for name in pending:
                print(f"PENDING {scope.name}/{name}")
        if args.plan:
            return 0
        if not args.yes:
            print("ERREUR: application non confirmée. Relancez avec --yes après vérification du plan.", file=sys.stderr)
            return 2
        if args.backup and args.no_backup_i_understand_the_risk:
            print("ERREUR: choisissez --backup ou --no-backup-i-understand-the-risk, pas les deux.", file=sys.stderr)
            return 2
        if not args.backup and not args.no_backup_i_understand_the_risk:
            print("ERREUR: application refusée sans --backup ou --no-backup-i-understand-the-risk.", file=sys.stderr)
            return 2
        if args.backup:
            make_sqlite_backup()
        total = 0
        for scope in scopes:
            applied = apply_scope(scope)
            total += len(applied)
            for name in applied:
                print(f"APPLIED {scope.name}/{name}")
        print(f"Migrations terminées: {total} migration(s) appliquée(s).")
        return 0
    except MigrationChecksumError as exc:
        print(f"ERREUR: {exc}", file=sys.stderr)
        return 1
    except RuntimeError as exc:
        print(f"ERREUR: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
