from __future__ import annotations

import json
import subprocess
import sys
import tempfile
import unittest
import zipfile
from pathlib import Path


ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
SCRIPT = ROOT / "tools/python/operations/deployment/d15_package_stable_components.py"


class StableComponentPackagerTest(unittest.TestCase):
    def test_core_and_module_ownership_are_separated(self) -> None:
        with tempfile.TemporaryDirectory(prefix="dec-component-packager-") as temporary:
            base = Path(temporary)
            stage = base / "stage"
            output = base / "output"
            files = {
                "config/release.json": json.dumps({"technical_version": "dec_v99-e01a"}),
                "index.php": "<?php",
                "backend/public/index.php": "<?php",
                "backend/bootstrap/runtime.php": "<?php",
                "backend/bin/console": "#!/usr/bin/env php",
                "backend/src/Core/App.php": "<?php",
                "backend/src/Modules/Sale/module.json": json.dumps(
                    {
                        "key": "sale",
                        "name": "Ventes",
                        "version": "1.2.3",
                        "provider_file": "backend/src/Modules/Sale/SaleModuleProvider.php",
                        "databases": [
                            {
                                "key": "sale",
                                "schema": "database/modules/sale.sql",
                                "migrations": "database/migrations/sale",
                            }
                        ],
                    }
                ),
                "backend/src/Modules/Sale/SaleModuleProvider.php": "<?php",
                "database/modules/sale.sql": "CREATE TABLE sale_test(id INTEGER);",
                "database/migrations/sale/0001.sql": "SELECT 1;",
                "database/migrations/core/0001.sql": "SELECT 1;",
                "database/migrations/iam/0001.sql": "SELECT 1;",
                "admin-app/index.html": "<!doctype html>",
                "storage/database/core.sqlite": "never packaged",
                "local/custom.php": "never packaged",
            }
            for relative, contents in files.items():
                path = stage / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(contents, encoding="utf-8")

            completed = subprocess.run(
                [
                    sys.executable,
                    str(SCRIPT),
                    "--stage",
                    str(stage),
                    "--output",
                    str(output),
                    "--release-tag",
                    "dec_v99-e01a",
                ],
                cwd=ROOT,
                text=True,
                capture_output=True,
            )
            self.assertEqual(0, completed.returncode, completed.stderr)
            catalog = json.loads((output / "dec-cms-stable.json").read_text(encoding="utf-8"))
            self.assertEqual("dec_v99-e01a", catalog["core"]["version"])
            self.assertEqual(["sale"], [module["key"] for module in catalog["modules"]])
            self.assertEqual(["sale"], catalog["modules"][0]["database_keys"])

            with zipfile.ZipFile(output / "core-dec_v99-e01a.zip") as archive:
                core_names = set(archive.namelist())
            self.assertIn("core-core/backend/src/Core/App.php", core_names)
            self.assertNotIn("core-core/backend/src/Modules/Sale/module.json", core_names)
            self.assertFalse(any(name.endswith("core.sqlite") for name in core_names))
            self.assertNotIn("core-core/local/custom.php", core_names)

            with zipfile.ZipFile(output / "module-sale-1.2.3.zip") as archive:
                module_names = set(archive.namelist())
            self.assertIn("module-sale/backend/src/Modules/Sale/module.json", module_names)
            self.assertIn("module-sale/database/migrations/sale/0001.sql", module_names)
            self.assertIn("module-sale/admin-app/index.html", module_names)


if __name__ == "__main__":
    unittest.main()
