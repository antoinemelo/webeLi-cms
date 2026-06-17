from __future__ import annotations
from tools.python.validation.checks import *
from tools.python.validation.model import ValidationReport

VALIDATOR_ID='CONTENT_CONTRACTS'
DOMAIN='content'
MODES=("fast","full")

def validate(mode: str="fast") -> ValidationReport:
    r=ValidationReport(VALIDATOR_ID,DOMAIN,mode)
    require_paths(r,["backend/config/workflow.php","backend/src/Application/Content","backend/src/Domain/Content"]); require_tokens(r,"backend/config/workflow.php",["draft","published"],"WF-001"); require_tokens(r,"database/schema/core.sql",["content_entry_working_revisions","content_entry_publications"],"CNT-001"); return r
