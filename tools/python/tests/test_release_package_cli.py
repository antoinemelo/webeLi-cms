from __future__ import annotations

import subprocess
import sys
import tempfile
import unittest
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]


class ReleasePackageCliTest(unittest.TestCase):
    def test_cli_entrypoints_survive_extraction_and_external_cwd(self) -> None:
        required = [
            "tools/cms.py",
            "tools/__init__.py",
            "tools/python/__init__.py",
        ]
        for rel in required:
            self.assertTrue((ROOT / rel).is_file(), rel)

        with tempfile.TemporaryDirectory(prefix="am cms package ") as directory:
            temporary = Path(directory)
            archive = temporary / "package.zip"
            extracted = temporary / "extracted with spaces"
            outside = temporary / "outside"
            outside.mkdir()

            with zipfile.ZipFile(archive, "w", zipfile.ZIP_DEFLATED) as package:
                for rel in required:
                    package.write(ROOT / rel, rel)
                # The release contract is the complete Python runtime used by
                # tools/cms.py. Keeping a hand-maintained module allow-list here
                # creates a second source of truth and misses new imports.
                already_added = set(required)
                for path in sorted((ROOT / "tools" / "python").rglob("*.py")):
                    if "__pycache__" in path.parts:
                        continue
                    relative = path.relative_to(ROOT).as_posix()
                    if relative in already_added:
                        continue
                    package.write(path, relative)
                release_config = ROOT / "config" / "release.json"
                if release_config.is_file():
                    package.write(release_config, release_config.relative_to(ROOT))

            with zipfile.ZipFile(archive) as package:
                package.extractall(extracted)

            for command in (
                [sys.executable, str(extracted / "tools/cms.py"), "--help"],
                [sys.executable, str(extracted / "tools/cms.py")],
            ):
                process = subprocess.run(
                    command,
                    cwd=outside,
                    text=True,
                    capture_output=True,
                    timeout=30,
                )
                self.assertEqual(process.returncode, 0, process.stderr)

            invalid = subprocess.run(
                [sys.executable, str(extracted / "tools/cms.py"), "invalid"],
                cwd=outside,
                text=True,
                capture_output=True,
                timeout=30,
            )
            self.assertNotEqual(invalid.returncode, 0)


if __name__ == "__main__":
    unittest.main()
