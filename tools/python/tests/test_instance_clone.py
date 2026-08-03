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
        source = base / "mod"
        (source / "ops").mkdir(parents=True)
        (source / "config").mkdir()
        (source / "storage/database").mkdir(parents=True)
        (source / "storage/cache").mkdir(parents=True)
        (source / "docs").mkdir()
        (source / ".git").mkdir()

        (source / "ops/.env").write_text(
            "\n".join(
                [
                    "APP_ENV=staging",
                    "APP_DEBUG=0",
                    "APP_BASE_PATH=/cms",
                    "APP_PUBLIC_BASE_URL=https://webe.li/cms",
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

        db_path = source / "storage/database/core.sqlite"
        with sqlite3.connect(db_path) as connection:
            connection.execute("CREATE TABLE pages (id INTEGER PRIMARY KEY, body TEXT)")
            connection.execute(
                "INSERT INTO pages (body) VALUES (?)",
                (f"https://webe.li/cms/page stored at {source}",),
            )
        return source

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
            env = (destination / "ops/.env").read_text(encoding="utf-8")
            self.assertIn("APP_BASE_PATH=/cms", env)
            self.assertIn("APP_PUBLIC_BASE_URL=https://webe.li/cms", env)
            self.assertIn(f"ABS_PATH={destination}", env)

            modules = (destination / "config/modules.php").read_text(encoding="utf-8")
            self.assertIn("/modules", modules)
            self.assertIn("database/modules", modules)
            self.assertIn("config/modules.php", modules)
            self.assertNotIn("mod2ules", modules)

            self.assertFalse((destination / "docs/ignored.md").exists())
            self.assertFalse((destination / ".git/config").exists())

            with sqlite3.connect(destination / "storage/database/core.sqlite") as connection:
                body = connection.execute("SELECT body FROM pages WHERE id = 1").fetchone()[0]
            self.assertIn("https://webe.li/cms/page", body)
            self.assertIn(str(destination), body)


if __name__ == "__main__":
    unittest.main()
