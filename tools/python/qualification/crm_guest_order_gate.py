#!/usr/bin/env python3
from __future__ import annotations

import json
from pathlib import Path
from typing import Any

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools/cms.py").is_file())
REPORT = ROOT / "docs/evaluation/machine-readable/crm-guest-order-gate-m7.json"
GATE = "business.crm-guest-order.m7.4.v1"
SCENARIOS = {
    "new_guest_without_contact", "unique_verified_crm_email", "ambiguous_unverified_email",
    "iam_email_change", "pos_optional_customer", "post_purchase_token_claim",
    "administrative_merge", "separate_incorrect_merge", "replay_all_events",
    "crm_outage_during_order", "consent_withdrawal", "abandoned_cart_eligibility",
}


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8", errors="ignore") if path.is_file() else ""


def validate(payload: dict[str, Any], root: Path = ROOT) -> list[str]:
    errors: list[str] = []
    if payload.get("format_version") != 1 or payload.get("gate") != GATE or payload.get("status") != "passed":
        errors.append("invalid M7.4 evidence header")

    scope = payload.get("scope", {})
    for key in ("real_public_checkout", "opaque_cart_token", "site_isolation", "language_preserved", "account_creation_optional", "order_tracking", "permission_aware_crm_navigation"):
        if scope.get(key) is not True:
            errors.append(f"scope.{key} not proven")

    scenarios = payload.get("scenarios", [])
    scenario_ids = {row.get("id") for row in scenarios if isinstance(row, dict)}
    if scenario_ids != SCENARIOS:
        errors.append("mandatory M7.4 scenarios incomplete")
    required_fields = ("identities_before", "identities_after", "rules_applied", "matching_decision", "events_consumed", "activities_produced", "consents", "duplicates_avoided")
    for row in scenarios:
        if not isinstance(row, dict) or row.get("status") != "passed":
            errors.append("scenario not passed")
            continue
        for field in required_fields:
            if field not in row:
                errors.append(f"scenario {row.get('id', '?')} missing {field}")

    assertions = payload.get("assertions", {})
    for key in ("order_never_blocked_by_crm", "provenance_preserved", "sale_snapshots_unchanged", "ambiguous_identity_requires_review", "late_order_and_activity_linking"):
        if assertions.get(key) is not True:
            errors.append(f"assertions.{key} not proven")
    if assertions.get("implicit_marketing_consent") is not False or assertions.get("duplicate_activity_count") != 0:
        errors.append("consent or activity duplication invariant failed")

    experience = payload.get("operator_experience", {})
    for key in ("duplicate_identified", "proposal_explained", "controlled_merge", "guest_order_found", "consent_provenance_verified", "postpone_supported"):
        if experience.get(key) is not True:
            errors.append(f"operator_experience.{key} not proven")
    if float(experience.get("automated_task_success_rate", 0)) < 1 or int(experience.get("errors", 1)) != 0 or int(experience.get("points_without_next_action", 1)) != 0:
        errors.append("operator UX metrics failed")
    if experience.get("technical_complexity_primary_path") is not False:
        errors.append("technical complexity exposed in primary operator path")
    if payload.get("fresh_instance") != {"canonical_schema": True, "migration_required": False}:
        errors.append("fresh-instance contract invalid")

    sources = {
        "checkout": (root / "backend/src/Application/PublicApi/PublicSaleApiHandler.php", ["opaque_token", "cartByToken", "tokenHash", "marketing_consent"]),
        "identity": (root / "backend/src/Modules/Sale/Services/SaleCustomerAccountService.php", ["uniqueVerifiedContactByChannel", "reviewIdentities", "post_purchase_proof", "mergePreview", "separateMerge"]),
        "projection": (root / "backend/src/Modules/Business/Services/SaleCrmActivityProjectionService.php", ["CRM availability never blocks Sale", "rebuild", "source_type,source_id,contract_version", "assertEligibleAbandonedCart"]),
        "consent": (root / "backend/src/Modules/Business/Repositories/BusinessConsentRepository.php", ["withdrawn", "historyForContact", "uniqueVerifiedContactByChannel"]),
        "identity tests": (root / "tools/php/tests/unit/sale_customer_accounts_test.php", ["unique verified CRM email", "unverified CRM duplicates remain distinct", "email change", "separation"]),
        "projection tests": (root / "tools/php/tests/unit/sale_crm_activity_projection_test.php", ["CRM outage", "replay", "POS", "ineligible abandoned cart"]),
        "consent tests": (root / "tools/php/tests/unit/business_segmentation_consent_test.php", ["withdrawal retains", "checkout cannot create"]),
        "identity UX": (root / "frontend/admin-vue/tests/e2e/sale-identity-review.spec.ts", ["Compte avec e-mail vérifié concordant", "Fusionner avec ces décisions", "Reporter"]),
        "consent UX": (root / "frontend/admin-vue/tests/e2e/business-crm-smoke.spec.ts", ["preuve", "source", "opt_out"]),
    }
    for label, (path, needles) in sources.items():
        source = read(path).lower()
        for needle in needles:
            if needle.lower() not in source:
                errors.append(f"{label} evidence missing: {needle}")
    return errors


def main() -> int:
    try:
        payload = json.loads(REPORT.read_text(encoding="utf-8"))
        errors = validate(payload if isinstance(payload, dict) else {})
    except (OSError, json.JSONDecodeError) as exc:
        errors = [str(exc)]
    print(f"Gate M7.4 validée: {REPORT}" if not errors else "Gate M7.4 échouée:\n- " + "\n- ".join(errors))
    return 0 if not errors else 1


if __name__ == "__main__":
    raise SystemExit(main())
