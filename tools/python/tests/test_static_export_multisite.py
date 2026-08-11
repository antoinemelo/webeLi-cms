from __future__ import annotations

import json
import os
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

from tools.python.cms.runtime import resolve_php_binary
from tools.python.tests.support.database_fixture import copy_full_seeded_databases

ROOT = Path(__file__).resolve().parents[3]


def php_binary() -> str | None:
    try:
        return resolve_php_binary()
    except (FileNotFoundError, PermissionError):
        return None


def php_supports_pdo_sqlite(binary: str) -> bool:
    result = subprocess.run([binary, "-m"], cwd=ROOT, text=True, capture_output=True, timeout=30)
    modules = {line.strip().lower() for line in result.stdout.splitlines()}
    return result.returncode == 0 and "pdo_sqlite" in modules


class StaticExportMultisiteTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        binary = php_binary()
        if binary is None or not php_supports_pdo_sqlite(binary):
            raise unittest.SkipTest("PHP avec pdo_sqlite requis pour tester l'export statique multisite")
        cls.database_holder, cls.database_dir = copy_full_seeded_databases(prefix="webeli-static-export-db-")
        cls.core_db = cls.database_dir / "core.sqlite"
        cls.pristine_core = cls.database_dir / "core.pristine.sqlite"
        shutil.copy2(cls.core_db, cls.pristine_core)
        cls.env = os.environ.copy()
        cls.env["CMS_PHP_BINARY"] = binary
        cls.env["CMS_DATABASE_DIR"] = str(cls.database_dir)

    @classmethod
    def tearDownClass(cls) -> None:
        cls.database_holder.cleanup()

    def setUp(self) -> None:
        shutil.copy2(self.pristine_core, self.core_db)

    def tearDown(self) -> None:
        shutil.copy2(self.pristine_core, self.core_db)

    @classmethod
    def restore_demo_multisite_fixture(cls) -> None:
        with sqlite3.connect(cls.core_db) as con:
            con.execute("UPDATE site_languages SET url_prefix = '' WHERE site_id = 10 AND language_code = 'fr'")
            con.execute("UPDATE site_languages SET url_prefix = '/en' WHERE site_id = 10 AND language_code = 'en'")
            con.execute(
                """
                UPDATE routes
                   SET full_path = '/news', slug = 'news', status = 'active'
                 WHERE site_id = 10
                   AND language_code = 'en'
                   AND (full_path IN ('/', '/news') OR slug IN ('accueil', 'news'))
                """
            )
            con.commit()

    def run_export(self, output_dir: Path) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [sys.executable, str(ROOT / "tools" / "cms.py"), "export", "--all-languages", "--output", str(output_dir)],
            cwd=ROOT,
            env=self.env,
            text=True,
            capture_output=True,
            timeout=180,
        )

    def load_report(self, output_dir: Path) -> dict:
        report = output_dir / "static-export-report.json"
        self.assertTrue(report.is_file(), f"Rapport absent: {report}")
        with report.open("r", encoding="utf-8") as handle:
            return json.load(handle)

    def test_multisite_multilingual_export_has_isolated_output_paths(self) -> None:
        with tempfile.TemporaryDirectory(prefix="webeli-static-export-") as tmp:
            output_dir = Path(tmp) / "export"
            result = self.run_export(output_dir)
            self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
            report = self.load_report(output_dir)
            output_plan = report["output_plan"]

            self.assertEqual(output_plan["collision_count"], 0, output_plan["collisions"])
            self.assertEqual(output_plan["invalid_output_path_count"], 0, output_plan["invalid_output_paths"])
            self.assertEqual(output_plan["output_paths_total"], output_plan["unique_output_paths"])

            sites = {site["site_key"]: site for site in output_plan["sites"]}
            self.assertGreaterEqual(set(sites), {"main", "site_a", "site_b"})
            languages = {route["language_code"] for route in report["routes_exported"]}
            self.assertGreaterEqual(languages, {"fr", "en", "de"})

            for site_key in ("main", "site_a", "site_b"):
                prefix = sites[site_key]["output_prefix"]
                self.assertTrue(prefix, f"Préfixe de sortie absent pour {site_key}")
                site_routes = [route for route in report["routes_exported"] if route["site_key"] == site_key]
                self.assertTrue(site_routes, f"Aucune route exportée pour {site_key}")
                for route in site_routes:
                    self.assertTrue(
                        route["output_path"].startswith(prefix + "/"),
                        f"Route non isolée pour {site_key}: {route['output_path']} / {prefix}",
                    )

    def test_unresolved_output_path_collision_fails_before_html_write(self) -> None:
        with sqlite3.connect(self.core_db) as con:
            # Simule une régression de configuration : deux langues actives du même site
            # publient volontairement une route vers le même output_path.
            con.execute("UPDATE site_languages SET url_prefix = '/actualites' WHERE site_id = 10 AND language_code = 'en'")
            con.execute("UPDATE routes SET full_path = '/' WHERE site_id = 10 AND language_code = 'en' AND full_path = '/news'")
            con.commit()

        with tempfile.TemporaryDirectory(prefix="webeli-static-export-collision-") as tmp:
            output_dir = Path(tmp) / "export"
            result = self.run_export(output_dir)
            self.assertNotEqual(result.returncode, 0, "L'export doit échouer lorsqu'une collision output_path est détectée")
            report = self.load_report(output_dir)
            output_plan = report["output_plan"]
            self.assertGreater(output_plan["collision_count"], 0, output_plan)
            self.assertTrue(report["errors"], "Le rapport doit expliciter l'erreur de collision")
            public_dir = output_dir / "public"
            written_html = list(public_dir.rglob("*.html")) if public_dir.is_dir() else []
            self.assertEqual(written_html, [], "Aucune page HTML ne doit être écrite après une collision non résolue")


if __name__ == "__main__":
    unittest.main()
