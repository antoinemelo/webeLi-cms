from __future__ import annotations

import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
CLI = ROOT / "tools/cms.py"
MANIFEST_EXCLUDED_PARTS = {".git", ".venv", "node_modules", "vendor", "storage", "admin-app", "__pycache__"}


class CliRationalizationTest(unittest.TestCase):
    def run_cli(self, *args: str, cwd: str | Path | None = None) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [sys.executable, str(CLI), *args],
            cwd=cwd or ROOT,
            text=True,
            capture_output=True,
        )

    def test_help(self) -> None:
        process = self.run_cli("--help")
        self.assertEqual(process.returncode, 0)
        self.assertIn("rebuild", process.stdout)
        self.assertIn("release", process.stdout)

    def test_unknown_command(self) -> None:
        self.assertNotEqual(self.run_cli("unknown").returncode, 0)

    def test_dry_run_from_other_directory_with_spaces(self) -> None:
        with tempfile.TemporaryDirectory(prefix="dec cms ") as directory:
            process = self.run_cli(
                "--root",
                str(ROOT),
                "--dry-run",
                "backup",
                cwd=directory,
            )
        self.assertEqual(process.returncode, 0, process.stderr)
        self.assertIn("DRY-RUN", process.stdout)

    def test_manifest_is_exhaustive(self) -> None:
        data = json.loads(
            (ROOT / "tools/python/tool-manifest.json").read_text(encoding="utf-8")
        )
        listed = {item["path"] for item in data["tools"]}
        actual = {
            path.relative_to(ROOT).as_posix()
            for path in ROOT.glob("tools/**/*.py")
            if not any(part in MANIFEST_EXCLUDED_PARTS for part in path.parts)
        }
        self.assertEqual(listed, actual)

    def test_archive_not_imported_by_active_python(self) -> None:
        for path in ROOT.glob("tools/python/**/*.py"):
            if "archive" in path.parts or path == Path(__file__):
                continue
            self.assertNotIn(
                "tools.python.archive",
                path.read_text(encoding="utf-8", errors="ignore"),
                str(path),
            )

    def test_validation_modes_have_distinct_plans(self) -> None:
        fast = json.loads(self.run_cli("--json", "--dry-run", "validate").stdout)
        full = json.loads(
            self.run_cli("--json", "--dry-run", "validate", "--full").stdout
        )
        slow = json.loads(
            self.run_cli(
                "--json",
                "--dry-run",
                "validate",
                "--full",
                "--with-slow",
            ).stdout
        )
        fast_names = {row["name"] for row in fast["validators"]}
        full_names = {row["name"] for row in full["validators"]}
        slow_names = {row["name"] for row in slow["validators"]}
        self.assertNotIn("REBUILD_FROM_SCRATCH", fast_names)
        self.assertNotIn("REBUILD_FROM_SCRATCH", full_names)
        self.assertNotIn("REBUILD_FROM_SCRATCH", slow_names)
        self.assertNotIn("BACKUP_RESTORE_ROUNDTRIP", full_names)
        self.assertIn("BACKUP_RESTORE_ROUNDTRIP", slow_names)
        self.assertLess(len(fast_names), len(full_names))
        self.assertLess(len(full_names), len(slow_names))

    def test_rebuild_restores_private_storage_protection(self) -> None:
        from unittest.mock import patch

        from tools.python.operations.database import a_db_init

        with tempfile.TemporaryDirectory() as directory:
            temporary_root = Path(directory)
            database = temporary_root / "database"
            logs = temporary_root / "logs"
            backups = temporary_root / "backups"
            with (
                patch.object(
                    a_db_init,
                    "MEDIA_STORAGE_DIRS",
                    [database, logs, backups],
                ),
                patch.object(
                    a_db_init,
                    "PRIVATE_STORAGE_DIRS",
                    [database, logs, backups],
                ),
            ):
                a_db_init.ensure_storage_dirs()

            for protected_directory in (database, logs, backups):
                protection = (protected_directory / ".htaccess").read_text(
                    encoding="utf-8"
                )
                self.assertIn("Options -Indexes", protection)
                self.assertIn("Require all denied", protection)

    def test_release_chain_uses_release_type_specific_quality_gate(self) -> None:
        source = (
            ROOT / "tools/python/operations/deployment/d_deploy.py"
        ).read_text(encoding="utf-8")
        self.assertIn(
            '("audit", "--profile", "release", "--build")',
            source,
        )
        self.assertIn(
            '("qualify", "--profile", "release")',
            source,
        )
        self.assertIn('release_type in {"minor", "major"}', source)
        self.assertIn('Step("Association release et preuves", "d13_bind_release_evidence.py")', source)
        self.assertNotIn(
            'Step("Porte de qualité release", "d12_quality_gate.py")',
            source,
        )
        self.assertLess(
            source.index("steps = [quality]"),
            source.index('steps.append(Step("Préflight local"'),
        )


    def test_release_failure_message_points_to_quality_and_audit_reports(self) -> None:
        source = (
            ROOT / "tools/python/operations/deployment/d_deploy.py"
        ).read_text(encoding="utf-8")
        self.assertIn("un contrôle requis", source)
        self.assertIn("pas pu être démontré", source)
        self.assertIn("storage/qualification", source)
        self.assertIn("storage/audit-results", source)

    def test_slow_mode_contains_production_qualifications(self) -> None:
        from tools.python.validation.registry import select

        names = {item.name for item in select(mode="slow")}
        self.assertIn("RUNTIME_INTEGRITY", names)
        self.assertIn("STATIC_EXPORT_DRY_RUN", names)
        self.assertIn("BACKUP_RESTORE_ROUNDTRIP", names)

    def test_qualification_exposes_only_three_profiles(self) -> None:
        process = self.run_cli("qualify", "--help")
        self.assertEqual(process.returncode, 0, process.stderr)
        self.assertIn("{quick,complete,release}", process.stdout)
        self.assertNotIn("standard", process.stdout)

    def test_test_command_exposes_duration_controls(self) -> None:
        process = self.run_cli("test", "--help")
        self.assertEqual(process.returncode, 0, process.stderr)
        self.assertIn("--timeout", process.stdout)
        self.assertIn("--target-duration", process.stdout)


if __name__ == "__main__":
    unittest.main()
