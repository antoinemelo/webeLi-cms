from __future__ import annotations

import unittest
from pathlib import Path

from tools.python.lib.release_metadata import load_release_metadata


ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())


class AuditAndReleaseExportLayoutTest(unittest.TestCase):
    def test_audit_scripts_are_grouped_under_tools_audit(self) -> None:
        self.assertTrue((ROOT / "tools/audit/audit.sh").is_file())
        self.assertTrue((ROOT / "tools/audit/run-audit.sh").is_file())
        self.assertFalse((ROOT / "tools/audit.sh").exists())
        self.assertFalse((ROOT / "audit/run-audit.sh").exists())

    def test_audit_dockerfile_uses_grouped_entrypoint(self) -> None:
        source = (ROOT / "Dockerfile.audit").read_text(encoding="utf-8")
        self.assertIn("COPY tools/audit/run-audit.sh /usr/local/bin/run-cms-audit", source)

    def test_release_tools_use_version_directory(self) -> None:
        metadata = load_release_metadata()
        version = metadata.technical_version
        expected = f'storage" / "exports" / metadata.technical_version'
        verifier = (ROOT / "tools/python/operations/deployment/d4_verify_release_archive.py").read_text(encoding="utf-8")
        binder = (ROOT / "tools/python/operations/deployment/d13_bind_release_evidence.py").read_text(encoding="utf-8")
        packager = (ROOT / "tools/python/operations/deployment/d2_package_release.py").read_text(encoding="utf-8")
        self.assertIn(expected, verifier)
        self.assertIn("release_dir = EXPORTS / metadata.technical_version", binder)
        self.assertIn("DIST_DIR / release_metadata.technical_version", packager)
        self.assertTrue(version)


if __name__ == "__main__":
    unittest.main()
