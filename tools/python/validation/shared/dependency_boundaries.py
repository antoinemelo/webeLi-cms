from __future__ import annotations
from tools.python.validation.checks import *
from tools.python.validation.model import ValidationReport

VALIDATOR_ID='DEPENDENCY_BOUNDARIES'
DOMAIN='shared'
MODES=("fast","full")

def validate(mode: str="fast") -> ValidationReport:
    r=ValidationReport(VALIDATOR_ID,DOMAIN,mode)
    require_paths(r,["backend/src/Application","backend/src/Domain","backend/src/Infrastructure"]); 
    for p in (ROOT/"backend/src/Application").rglob("*.php"):
        text=p.read_text(errors="ignore"); r.checked()
        if "new PDO(" in text or "sqlite3" in text.lower(): r.add("ARCH-002","Accès direct au stockage depuis la couche Application",path=p.relative_to(ROOT).as_posix())
    return r
