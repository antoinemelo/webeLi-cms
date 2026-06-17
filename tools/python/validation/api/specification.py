from __future__ import annotations
from tools.python.validation.checks import *
from tools.python.validation.model import ValidationReport

VALIDATOR_ID='API_SPEC'
DOMAIN='api'
MODES=("fast","full")

def validate(mode: str="fast") -> ValidationReport:
    r=ValidationReport(VALIDATOR_ID,DOMAIN,mode)
    routes=[]
    for rel in ["backend/routes/api.php","backend/routes/admin.php","backend/routes/web.php"]:
        require_paths(r,[rel]); routes.extend((rel,*x) for x in php_route_entries(rel))
    seen=set()
    for rel,method,path,handler in routes:
        r.checked(); key=(method,path)
        if key in seen: r.add("API-002","Route dupliquée",path=rel,method=method,route=path)
        seen.add(key)
        if "@" not in handler: r.add("API-003","Handler de route invalide",path=rel,handler=handler)
    require_paths(r,["docs/reference/contracts/headless-v1/README.md"]); return r
