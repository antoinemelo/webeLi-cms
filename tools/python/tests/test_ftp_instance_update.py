from __future__ import annotations

import tempfile
import unittest
from pathlib import Path

from tools.python.lib.deploylib import compute_delta
from tools.python.operations.deployment import d3_deploy_ftp


class FtpInstanceUpdateTest(unittest.TestCase):
    def test_instance_profile_transfers_only_changed_application_files(self) -> None:
        previous = {
            "backend/src/App.php": {"sha256": "old"},
            "storage/database/core.sqlite": {"sha256": "live-db"},
            "storage/media/logo.webp": {"sha256": "live-media"},
            "ops/.env": {"sha256": "live-secret"},
        }
        current = {
            "backend/src/App.php": {"sha256": "new"},
            "storage/database/core.sqlite": {"sha256": "release-db"},
            "storage/media/logo.webp": {"sha256": "release-media"},
            "ops/.env": {"sha256": "release-env"},
        }
        protected = tuple(sorted(set(d3_deploy_ftp.PROTECTED_PREFIXES + d3_deploy_ftp.INSTANCE_DATA_PREFIXES)))

        added, changed, removed = compute_delta(previous, current, protected)

        self.assertEqual(added, [])
        self.assertEqual(changed, ["backend/src/App.php"])
        self.assertEqual(removed, [])

    def test_locked_plan_rejects_a_changed_stage_or_manifest(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            manifest = root / "manifest.json"
            manifest.write_text('{"files": {}}\n', encoding="utf-8")
            files = {"backend/src/App.php": {"sha256": "one", "size": 12}}
            release = {"release_id": "release-1"}
            expected = d3_deploy_ftp.build_locked_plan(
                stage_dir=root,
                remote_root="/www/client",
                release_manifest=release,
                manifest_path=manifest,
                current_files=files,
                uploads_new=["backend/src/App.php"],
                uploads_changed=[],
                removals=[],
                protect_instance_data=True,
                no_delete_removed=False,
            )
            changed = dict(expected)
            changed["stage_fingerprint"] = "different"

            with self.assertRaises(d3_deploy_ftp.DeployError):
                d3_deploy_ftp.validate_locked_plan(expected, changed)


if __name__ == "__main__":
    unittest.main()
