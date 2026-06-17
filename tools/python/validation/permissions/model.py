from __future__ import annotations
from tools.python.validation.checks import *
from tools.python.validation.model import ValidationReport

VALIDATOR_ID='PERMISSION_MODEL'
DOMAIN='permissions'
MODES=("fast","full")

def validate(mode: str="fast") -> ValidationReport:
    r=ValidationReport(VALIDATOR_ID,DOMAIN,mode)
    require_paths(r,["database/iam.sql","database/seeds/iam_seed.sql"]); require_tokens(r,"database/iam.sql",["permissions","roles","role_permissions"],"AUTH-001");
    used=set()
    import re
    for p in (ROOT/"backend").rglob("*.php"):
        used.update(re.findall(r'(?:can|requirePermission)\([\'"]([a-z0-9_.:-]+)', p.read_text(errors="ignore")))
    seed=(ROOT/"database/seeds/iam_seed.sql").read_text(errors="ignore")
    for permission in sorted(used):
        r.checked()
        if permission not in seed and permission not in (ROOT/"database/iam.sql").read_text(errors="ignore"): r.add("AUTH-001","Permission utilisée mais non déclarée",path="database/seeds/iam_seed.sql",permission=permission)
    return r
