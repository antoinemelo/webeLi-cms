from __future__ import annotations
from tools.python.validation.checks import *
from tools.python.validation.model import ValidationReport

VALIDATOR_ID='MULTISITE_LOCALE_MODEL'
DOMAIN='content'
MODES=("fast","full")

def validate(mode: str="fast") -> ValidationReport:
    r=ValidationReport(VALIDATOR_ID,DOMAIN,mode)
    require_tokens(r,"database/schema/core.sql",["site_id","locale"],"SITE-001"); require_tokens(r,"database/schema/core.sql",["language_code"],"I18N-001"); require_paths(r,["database/seeds/default/core_multisite_contract_seed.sql","database/seeds/default/iam_multisite_contract_seed.sql"]); return r
