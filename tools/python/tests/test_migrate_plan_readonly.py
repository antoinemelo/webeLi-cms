from __future__ import annotations

import hashlib
import sqlite3
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

from tools.python.operations.database import d9_migrate_sqlite as migrator

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
DB_DIR = ROOT / "storage" / "database"


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
        dbs = sorted(DB_DIR.glob("*.sqlite"))
        self.assertTrue(dbs, "Aucune base SQLite trouvée dans storage/database")
        before = {path.name: sha256(path) for path in dbs}
        completed = subprocess.run(
            [sys.executable, "tools/cms.py", "migrate", "--plan"],
            cwd=ROOT,
            text=True,
            capture_output=True,
            timeout=120,
        )
        self.assertEqual(completed.returncode, 0, completed.stderr or completed.stdout)
        after = {path.name: sha256(path) for path in dbs}
        self.assertEqual(before, after, completed.stdout)

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
