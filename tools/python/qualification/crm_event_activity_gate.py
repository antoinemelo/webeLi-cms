#!/usr/bin/env python3
from __future__ import annotations
import json
from pathlib import Path
from typing import Any

ROOT=next(parent for parent in Path(__file__).resolve().parents if (parent/'tools/cms.py').is_file())
REPORT=ROOT/'docs/evaluation/machine-readable/crm-event-activities-m7.json'
GATE='business.crm-event-activities.m7.2.v1'

def read(path:Path)->str:return path.read_text(encoding='utf-8',errors='ignore') if path.is_file() else ''
def validate(payload:dict[str,Any],root:Path=ROOT)->list[str]:
    errors=[]
    if payload.get('format_version')!=1 or payload.get('gate')!=GATE or payload.get('status')!='passed':errors.append('invalid M7.2 evidence header')
    for section,keys in {
        'contract':['versioned','channel_id','provenance','non_sensitive_summary'],
        'delivery':['outbox','idempotent','source_uniqueness','crm_failure_non_blocking','replay','rebuild','reconciliation'],
        'identity':['pending_preserved','late_event_correlation','manual_link_audited','no_fake_contact'],
        'experience':['business_labels','order_grouping','search','type_filter','channel_filter','pagination','pending_notice','permission_aware_links','technical_details_secondary'],
    }.items():
        for key in keys:
            if payload.get(section,{}).get(key) is not True:errors.append(f'{section}.{key} not proven')
    privacy=payload.get('privacy',{})
    for key in ('full_transaction_payload_stored','cart_address_stored','product_payload_stored','marketing_without_consent'):
        if privacy.get(key) is not False:errors.append(f'privacy.{key} must be false')
    if privacy.get('abandoned_cart_lawful_basis_required') is not True or int(privacy.get('abandoned_cart_retention_limited_days',999))>180:errors.append('abandoned cart safeguards invalid')
    required={'sale.cart.abandoned','sale.order.placed','sale.payment.capture.completed','sale.order.cancelled','sale.return.created','sale.refund.completed','sale.gift_card.issued','sale.gift_card.redeemed','customer.account.created'}
    if not required.issubset(set(payload.get('events',[]))):errors.append('minimal event coverage incomplete')
    if payload.get('fresh_instance')!={'canonical_schema':True,'migration_required':False}:errors.append('fresh-instance contract invalid')
    sources={
      'dto':(root/'backend/src/Modules/Business/Contracts/CrmActivityV2.php',['CONTRACT_VERSION','channelId','provenance','sourceType','sourceId']),
      'projection':(root/'backend/src/Modules/Business/Services/SaleCrmActivityProjectionService.php',['sale.cart.abandoned','customer.account.created','resolvePendingForOrder','rebuild','assertEligibleAbandonedCart']),
      'schema':(root/'database/modules/business.sql',['contract_version','source_type','source_id','provenance_json','retention_until']),
      'routes':(root/'backend/src/Modules/Business/BusinessModuleProvider.php',['sale-activities/unlinked','sale-activities/{id}/link','sale-activities/reconcile']),
      'timeline':(root/'frontend/admin-vue/src/views/modules/business/RelationTimeline.vue',['Rechercher','Canal','pendingCount','Détails techniques','timeline-pagination']),
      'tests':(root/'tools/php/tests/unit/sale_crm_activity_projection_test.php',['out-of-order','CRM outage','versioned DTO','non-sensitive metadata']),
    }
    for label,(path,needles) in sources.items():
        text=read(path).lower()
        for needle in needles:
            if needle.lower() not in text:errors.append(f'{label} evidence missing: {needle}')
    return errors
def main()->int:
    try:payload=json.loads(REPORT.read_text(encoding='utf-8'));errors=validate(payload if isinstance(payload,dict) else {})
    except (OSError,json.JSONDecodeError) as exc:errors=[str(exc)]
    print(f'Gate M7.2 validée: {REPORT}' if not errors else 'Gate M7.2 échouée:\n- '+'\n- '.join(errors));return 0 if not errors else 1
if __name__=='__main__':raise SystemExit(main())
