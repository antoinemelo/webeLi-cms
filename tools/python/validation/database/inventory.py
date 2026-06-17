from __future__ import annotations
from tools.python.validation.checks import *
from tools.python.validation.model import ValidationReport

VALIDATOR_ID='DB_INVENTORY'
DOMAIN='database'
MODES=("fast","full")

def validate(mode: str="fast") -> ValidationReport:
    r=ValidationReport(VALIDATOR_ID,DOMAIN,mode)
    require_paths(r,["tools/python/lib/database_inventory.py","tools/python/operations/database/a_db_init.py","tools/python/operations/ai/create_ai_database.py"]); require_tokens(r,"tools/python/lib/database_inventory.py",["core","iam","ai"],"DB-006"); return r
