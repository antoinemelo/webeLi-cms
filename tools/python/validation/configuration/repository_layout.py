from __future__ import annotations
from tools.python.validation.checks import *
from tools.python.validation.model import ValidationReport

VALIDATOR_ID='REPOSITORY_LAYOUT'
DOMAIN='configuration'
MODES=("fast","full")

def validate(mode: str="fast") -> ValidationReport:
    r=ValidationReport(VALIDATOR_ID,DOMAIN,mode)
    require_paths(r,["tools/cms.py","backend/config/app.php","backend/routes/web.php","database/schema/core.sql","database/iam.sql","docs","frontend" ]); forbid_globs(r,["database/*.db"]); return r
