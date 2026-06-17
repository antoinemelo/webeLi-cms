from __future__ import annotations
from tools.python.validation.checks import *
from tools.python.validation.model import ValidationReport

VALIDATOR_ID='OPERATIONS_MANIFESTS'
DOMAIN='operations'
MODES=("fast","full")

def validate(mode: str="fast") -> ValidationReport:
    r=ValidationReport(VALIDATOR_ID,DOMAIN,mode)
    require_paths(r,["tools/python/commands/backup.py","tools/python/commands/export.py","tools/python/operations/backup/d6_backup_sqlite.py","tools/python/operations/backup/d7_restore_sqlite.py","tools/python/operations/deployment/d0_prepare_release.py"]); require_tokens(r,"tools/python/cms/runtime.py",["dry_run"],"OPS-001"); return r
