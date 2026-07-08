from __future__ import annotations

import hashlib
import json
import subprocess
import sys
import unittest
from pathlib import Path

from tools.python.lib.database_inventory import database_specs, module_keys
from tools.python.operations.deployment import d8_deploy_web_update as deploy

ROOT = Path(__file__).resolve().parents[3]
CMS = ROOT / "tools" / "cms.py"


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


class ModuleFoundationTest(unittest.TestCase):
    def test_client_notes_example_manifest_is_not_activated(self) -> None:
        manifest = ROOT / "examples/modules/client-notes/module.json"
        self.assertTrue(manifest.is_file())
        data = json.loads(manifest.read_text(encoding="utf-8"))
        self.assertEqual(data["type"], "client")
        self.assertFalse(data["enabled_by_default"])
        self.assertTrue(data["protected_on_core_update"])
        keys = {spec.module_key for spec in database_specs(root=ROOT)}
        self.assertNotIn("client-notes", keys)

    def test_system_modules_remain_discoverable_for_migrations(self) -> None:
        self.assertIn("forms", module_keys(root=ROOT))
        self.assertIn("ai-assistant", module_keys(root=ROOT))
        self.assertIn("sale", module_keys(root=ROOT))

    def test_deployment_protects_local_modules_and_local_config(self) -> None:
        self.assertTrue(deploy.is_protected("local/modules/client-x/module.json"))
        self.assertTrue(deploy.is_protected("ops/modules.local.json"))
        self.assertFalse(deploy.is_protected("backend/src/Modules/Forms/module.json"))

    def test_migrate_plan_does_not_modify_sqlite_files(self) -> None:
        dbs = sorted((ROOT / "storage/database").glob("*.sqlite"))
        before = {path.name: sha256(path) for path in dbs}
        result = subprocess.run(
            [sys.executable, str(CMS), "migrate", "--plan"],
            cwd=ROOT,
            text=True,
            capture_output=True,
            timeout=60,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        after = {path.name: sha256(path) for path in dbs}
        self.assertEqual(before, after)

    def test_migrate_apply_requires_backup_or_explicit_risk(self) -> None:
        result = subprocess.run(
            [sys.executable, str(CMS), "migrate", "--apply", "--yes"],
            cwd=ROOT,
            text=True,
            capture_output=True,
            timeout=30,
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("--backup", result.stderr + result.stdout)


if __name__ == "__main__":
    unittest.main()
