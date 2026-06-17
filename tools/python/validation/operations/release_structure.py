from __future__ import annotations
from tools.python.validation.checks import *
from tools.python.validation.model import ValidationReport

VALIDATOR_ID='RELEASE_STRUCTURE'
DOMAIN='operations'
MODES=("fast","full")

def validate(mode: str="fast") -> ValidationReport:
    r=ValidationReport(VALIDATOR_ID,DOMAIN,mode)
    data=load_json(r,"config/release.json","REL-003"); require_paths(r,["tools/python/operations/deployment/d2_package_release.py","tools/python/operations/deployment/d4_verify_release_archive.py",".github/workflows/release.yml"]); return r
