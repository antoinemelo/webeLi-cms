from __future__ import annotations
from tools.python.validation.checks import *
from tools.python.validation.model import ValidationReport

VALIDATOR_ID='CONFIG_CONSISTENCY'
DOMAIN='configuration'
MODES=("fast","full")

def validate(mode: str="fast") -> ValidationReport:
    r=ValidationReport(VALIDATOR_ID,DOMAIN,mode)
    require_paths(r,["backend/config/app.php","backend/config/cms.php","backend/config/databases.php","backend/config/modules.php","config/release.json"]); load_json(r,"config/release.json","CFG-006"); require_tokens(r,"backend/config/databases.php",["core","iam"],"CFG-005"); return r
