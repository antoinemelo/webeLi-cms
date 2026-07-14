#!/usr/bin/env python3
from __future__ import annotations
import json
from pathlib import Path
from typing import Any

ROOT=next(parent for parent in Path(__file__).resolve().parents if (parent/'tools/cms.py').is_file())
REPORT=ROOT/'docs/evaluation/machine-readable/customer-identity-bridges-m7.json'
GATE='sale.customer-identity-bridges.m7.1.v1'

def read(path:Path)->str:return path.read_text(encoding='utf-8',errors='ignore') if path.is_file() else ''
def validate(payload:dict[str,Any],root:Path=ROOT)->list[str]:
    errors=[]
    if payload.get('format_version')!=1 or payload.get('gate')!=GATE or payload.get('status')!='passed':errors.append('invalid M7.1 evidence header')
    if set(payload.get('identity_types',[]))!={'TransactionalCustomer','CrmContact','IamAccount','Organization'}:errors.append('identity boundaries incomplete')
    for section,keys in {'bridges':['crm_activity_real','customer_identity_real','null_fallback_test_only','legacy_name_compatible'],'resolution_strategies':['explicit_link','post_purchase_token','verified_email','verified_normalized_phone_opt_in','administrative_action','name_only_forbidden'],'review':['explainable_score','matching_and_divergent_fields','orders_and_activities','link','do_not_link','merge','postpone','separate','audit_log']}.items():
        for key in keys:
            if payload.get(section,{}).get(key) is not True:errors.append(f'{section}.{key} not proven')
    safety=payload.get('safety',{})
    if safety.get('automatic_risky_merge') is not False or safety.get('cross_database_foreign_keys') is not False:errors.append('unsafe identity coupling')
    for key in ('guest_checkout_without_fake_contact','field_conflict_decision_required','sale_snapshots_immutable'):
        if safety.get(key) is not True:errors.append(f'safety.{key} not proven')
    if set(payload.get('provenance_sources',[]))!={'checkout','account','import','operator','event'}:errors.append('provenance sources incomplete')
    if payload.get('fresh_instance')!={'canonical_schema':True,'migration_required':False}:errors.append('fresh-instance contract invalid')
    sources={
      'schema':(root/'database/modules/sale.sql',['sale_identity_review_cases','sale_identity_resolution_audit','sale_identity_field_provenance','field_decisions_json']),
      'service':(root/'backend/src/Modules/Sale/Services/SaleCustomerAccountService.php',['reviewIdentities','nom seul','mergePreview','merge_field_decision_required','separateMerge','snapshot']),
      'bridges':(root/'backend/src/Core/ServiceFactory.php',['saleCrmActivities()','saleCustomerIdentityBridge']),
      'ui':(root/'frontend/admin-vue/src/views/modules/SaleIdentityReviewView.vue',['Revue des identités','Concordances expliquées','Ne pas lier','Fusionner avec ces décisions','Séparer']),
      'tests':(root/'tools/php/tests/unit/sale_customer_accounts_test.php',['post-purchase proof','across sites','merge preview','separation','snapshot']),
      'browser':(root/'frontend/admin-vue/tests/e2e/sale-identity-review.spec.ts',['explains evidence','Preuves vérifiées','snapshots immuables'])}
    for label,(path,needles) in sources.items():
        text=read(path).lower()
        for needle in needles:
            if needle.lower() not in text:errors.append(f'{label} evidence missing: {needle}')
    return errors
def main()->int:
    try:payload=json.loads(REPORT.read_text(encoding='utf-8'));errors=validate(payload if isinstance(payload,dict) else {})
    except (OSError,json.JSONDecodeError) as exc:errors=[str(exc)]
    print(f'Gate M7.1 validée: {REPORT}' if not errors else 'Gate M7.1 échouée:\n- '+'\n- '.join(errors));return 0 if not errors else 1
if __name__=='__main__':raise SystemExit(main())
