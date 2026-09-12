#!/usr/bin/env python3
from __future__ import annotations
import json
from pathlib import Path
from typing import Any

ROOT=next(parent for parent in Path(__file__).resolve().parents if (parent/'tools/cms.py').is_file())
REPORT=ROOT/'docs/evaluation/machine-readable/crm-segmentation-consents-m7.json'
GATE='business.crm-segmentation-consents.m7.3.v1'
def read(path:Path)->str:return path.read_text(encoding='utf-8',errors='ignore') if path.is_file() else ''
def validate(payload:dict[str,Any],root:Path=ROOT)->list[str]:
    errors=[]
    if payload.get('format_version')!=1 or payload.get('gate')!=GATE or payload.get('status')!='passed':errors.append('invalid M7.3 evidence header')
    for section,keys in {
      'segmentation':['manual_separate','calculated_separate','configurable','explainable','rule_versioned','calculation_dated','full_recalculation','incremental_recalculation','anonymous_preview'],
      'consent':['marketing_separate','contact_preference_separate','transactional_necessity_separate','account_creation_separate','purchase_separate','proof_visible','source_visible','date_visible','scope_visible','withdrawal_preserves_history'],
      'privacy':['minimal_transaction_data','configurable_retention'],
      'chronology':['origin','channel','order','payment','return','consent','account'],
      'experience':['simple_builder','criterion_operator_value','count_preview','zero_result','advanced_collapsed','permissions','fr_en'],
    }.items():
        for key in keys:
            if payload.get(section,{}).get(key) is not True:errors.append(f'{section}.{key} not proven')
    if payload.get('consent',{}).get('implicit_opt_in') is not False:errors.append('implicit marketing opt-in must be false')
    if payload.get('privacy',{}).get('prechecked_consent') is not False:errors.append('prechecked consent must be false')
    source=payload.get('source',{})
    if source.get('projected_crm_activities_only') is not True or source.get('sale_transaction_table_read') is not False:errors.append('segmentation source boundary invalid')
    required={'customer_type','last_purchase_at','order_count','total_spent_minor','primary_channel','product_id','category_id','has_return','gift_card_status'}
    if not required.issubset(set(payload.get('dimensions',[]))):errors.append('segmentation dimensions incomplete')
    if payload.get('fresh_instance')!={'canonical_schema':True,'migration_required':False}:errors.append('fresh-instance contract invalid')
    sources={
      'service':(root/'backend/src/Modules/Business/Services/BusinessSegmentationService.php',['crm_sale_activities','incremental','rule_version','sha256','segment_manual_not_recalculable']),
      'consent':(root/'backend/src/Modules/Business/Repositories/BusinessConsentRepository.php',['crm_consent_events','crm_contact_preferences','withdrawn','retention']),
      'schema':(root/'database/modules/business.sql',['crm_segments','crm_segment_members','crm_consent_events','crm_contact_preferences']),
      'permissions':(root/'backend/src/Modules/Business/BusinessModuleProvider.php',['business.segment.read','business.segment.manage','business.consent.read','business.consent.manage']),
      'ui':(root/'frontend/admin-vue/src/views/modules/business/BusinessSegmentsPanel.vue',['segment-builder','segment-preview','business.segments.advanced','incremental']),
      'tests':(root/'tools/php/tests/unit/business_segmentation_consent_test.php',['checkout cannot create','empty segment rule','withdrawal retains','no coupling']),
    }
    for label,(path,needles) in sources.items():
        text=read(path).lower()
        for needle in needles:
            if needle.lower() not in text:errors.append(f'{label} evidence missing: {needle}')
    service=read(root/'backend/src/Modules/Business/Services/BusinessSegmentationService.php').lower()
    for forbidden in ('sale_orders','sale_order_lines','sale_payments','sale_returns'):
        if forbidden in service:errors.append(f'segmentation reads forbidden source: {forbidden}')
    return errors
def main()->int:
    try:payload=json.loads(REPORT.read_text(encoding='utf-8'));errors=validate(payload if isinstance(payload,dict) else {})
    except (OSError,json.JSONDecodeError) as exc:errors=[str(exc)]
    print(f'Gate M7.3 validée: {REPORT}' if not errors else 'Gate M7.3 échouée:\n- '+'\n- '.join(errors));return 0 if not errors else 1
if __name__=='__main__':raise SystemExit(main())
