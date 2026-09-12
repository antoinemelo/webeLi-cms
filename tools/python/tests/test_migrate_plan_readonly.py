from __future__ import annotations

import hashlib
import sqlite3
import tempfile
import unittest
from pathlib import Path

from tools.python.operations.database import d9_migrate_sqlite as migrator

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def table_exists(path: Path, table: str) -> bool:
    with sqlite3.connect(path) as connection:
        row = connection.execute(
            "SELECT 1 FROM sqlite_master WHERE type='table' AND name=?",
            (table,),
        ).fetchone()
    return row is not None


class MigratePlanReadOnlyTest(unittest.TestCase):
    def test_migrate_plan_does_not_modify_sqlite_files(self) -> None:
        scopes = [scope for scope in migrator.SCOPES.values() if scope.path.is_file()]
        self.assertTrue(scopes, "Aucune base SQLite migratable trouvée dans storage/database")

        with tempfile.TemporaryDirectory() as tmp:
            base = Path(tmp)
            isolated_scopes: list[migrator.DatabaseScope] = []
            for scope in scopes:
                clone = base / scope.path.name
                with sqlite3.connect(f"file:{scope.path.as_posix()}?mode=ro", uri=True) as source:
                    with sqlite3.connect(clone) as target:
                        source.backup(target)
                isolated_scopes.append(
                    migrator.DatabaseScope(
                        scope.name,
                        clone,
                        scope.migrations,
                        key=scope.key,
                        kind=scope.kind,
                        module_key=scope.module_key,
                    )
                )

            before = {scope.path.name: sha256(scope.path) for scope in isolated_scopes}
            for scope in isolated_scopes:
                migrator.plan_scope(scope)
            after = {scope.path.name: sha256(scope.path) for scope in isolated_scopes}

        self.assertEqual(before, after)

    def test_plan_scope_with_missing_log_reports_without_creating_log(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            base = Path(tmp)
            db = base / "custom.sqlite"
            migrations = base / "migrations"
            migrations.mkdir()
            migrations.joinpath("0001_create_demo.sql").write_text(
                "CREATE TABLE demo (id INTEGER PRIMARY KEY);\n",
                encoding="utf-8",
            )
            with sqlite3.connect(db) as connection:
                connection.execute("CREATE TABLE existing (id INTEGER PRIMARY KEY)")

            before = sha256(db)
            files, pending, has_log = migrator.plan_scope(
                migrator.DatabaseScope("custom", db, migrations)
            )
            after = sha256(db)

            self.assertEqual(files, ["0001_create_demo.sql"])
            self.assertEqual(pending, ["0001_create_demo.sql"])
            self.assertFalse(has_log)
            self.assertEqual(before, after)
            self.assertFalse(table_exists(db, "schema_migrations"))


if __name__ == "__main__":
    unittest.main()
