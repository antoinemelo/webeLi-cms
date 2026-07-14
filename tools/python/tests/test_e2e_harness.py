from __future__ import annotations

import sqlite3
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from tools.python.operations.testing import run_playwright_e2e as harness


class E2EHarnessTest(unittest.TestCase):
    def test_targeted_e2e_gates_are_mutually_exclusive(self) -> None:
        with self.assertRaisesRegex(RuntimeError, "mutuellement exclusifs"):
            harness.run_playwright({}, headed=False, omnichannel_only=True, usability_only=True)

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

    def test_blueprint_fixtures_are_created_only_inside_target_instance(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            instance = Path(directory)
            database_dir = instance / "storage/database"
            database_dir.mkdir(parents=True)
            database = database_dir / "core.sqlite"
            with sqlite3.connect(database) as connection:
                connection.executescript(
                    """
                    CREATE TABLE languages(code TEXT PRIMARY KEY, name TEXT, locale TEXT);
                    CREATE TABLE sites(
                        id INTEGER PRIMARY KEY AUTOINCREMENT, site_key TEXT UNIQUE,
                        name TEXT, default_language_code TEXT, is_active INTEGER DEFAULT 1
                    );
                    CREATE TABLE site_languages(
                        id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER,
                        language_code TEXT, locale TEXT, url_prefix TEXT,
                        hreflang_code TEXT, fallback_language_code TEXT,
                        is_default INTEGER, is_active INTEGER, is_rtl INTEGER,
                        sort_order INTEGER, UNIQUE(site_id, language_code)
                    );
                    CREATE TABLE site_domains(
                        id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER,
                        host TEXT, base_path TEXT, scheme TEXT, is_primary INTEGER,
                        is_active INTEGER, enforce_https INTEGER,
                        canonical_host_strategy TEXT, UNIQUE(host, base_path)
                    );
                    CREATE TABLE blueprints(
                        id INTEGER PRIMARY KEY AUTOINCREMENT, blueprint_key TEXT,
                        resource_type TEXT, site_id INTEGER, label TEXT,
                        description TEXT, is_active INTEGER, active_version_id INTEGER
                    );
                    CREATE UNIQUE INDEX test_blueprints_global_key
                        ON blueprints(blueprint_key, resource_type) WHERE site_id IS NULL;
                    CREATE UNIQUE INDEX test_blueprints_site_key
                        ON blueprints(site_id, blueprint_key, resource_type) WHERE site_id IS NOT NULL;
                    CREATE TABLE blueprint_versions(
                        id INTEGER PRIMARY KEY AUTOINCREMENT, blueprint_id INTEGER,
                        version INTEGER, version_label TEXT, status TEXT,
                        schema_json TEXT, ui_schema_json TEXT, validation_json TEXT,
                        seo_policy_json TEXT, routing_policy_json TEXT,
                        workflow_policy_json TEXT, translation_policy_json TEXT,
                        permissions_policy_json TEXT, checksum_sha256 TEXT,
                        is_active INTEGER, activated_at TEXT
                    );
                    CREATE TABLE blueprint_sections(
                        id INTEGER PRIMARY KEY AUTOINCREMENT, blueprint_id INTEGER,
                        section_key TEXT, label TEXT, description TEXT,
                        layout TEXT, sort_order INTEGER
                    );
                    CREATE TABLE blueprint_fields(
                        id INTEGER PRIMARY KEY AUTOINCREMENT, blueprint_id INTEGER,
                        section_id INTEGER, field_handle TEXT, field_type TEXT,
                        label TEXT, field_purpose TEXT, width INTEGER,
                        is_required INTEGER, is_localized INTEGER, is_system INTEGER,
                        is_deletable INTEGER, sort_order INTEGER, options_json TEXT,
                        validation_json TEXT, conditions_json TEXT, config_json TEXT
                    );
                    """
                )
                connection.execute("INSERT INTO languages VALUES('fr', 'Français', 'fr-CH')")
                connection.execute(
                    "INSERT INTO sites(site_key, name, default_language_code) VALUES('main', 'Site principal', 'fr')"
                )

            harness.create_blueprint_e2e_fixtures(instance)

            with sqlite3.connect(database) as connection:
                sites = connection.execute(
                    "SELECT site_key FROM sites ORDER BY site_key"
                ).fetchall()
                blueprints = connection.execute(
                    """
                    SELECT blueprint_key, site_id IS NULL, COUNT(*)
                    FROM blueprints
                    GROUP BY blueprint_key, site_id IS NULL
                    ORDER BY blueprint_key, site_id IS NULL
                    """
                ).fetchall()
                field_types = connection.execute(
                    """
                    SELECT DISTINCT field_type FROM blueprint_fields
                    WHERE field_handle LIKE 'e2e_%' ORDER BY field_type
                    """
                ).fetchall()

            self.assertEqual([("e2e_secondary",), ("main",)], sites)
            self.assertEqual(
                [("000_e2e_fixture", 0, 1), ("000_e2e_fixture", 1, 1), ("001_e2e_secondary", 0, 1)],
                blueprints,
            )
            self.assertEqual([("media",), ("select",), ("text",), ("textarea",)], field_types)


if __name__ == "__main__":
    unittest.main()
