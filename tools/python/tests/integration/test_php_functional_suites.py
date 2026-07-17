from __future__ import annotations
import subprocess, unittest
from pathlib import Path
from tools.python.cms.runtime import resolve_php_binary
ROOT=Path(__file__).resolve().parents[4]
PHP_FUNCTIONAL_SUITE_TIMEOUT_SECONDS = 360
class PhpFunctionalSuitesTest(unittest.TestCase):
    def test_php_functional_suites(self):
        try:
            php = resolve_php_binary()
        except (FileNotFoundError, PermissionError) as exc:
            raise unittest.SkipTest(str(exc)) from exc
        result=subprocess.run([php,'tools/php/tests/run.php'],cwd=ROOT,text=True,capture_output=True,timeout=PHP_FUNCTIONAL_SUITE_TIMEOUT_SECONDS)
        self.assertEqual(0,result.returncode,result.stdout+'\n'+result.stderr)
if __name__=='__main__': unittest.main()
