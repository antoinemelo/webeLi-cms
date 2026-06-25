from __future__ import annotations
import shutil, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[4]
PHP_FUNCTIONAL_SUITE_TIMEOUT_SECONDS = 300
class PhpFunctionalSuitesTest(unittest.TestCase):
    @unittest.skipUnless(shutil.which('php'), 'PHP CLI unavailable')
    def test_php_functional_suites(self):
        result=subprocess.run(['php','tools/php/tests/run.php'],cwd=ROOT,text=True,capture_output=True,timeout=PHP_FUNCTIONAL_SUITE_TIMEOUT_SECONDS)
        self.assertEqual(0,result.returncode,result.stdout+'\n'+result.stderr)
if __name__=='__main__': unittest.main()
