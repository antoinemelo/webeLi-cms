from __future__ import annotations
import json, subprocess, sys, tempfile, unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[3]
CLI=ROOT/'tools'/'cms.py'

class CmsCliTest(unittest.TestCase):
    def run_cli(self,*args,cwd=None):
        return subprocess.run([sys.executable,str(CLI),*args],cwd=cwd or ROOT,text=True,capture_output=True)

    def test_help_for_all_commands(self):
        self.assertEqual(self.run_cli('--help').returncode,0)
        for command in ('init','rebuild','validate','qualify','test','export','backup','release'):
            result=self.run_cli(command,'--help')
            self.assertEqual(result.returncode,0,(command,result.stderr))

    def test_json_dry_run_is_valid(self):
        result=self.run_cli('--json','--dry-run','validate')
        self.assertEqual(result.returncode,0,result.stderr)
        payload=json.loads(result.stdout)
        self.assertEqual(payload['status'],'dry-run')
        self.assertEqual(payload['returncode'],0)

    def test_invalid_root_has_uniform_error(self):
        with tempfile.TemporaryDirectory(prefix='cms cli space ') as tmp:
            result=self.run_cli('--root',tmp,'--json','--dry-run','validate')
        self.assertEqual(result.returncode,2)
        self.assertEqual(json.loads(result.stdout)['status'],'error')

    def test_project_path_with_spaces(self):
        with tempfile.TemporaryDirectory(prefix='cms cli space ') as tmp:
            linked=Path(tmp)/'project with spaces'
            linked.symlink_to(ROOT,target_is_directory=True)
            result=self.run_cli('--root',str(linked),'--json','--dry-run','init')
            self.assertEqual(result.returncode,0,result.stderr)
            self.assertEqual(json.loads(result.stdout)['status'],'dry-run')

    def test_custom_database_dir_is_explicitly_rejected_for_legacy_adapter(self):
        result=self.run_cli('--database-dir','other databases','--json','--dry-run','rebuild')
        self.assertEqual(result.returncode,2)
        self.assertIn('exige --database-dir',json.loads(result.stdout)['error'])

    def test_command_evidence_contains_required_fields_and_checksum(self):
        with tempfile.TemporaryDirectory() as tmp:
            result=self.run_cli('--evidence-dir',tmp,'--dry-run','validate')
            self.assertEqual(result.returncode,0,result.stderr)
            evidence=list(Path(tmp).glob('*.command-evidence.json'))
            self.assertEqual(len(evidence),1)
            payload=json.loads(evidence[0].read_text())
            for key in ('command','date_utc','cms_version','environment','duration_seconds','exit_code','summary','artifacts'):
                self.assertIn(key,payload)
            self.assertEqual(payload['exit_code'],0)
            self.assertTrue(evidence[0].with_suffix(evidence[0].suffix+'.sha256').is_file())

    def test_missing_external_dependency_returns_reliable_error(self):
        env=dict(**__import__('os').environ, CMS_PHP_BINARY='/definitely/missing/php')
        result=subprocess.run([sys.executable,str(CLI),'--json','--dry-run','export'],cwd=ROOT,text=True,capture_output=True,env=env)
        self.assertEqual(result.returncode,2)

if __name__=='__main__': unittest.main()
