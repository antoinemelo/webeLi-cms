from __future__ import annotations
from tools.python.validation.checks import *
from tools.python.validation.model import ValidationReport

VALIDATOR_ID='PROJECTION_DEFINITIONS'
DOMAIN='database'
MODES=("fast","full")

def validate(mode: str="fast") -> ValidationReport:
    r=ValidationReport(VALIDATOR_ID,DOMAIN,mode)
    require_paths(r,["tools/python/operations/database/b1_rebuild_projections.py","tools/python/operations/database/b3_rebuild_search.py","database/views/search_views.sql","database/views/seo_views.sql"]); require_tokens(r,"tools/python/operations/database/b1_rebuild_projections.py",["projection","rebuild"],"PRJ-002"); return r
