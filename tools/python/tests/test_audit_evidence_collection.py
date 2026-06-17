from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path

from tools.python.operations.audit.a1_collect_evidence import collect
from tools.python.operations.audit.a2_verify_evidence import verify


class AuditEvidenceCollectionTest(unittest.TestCase):
    def test_collects_allowlisted_metadata_and_hashes_heavy_payloads(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            project = root / "project"
            run_dir = root / "run"
            (project / "docs/evaluation/machine-readable").mkdir(parents=True)
            (project / "storage/exports/v1").mkdir(parents=True)
            (project / "storage/backups").mkdir(parents=True)
            (project / "storage/logs").mkdir(parents=True)

            (project / "docs/evaluation/machine-readable/features.json").write_text('{"ok":true}\n')
            (project / "storage/exports/v1/release.json").write_text('{"version":"v1"}\n')
            (project / "storage/exports/v1/release.zip").write_bytes(b"release archive")
            (project / "storage/backups/evaluation.zip").write_bytes(b"backup archive")
            (project / "storage/logs/runtime.log").write_text("runtime\n")

            manifest = collect(project, run_dir)
            artifacts = run_dir / "artifacts"

            self.assertTrue((artifacts / "evaluation/machine-readable/features.json").is_file())
            self.assertTrue((artifacts / "release/exports/v1/release.json").is_file())
            self.assertTrue((artifacts / "runtime-logs/runtime.log").is_file())
            self.assertFalse(any(artifacts.rglob("*.zip")))
            self.assertEqual(manifest["totals"]["external_files"], 2)

            saved = json.loads((artifacts / "evidence-manifest.json").read_text())
            paths = {item["path"] for item in saved["external_artifacts"]}
            self.assertIn("storage/exports/v1/release.zip", paths)
            self.assertIn("storage/backups/evaluation.zip", paths)

    def test_verifier_rejects_nested_archives(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            run_dir = Path(tmp)
            (run_dir / "logs").mkdir()
            (run_dir / "artifacts").mkdir()
            (run_dir / "environment.txt").write_text("env\n")
            (run_dir / "summary.tsv").write_text("step\tstatus\n")
            (run_dir / "logs/step.log").write_text("ok\n")
            (run_dir / "artifacts/evidence-manifest.json").write_text(json.dumps({
                "policy": {
                    "archives_embedded": False,
                    "databases_embedded": False,
                    "backups_embedded": False,
                    "previous_audits_embedded": False,
                },
                "external_artifacts": [],
            }))
            self.assertEqual(verify(run_dir), [])
            (run_dir / "artifacts/old-audit.zip").write_bytes(b"nested")
            self.assertTrue(any("payload lourd interdit" in error for error in verify(run_dir)))

    def test_verifier_detects_modified_embedded_file(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            project = root / 'project'
            run_dir = root / 'run'
            (project / 'docs/evaluation/machine-readable').mkdir(parents=True)
            source = project / 'docs/evaluation/machine-readable/features.json'
            source.write_text('{"ok":true}\n')
            collect(project, run_dir)
            (run_dir / 'environment.txt').write_text('env\n')
            (run_dir / 'summary.tsv').write_text('step\tstatus\n')
            (run_dir / 'logs').mkdir()
            (run_dir / 'logs/step.log').write_text('ok\n')
            embedded = run_dir / 'artifacts/evaluation/machine-readable/features.json'
            embedded.write_text('{"ok":false}\n')
            self.assertTrue(any('checksum invalide' in error for error in verify(run_dir)))

    def test_run_audit_no_longer_recursively_copies_archives(self) -> None:
        root = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools/cms.py").is_file())
        source = (root / "tools/audit/run-audit.sh").read_text(encoding="utf-8")
        self.assertIn("a1_collect_evidence.py", source)
        self.assertNotIn("-name '*.zip'", source)
        self.assertNotIn("cp --parents", source)
        self.assertNotIn("cp storage/backups/evaluation.zip", source)
        self.assertIn("backup --output storage/backups/evaluation.zip", source)


if __name__ == "__main__":
    unittest.main()
