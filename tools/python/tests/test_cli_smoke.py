from __future__ import annotations

import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
CMS = ROOT / "tools" / "cms.py"


class CliSmokeTest(unittest.TestCase):
    def run_cli(self, *args: str) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [sys.executable, str(CMS), *args],
            cwd=ROOT,
            text=True,
            capture_output=True,
            timeout=30,
        )

    def test_main_help(self) -> None:
        result = self.run_cli("--help")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("instance", result.stdout)
        self.assertIn("migrate", result.stdout)

    def test_instance_update_help(self) -> None:
        result = self.run_cli("instance", "update", "--help")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("--source", result.stdout)
        self.assertIn("--target", result.stdout)

    def test_migrate_module_help_mentions_module(self) -> None:
        result = self.run_cli("migrate", "--help")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("--module", result.stdout)


if __name__ == "__main__":
    unittest.main()
