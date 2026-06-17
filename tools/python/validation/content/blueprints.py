from __future__ import annotations
from tools.python.validation.checks import *
from tools.python.validation.model import ValidationReport

VALIDATOR_ID='BLUEPRINT_SCHEMA'
DOMAIN='content'
MODES=("fast","full")

def validate(mode: str="fast") -> ValidationReport:
    r=ValidationReport(VALIDATOR_ID,DOMAIN,mode)
    data=load_json(r,"database/seeds/native_blueprints.json","BPT-001");
    if isinstance(data,list):
        seen=set()
        for item in data:
            r.checked(); key=str(item.get("key") or item.get("slug") or item.get("name") or "") if isinstance(item,dict) else ""
            if not key: r.add("BPT-001","Blueprint sans identifiant",path="database/seeds/native_blueprints.json")
            elif key in seen: r.add("BPT-002","Identifiant de blueprint dupliqué",path="database/seeds/native_blueprints.json",identifier=key)
            seen.add(key)
    elif data is not None and not isinstance(data,dict): r.add("BPT-001","Racine de blueprints invalide",path="database/seeds/native_blueprints.json")
    return r
