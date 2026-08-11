from __future__ import annotations

import os
import subprocess
import unittest
from pathlib import Path

from tools.python.cms.runtime import resolve_php_binary
from tools.python.tests.support.database_fixture import (
    copy_full_seeded_databases,
    full_seeded_database_template,
)

ROOT=Path(__file__).resolve().parents[4]
PHP_FUNCTIONAL_SUITE_TIMEOUT_SECONDS = 360
class PhpFunctionalSuitesTest(unittest.TestCase):
    def test_php_functional_suites(self):
        try:
            php = resolve_php_binary()
        except (FileNotFoundError, PermissionError) as exc:
            raise unittest.SkipTest(str(exc)) from exc
        holder, database_dir = copy_full_seeded_databases(prefix="webeli-php-functional-")
        self.addCleanup(holder.cleanup)
        env = os.environ.copy()
        env["CMS_DATABASE_DIR"] = str(database_dir)
        # Les tests PHP historiques partagent cette copie et certains modifient
        # son état. Les scénarios HTTP sensibles peuvent repartir explicitement
        # de ce modèle intact, sans dépendre de leur ordre d'exécution.
        env["CMS_TEST_PRISTINE_DATABASE_DIR"] = str(full_seeded_database_template())
        result=subprocess.run([php,'tools/php/tests/run.php'],cwd=ROOT,env=env,text=True,capture_output=True,timeout=PHP_FUNCTIONAL_SUITE_TIMEOUT_SECONDS)
        self.assertEqual(0,result.returncode,result.stdout+'\n'+result.stderr)
if __name__=='__main__': unittest.main()
