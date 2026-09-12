from __future__ import annotations

import os
import sqlite3
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from tools.python.operations.database import a_db_init
from tools.python.operations.database import b0_db_seed
from tools.python.cms.runtime import resolve_php_binary


ROOT = Path(__file__).resolve().parents[3]


class DatabaseRebuildProfilesTest(unittest.TestCase):
    def test_business_and_sale_structures_can_be_created_without_seed_data(self) -> None:
        cases = (
            ("business", ROOT / "database/modules/business.sql"),
            ("sale", ROOT / "database/modules/sale.sql"),
        )
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory)
            for scope, schema in cases:
                with self.subTest(scope=scope):
                    database = target / f"{scope}.sqlite"
                    skipped = a_db_init.create_structure(
                        database,
                        schema,
                        include_schema_data=False,
                    )
                    self.assertGreater(skipped, 0)
                    with sqlite3.connect(database) as connection:
                        tables = connection.execute(
                            "SELECT name FROM sqlite_master "
                            "WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name != 'schema_migrations'"
                        ).fetchall()
                        self.assertGreater(len(tables), 0)
                        for (table,) in tables:
                            count = connection.execute(f'SELECT COUNT(*) FROM "{table}"').fetchone()[0]
                            self.assertEqual(count, 0, table)

    def test_data_filter_keeps_insert_statements_inside_triggers(self) -> None:
        sql = """
        CREATE TABLE source(id INTEGER PRIMARY KEY, value TEXT);
        CREATE TABLE audit(id INTEGER PRIMARY KEY, source_id INTEGER);
        INSERT INTO source(id, value) VALUES(1, 'seed');
        CREATE TRIGGER source_audit AFTER INSERT ON source
        BEGIN
            INSERT INTO audit(source_id) VALUES(NEW.id);
        END;
        """
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            schema = root / "schema.sql"
            database = root / "test.sqlite"
            schema.write_text(sql, encoding="utf-8")
            skipped = a_db_init.create_structure(database, schema, include_schema_data=False)

            self.assertEqual(skipped, 1)
            with sqlite3.connect(database) as connection:
                self.assertEqual(connection.execute("SELECT COUNT(*) FROM source").fetchone()[0], 0)
                connection.execute("INSERT INTO source(id, value) VALUES(2, 'runtime')")
                self.assertEqual(connection.execute("SELECT source_id FROM audit").fetchone()[0], 2)

    def test_commerce_free_seed_skips_business_sale_derivations(self) -> None:
        completed = subprocess.CompletedProcess(args=[], returncode=0)
        with (
            patch.object(sys, "argv", ["b0_db_seed.py", "--skip-commerce-seed"]),
            patch.object(b0_db_seed, "seed") as seed,
            patch.object(b0_db_seed, "seed_sale_opening_inventory") as opening_inventory,
            patch.object(b0_db_seed, "rebuild_seed_product_content_projections") as product_projections,
            patch.object(b0_db_seed, "sync_php_modules", return_value=0) as sync_modules,
            patch.object(b0_db_seed, "rebuild_public_projections", return_value=0) as public_projections,
            patch.object(b0_db_seed, "rebuild_storefront_projections") as storefront_projections,
            patch.object(b0_db_seed.subprocess, "run", return_value=completed),
        ):
            self.assertEqual(b0_db_seed.main(), 0)

        seed.assert_called_once_with(skip_core_seed=False)
        opening_inventory.assert_not_called()
        product_projections.assert_not_called()
        sync_modules.assert_called_once_with(required=True)
        public_projections.assert_called_once_with(required=True)
        storefront_projections.assert_not_called()

    def test_core_content_free_seed_keeps_technical_post_processing(self) -> None:
        completed = subprocess.CompletedProcess(args=[], returncode=0)
        with (
            patch.object(sys, "argv", ["b0_db_seed.py", "--skip-commerce-seed", "--skip-core-seed"]),
            patch.object(b0_db_seed, "seed") as seed,
            patch.object(b0_db_seed, "seed_sale_opening_inventory") as opening_inventory,
            patch.object(b0_db_seed, "rebuild_seed_product_content_projections") as product_projections,
            patch.object(b0_db_seed, "sync_php_modules", return_value=0) as sync_modules,
            patch.object(b0_db_seed, "rebuild_public_projections", return_value=0) as public_projections,
            patch.object(b0_db_seed, "rebuild_storefront_projections") as storefront_projections,
            patch.object(b0_db_seed.subprocess, "run", return_value=completed),
        ):
            self.assertEqual(b0_db_seed.main(), 0)

        seed.assert_called_once_with(skip_core_seed=True)
        opening_inventory.assert_not_called()
        product_projections.assert_not_called()
        sync_modules.assert_called_once_with(required=True)
        public_projections.assert_called_once_with(required=True)
        storefront_projections.assert_not_called()

    def test_core_runtime_bootstrap_has_no_editorial_content(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            database = Path(directory) / "core.sqlite"
            a_db_init.create_structure(database, ROOT / "database/schema/core.sql")

            b0_db_seed.seed_core_runtime_bootstrap(
                database,
                env_values={
                    "APP_NAME": "CMS vierge",
                    "APP_LOCALE": "fr",
                    "APP_BASE_PATH": "/custom/main",
                    "APP_PUBLIC_BASE_URL": "https://example.test/custom/main",
                },
            )

            with sqlite3.connect(database) as connection:
                self.assertEqual(connection.execute("SELECT COUNT(*) FROM sites").fetchone()[0], 1)
                self.assertEqual(connection.execute("SELECT COUNT(*) FROM languages").fetchone()[0], 1)
                self.assertEqual(
                    connection.execute("SELECT host, base_path FROM site_domains").fetchone(),
                    ("example.test", "/custom/main"),
                )
                for table in ("content_entries", "menus", "media_assets", "taxonomies"):
                    self.assertEqual(connection.execute(f"SELECT COUNT(*) FROM {table}").fetchone()[0], 0, table)

    def test_system_smoke_accepts_functional_core_without_demo_content(self) -> None:
        try:
            php = resolve_php_binary()
        except (FileNotFoundError, PermissionError) as exc:
            self.skipTest(str(exc))

        schemas = {
            "core.sqlite": ROOT / "database/schema/core.sql",
            "iam.sqlite": ROOT / "database/iam.sql",
            "forms.sqlite": ROOT / "database/modules/forms.sql",
            "cookies.sqlite": ROOT / "database/modules/cookies.sql",
        }
        with tempfile.TemporaryDirectory() as directory:
            database_dir = Path(directory)
            for filename, schema in schemas.items():
                a_db_init.create_structure(database_dir / filename, schema)
            b0_db_seed.seed_core_runtime_bootstrap(
                database_dir / "core.sqlite",
                env_values={
                    "APP_NAME": "CMS vierge",
                    "APP_LOCALE": "fr",
                    "APP_BASE_PATH": "/custom/main",
                    "APP_PUBLIC_BASE_URL": "https://example.test/custom/main",
                },
            )

            env = os.environ.copy()
            env["CMS_DATABASE_DIR"] = str(database_dir)
            env["APP_ENV"] = "test"
            process = subprocess.run(
                [php, "backend/bin/console", "system:smoke"],
                cwd=ROOT,
                env=env,
                text=True,
                capture_output=True,
                timeout=60,
            )

            self.assertEqual(process.returncode, 0, process.stdout + process.stderr)
            self.assertIn("Smoke OK", process.stdout)
            self.assertIn("contents=0 routes=0", process.stdout)


if __name__ == "__main__":
    unittest.main()
