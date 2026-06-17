from __future__ import annotations
from tools.python.validation.checks import *
from tools.python.validation.model import ValidationReport

VALIDATOR_ID='MEDIA_STORAGE_MODEL'
DOMAIN='operations'
MODES=("fast","full")

def validate(mode: str="fast") -> ValidationReport:
    r=ValidationReport(VALIDATOR_ID,DOMAIN,mode)
    require_paths(r,["backend/src","database/schema/core.sql"]); require_tokens(r,"database/schema/core.sql",["media"],"MED-001"); return r
