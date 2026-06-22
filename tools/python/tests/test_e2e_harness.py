from __future__ import annotations

import sqlite3
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from tools.python.operations.testing import run_playwright_e2e as harness


class E2EHarnessTest(unittest.TestCase):
    def test_partial_external_configuration_is_rejected(self) -> None:
        with patch.dict(
            harness.os.environ,
            {"E2E_BASE_URL": "http://example.test", "E2E_ADMIN_EMAIL": "", "E2E_ADMIN_PASSWORD": ""},
            clear=False,
        ):
            with self.assertRaisesRegex(RuntimeError, "[Cc]onfiguration E2E externe incomplète"):
                harness.run_external(headed=False)

    def test_site_is_normalized_only_inside_target_instance(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            instance = Path(directory)
            database_dir = instance / "storage/database"
            database_dir.mkdir(parents=True)
            with sqlite3.connect(database_dir / "core.sqlite") as connection:
                connection.executescript(
                    """
                    CREATE TABLE sites(id INTEGER PRIMARY KEY, site_key TEXT);
                    CREATE TABLE site_domains(
                        site_id INTEGER, host TEXT, base_path TEXT, scheme TEXT,
                        enforce_https INTEGER, canonical_host_strategy TEXT,
                        is_primary INTEGER, updated_at TEXT
                    );
                    INSERT INTO sites VALUES(1, 'main');
                    INSERT INTO site_domains VALUES(1, 'production.test', '/cms', 'https', 1, 'primary', 1, NULL);
                    """
                )
            harness.normalize_e2e_site(instance)
            with sqlite3.connect(database_dir / "core.sqlite") as connection:
                row = connection.execute(
                    "SELECT host, base_path, scheme, enforce_https, canonical_host_strategy FROM site_domains"
                ).fetchone()
            self.assertEqual(("127.0.0.1", "", "http", 0, "none"), row)

    def test_php_router_serves_existing_assets_directly(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            router = harness.create_php_router(Path(directory))
            source = router.read_text(encoding="utf-8")
            self.assertIn("is_file($file)", source)
            self.assertIn("return false", source)
            self.assertIn("SCRIPT_NAME", source)
            self.assertIn("/index.php", source)


if __name__ == "__main__":
    unittest.main()
