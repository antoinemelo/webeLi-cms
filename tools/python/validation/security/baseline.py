from __future__ import annotations
from tools.python.validation.checks import *
from tools.python.validation.model import ValidationReport

VALIDATOR_ID='SECURITY_BASELINE'
DOMAIN='security'
MODES=("fast","full")

def validate(mode: str="fast") -> ValidationReport:
    r=ValidationReport(VALIDATOR_ID,DOMAIN,mode)
    require_paths(r,["backend/config/security.php","backend/src/Service/Webhook/WebhookHttpClient.php","backend/routes/api.php"]); require_tokens(r,"backend/src/Service/Webhook/WebhookHttpClient.php",["CURLOPT_SSL_VERIFYPEER => true","CURLOPT_SSL_VERIFYHOST => 2","verify_peer_name"],"SEC-002"); return r
