from __future__ import annotations

import sqlite3
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[3]
SCRIPT = ROOT / "tools/python/operations/deployment/d14_clone_instance.py"


class InstanceCloneTest(unittest.TestCase):
    def run_clone(self, *args: str) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [sys.executable, str(SCRIPT), *args],
            cwd=ROOT,
            text=True,
            capture_output=True,
        )

    def make_source(self, base: Path) -> Path:
        source = base / "dev"
        (source / "ops").mkdir(parents=True)
        (source / "config").mkdir()
        (source / "storage/database").mkdir(parents=True)
        (source / "storage/cache").mkdir(parents=True)
        (source / "storage/audit-results/archive").mkdir(parents=True)
        (source / "storage/backups/sqlite/archive").mkdir(parents=True)
        (source / "vendor/twig").mkdir(parents=True)
        (source / "backend/vendor/composer").mkdir(parents=True)
        (source / "backend/bootstrap").mkdir(parents=True)
        (source / "backend/src/Application/Maintenance").mkdir(parents=True)
        (source / "docs").mkdir()
        (source / ".git").mkdir()

        (source / "ops/.env").write_text(
            "\n".join(
                [
                    "APP_ENV=staging",
                    "APP_DEBUG=0",
                    "APP_BASE_PATH=/cms",
                    "APP_PUBLIC_BASE_URL=https://webe.li/cms",
                    "APP_VUE_NODE_MODULES_PATH=./vendor/node_modules/",
                    "APP_TWIG_VENDOR_PATH=./vendor/twig/",
                    f"ABS_PATH={source}",
                ]
            )
            + "\n",
            encoding="utf-8",
        )
        (source / "config/modules.php").write_text(
            "return ['/cms/admin', '/modules', 'database/modules', 'config/modules.php'];\n",
            encoding="utf-8",
        )
        (source / "docs/ignored.md").write_text("ignored", encoding="utf-8")
        (source / ".git/config").write_text("ignored", encoding="utf-8")
        (source / "vendor/twig/autoload.php").write_text("ignored", encoding="utf-8")
        (source / "backend/vendor/composer/autoload.php").write_text("ignored", encoding="utf-8")
        (source / "backend/bootstrap/runtime.php").write_text(
            "<?php\nreturn dirname($projectRoot) . '/cms/vendor';\n",
            encoding="utf-8",
        )
        (source / "backend/src/Application/Maintenance/DependencyInventoryService.php").write_text(
            "<?php\nreturn base_path('../cms/vendor');\n",
            encoding="utf-8",
        )
        (source / "storage/audit-results/report.json").write_text("ignored", encoding="utf-8")
        (source / "storage/audit-results/archive/evidence.txt").write_text("ignored", encoding="utf-8")
        (source / "storage/backups/sqlite/core.sqlite").write_text("ignored", encoding="utf-8")
        (source / "storage/backups/sqlite/archive/core.sqlite.gz").write_text("ignored", encoding="utf-8")

        db_path = source / "storage/database/core.sqlite"
        with sqlite3.connect(db_path) as connection:
            connection.execute("CREATE TABLE pages (id INTEGER PRIMARY KEY, body TEXT)")
            connection.execute("CREATE TABLE site_domains (id INTEGER PRIMARY KEY, base_path TEXT)")
            connection.execute(
                "INSERT INTO pages (body) VALUES (?)",
                (f"https://webe.li/cms/page stored at {source}",),
            )
            connection.execute("INSERT INTO site_domains (id, base_path) VALUES (1, '/cms')")
        return source

    @staticmethod
    def env_values(path: Path) -> dict[str, str]:
        return {
            key: value
            for line in path.read_text(encoding="utf-8").splitlines()
            if line and not line.startswith("#") and "=" in line
            for key, value in [line.split("=", 1)]
        }

    def test_clone_can_keep_source_public_base_path_with_different_directory_name(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = self.make_source(root)
            destination = root / "mod2"

            process = self.run_clone(
                "--source",
                str(source),
                "--destination",
                str(destination),
                "--new-base-path",
                "/cms",
            )

            self.assertEqual(process.returncode, 0, process.stderr)
            env = self.env_values(destination / "ops/.env")
            self.assertEqual(env["APP_BASE_PATH"], "/cms")
            self.assertEqual(env["APP_PUBLIC_BASE_URL"], "https://webe.li/cms")
            self.assertEqual(env["APP_VUE_NODE_MODULES_PATH"], "./vendor/node_modules/")
            self.assertEqual(env["APP_TWIG_VENDOR_PATH"], "./vendor/twig/")
            self.assertEqual(env["ABS_PATH"], str(destination))

            modules = (destination / "config/modules.php").read_text(encoding="utf-8")
            self.assertIn("/modules", modules)
            self.assertIn("database/modules", modules)
            self.assertIn("config/modules.php", modules)
            self.assertNotIn("mod2ules", modules)

            self.assertFalse((destination / "docs/ignored.md").exists())
            self.assertFalse((destination / ".git/config").exists())
            self.assertFalse((destination / "vendor").exists())
            self.assertFalse((destination / "backend/vendor").exists())
            self.assertFalse((destination / "storage/audit-results").exists())
            self.assertFalse((destination / "storage/backups/sqlite").exists())

            self.assertIn(
                "/cms/vendor",
                (destination / "backend/bootstrap/runtime.php").read_text(encoding="utf-8"),
            )
            self.assertIn(
                "../cms/vendor",
                (
                    destination
                    / "backend/src/Application/Maintenance/DependencyInventoryService.php"
                ).read_text(encoding="utf-8"),
            )

            with sqlite3.connect(destination / "storage/database/core.sqlite") as connection:
                body = connection.execute("SELECT body FROM pages WHERE id = 1").fetchone()[0]
            self.assertIn("https://webe.li/cms/page", body)
            self.assertIn(str(destination), body)

    def test_clone_reads_source_env_base_path_and_rewrites_nested_instance_urls(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = self.make_source(root)
            destination = root / "main"

            with sqlite3.connect(source / "storage/database/core.sqlite") as connection:
                connection.execute(
                    "INSERT INTO pages (body) VALUES (?)",
                    ('{"image":"/cms/storage/media/public/hero.webp","url":"/cms/contact"}',),
                )

            process = self.run_clone(
                "--source",
                str(source),
                "--destination",
                str(destination),
                "--new-base-path",
                "/cms/main",
                "--new-public-base-url",
                "https://webe.li/cms/main",
            )

            self.assertEqual(process.returncode, 0, process.stderr)
            self.assertIn("Source public base path: /cms", process.stdout)

            env = self.env_values(destination / "ops/.env")
            self.assertEqual(env["APP_BASE_PATH"], "/cms/main")
            self.assertEqual(env["APP_PUBLIC_BASE_URL"], "https://webe.li/cms/main")

            with sqlite3.connect(destination / "storage/database/core.sqlite") as connection:
                domain_base_path = connection.execute(
                    "SELECT base_path FROM site_domains WHERE id = 1"
                ).fetchone()[0]
                bodies = [row[0] for row in connection.execute("SELECT body FROM pages ORDER BY id")]

            self.assertEqual(domain_base_path, "/cms/main")
            self.assertIn("https://webe.li/cms/main/page", bodies[0])
            self.assertIn("/cms/main/storage/media/public/hero.webp", bodies[1])
            self.assertIn("/cms/main/contact", bodies[1])

            runtime = (destination / "backend/bootstrap/runtime.php").read_text(encoding="utf-8")
            self.assertIn("/cms/vendor", runtime)
            self.assertNotIn("/cms/main/vendor", runtime)


if __name__ == "__main__":
    unittest.main()
