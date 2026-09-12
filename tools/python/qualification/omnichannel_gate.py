#!/usr/bin/env python3
"""Validate the machine-readable M5/M6/M7 omnichannel release-gate evidence."""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path
from typing import Any

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
GATE_ID = "cms-crm-sale-pos.omnichannel.v1"
FORMAT_VERSION = 2
REQUIRED_ORDER_FIELDS = {
    "id", "site_id", "channel_id", "order_number", "source", "status", "payment_status",
    "currency", "customer_snapshot_json", "subtotal_minor", "discount_total_minor", "tax_total_minor",
    "shipping_total_minor", "grand_total_minor", "lines",
}
REQUIRED_COMPARISONS = {
    "same_product_id", "same_sellable_id", "shared_order_schema", "distinct_channel_id",
    "distinct_order_source", "snapshots_immutable", "crm_idempotent", "stock_coherent",
    "channel_pricing_applied", "permission_enforced", "contracts_aligned",
    "cross_database_boundaries_respected", "backup_restore_covered", "proof_redacted",
    "ui_cart_checkout", "partial_fulfillment", "payment_reconciled", "storefront_rebuilt",
}
REQUIRED_FAILURE_CONTROLS = {
    "double_checkout", "double_webhook", "provider_call_interruption", "payment_decline",
    "expired_reservation", "last_item_race", "webhook_before_browser_return", "crm_unavailable",
    "refund_replay", "missing_stock_movement", "duplicate_crm_activity",
}
REQUIRED_ROLES = {"customer", "pos_operator", "fulfillment_operator", "finance_operator", "crm_operator"}
REQUIRED_UX = {
    "mobile_checkout", "decline_recovery", "network_refresh_recovery", "pos_keyboard_scanner",
    "mobile_fulfillment", "admin_refund", "reconciliation_resolution", "crm_timeline",
    "cross_navigation", "fr_en", "automated_accessibility", "keyboard_navigation", "no_dead_end",
}
FORBIDDEN_PROOF_KEY = re.compile(r"(?:email|password|token|secret|address|phone|recipient|customer_snapshot)", re.I)
EMAIL_LIKE = re.compile(r"\b[^\s@]+@[^\s@]+\.[^\s@]+\b")
SHA256 = re.compile(r"^[a-f0-9]{64}$")
COMMIT = re.compile(r"^[a-f0-9]{7,40}$")


def _positive_int(value: Any) -> bool:
    return isinstance(value, int) and not isinstance(value, bool) and value > 0


def _non_negative_int(value: Any) -> bool:
    return isinstance(value, int) and not isinstance(value, bool) and value >= 0


def _proof_leaks(value: Any, path: str = "$") -> list[str]:
    leaks: list[str] = []
    if isinstance(value, dict):
        for key, item in value.items():
            child = f"{path}.{key}"
            if FORBIDDEN_PROOF_KEY.search(str(key)) and str(key) not in {"contains_secret", "contains_pii"}:
                leaks.append(f"forbidden proof key: {child}")
            leaks.extend(_proof_leaks(item, child))
    elif isinstance(value, list):
        for index, item in enumerate(value):
            leaks.extend(_proof_leaks(item, f"{path}[{index}]") )
    elif isinstance(value, str) and EMAIL_LIKE.search(value):
        leaks.append(f"email-like value: {path}")
    return leaks


def _source_contract_errors(root: Path) -> list[str]:
    checks = {
        "public checkout route": (root / "backend/src/Modules/Sale/SaleModuleProvider.php", "/api/v1/sale/channels/{code}/checkout"),
        "payment retry route": (root / "backend/src/Modules/Sale/SaleModuleProvider.php", "/cart/{token}/payment-retry"),
        "POS checkout route": (root / "backend/src/Modules/Sale/SaleModuleProvider.php", "/admin/api/sale/pos/checkout"),
        "fulfillment transition route": (root / "backend/src/Modules/Sale/SaleModuleProvider.php", "/admin/api/sale/fulfillments/{id}/transition"),
        "payment reconciliation route": (root / "backend/src/Modules/Sale/SaleModuleProvider.php", "/admin/api/sale/payments/reconcile"),
        "public OpenAPI checkout": (root / "docs/reference/contracts/public-api/openapi.v1.json", '"/api/v1/sale/channels/{code}/checkout"'),
        "SDK checkout response": (root / "packages/amcms-client/src/generated/openapi-types.ts", "OpenApiPublicSaleCheckoutResponse"),
        "owned cross-database connections": (root / "backend/src/Modules/Business/Services/SaleCrmActivityProjectionService.php", "SaleDatabaseConnection"),
        "double checkout and stock replay": (root / "tools/php/tests/unit/sale_inventory_service_test.php", "checkout retry cannot consume stock twice"),
        "webhook replay and ordering": (root / "tools/php/tests/unit/sale_online_payment_workflow_test.php", "duplicate_webhook"),
        "reservation expiry and last-item race": (root / "tools/php/tests/unit/sale_reservation_lifecycle_test.php", "simulated crash"),
        "missing stock movement": (root / "tools/php/tests/unit/sale_stock_reconstruction_scenario_test.php", "movement"),
        "CRM outage and duplicate activity": (root / "tools/php/tests/unit/sale_crm_activity_projection_test.php", "duplicate"),
        "mobile public checkout": (root / "frontend/admin-vue/tests/e2e/public-guest-checkout.spec.ts", "completes a guest order on mobile"),
        "payment refusal recovery": (root / "frontend/admin-vue/tests/e2e/payment-provider-interchangeability.spec.ts", "without dead end"),
        "checkout keyboard operation": (root / "frontend/admin-vue/tests/e2e/payment-provider-interchangeability.spec.ts", "page.keyboard.press('Enter')"),
        "POS scanner and mobile stock": (root / "frontend/admin-vue/tests/e2e/sale-stock-ledger.spec.ts", "scanner search, persistent filters and mobile consultation"),
        "mobile fulfillment layout": (root / "frontend/admin-vue/src/views/modules/SaleOperationsView.vue", "@media(max-width:800px)"),
        "reconciliation exception resolution": (root / "frontend/admin-vue/tests/e2e/sale-stock-reconstruction.spec.ts", "/stock/reconciliation/repair"),
        "mobile CRM journey": (root / "frontend/admin-vue/tests/e2e/business-crm-smoke.spec.ts", "relations workflow usable on a mobile viewport"),
        "FR EN mobile Sale navigation": (root / "frontend/admin-vue/tests/e2e/admin-i18n.spec.ts", "localized on desktop and mobile"),
        "backup covers declared database inventory": (root / "tools/python/validation/qualification/backup_restore_roundtrip.py", "_check_expected_inventory"),
        "restored databases receive an integrity check": (root / "tools/python/validation/qualification/backup_restore_roundtrip.py", "PRAGMA integrity_check"),
    }
    errors: list[str] = []
    for label, (path, needle) in checks.items():
        if not path.is_file() or needle not in path.read_text(encoding="utf-8", errors="ignore"):
            errors.append(f"contract source missing: {label}")
    projection = root / "backend/src/Modules/Business/Services/SaleCrmActivityProjectionService.php"
    source = projection.read_text(encoding="utf-8", errors="ignore") if projection.is_file() else ""
    if "ATTACH DATABASE" in source.upper() or "storage/database" in source:
        errors.append("forbidden cross-database access in CRM Sale projection")
    return errors


def _validate_package(evidence: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    build = evidence.get("build")
    if not isinstance(build, dict) or not COMMIT.fullmatch(str(build.get("commit", ""))) or not str(build.get("technical_version", "")).strip():
        errors.append("build must identify a commit and technical version")
    providers = evidence.get("providers")
    if not isinstance(providers, list) or not {"sandbox_online", "cash"}.issubset(set(providers)):
        errors.append("providers must include sandbox_online and cash")

    numeric_package = {
        "state_transitions": ("before_count", "after_count"),
        "ledger": ("movement_count",),
        "reservations": ("created_count", "consumed_count"),
        "payments": ("intent_count", "capture_count", "refund_count"),
        "events": ("provider_event_count", "business_event_count", "correlation_count"),
    }
    for section, keys in numeric_package.items():
        value = evidence.get(section)
        if not isinstance(value, dict):
            errors.append(f"{section} evidence is required")
            continue
        for key in keys:
            if not _positive_int(value.get(key)):
                errors.append(f"{section}.{key} must be a positive integer")
        if section != "state_transitions" and not SHA256.fullmatch(str(value.get("sha256", ""))):
            errors.append(f"{section}.sha256 must be a SHA-256 digest")

    reconciliation = evidence.get("reconciliation")
    if not isinstance(reconciliation, dict):
        errors.append("reconciliation evidence is required")
    else:
        if reconciliation.get("payment_consistent") is not True:
            errors.append("reconciliation.payment_consistent must be true")
        if reconciliation.get("stock_remaining_differences") != 0:
            errors.append("reconciliation.stock_remaining_differences must be zero")
        if reconciliation.get("shop_rebuilt") is not True:
            errors.append("reconciliation.shop_rebuilt must be true")

    artifacts = evidence.get("artifacts")
    required_artifacts = {"orders", "ledger", "reservations", "payments", "events", "crm", "reconciliation"}
    if not isinstance(artifacts, dict) or set(artifacts) != required_artifacts:
        errors.append("artifacts must contain the complete hashed evidence package")
    elif any(not SHA256.fullmatch(str(value)) for value in artifacts.values()):
        errors.append("every artifact must be represented by a SHA-256 digest")
    return errors


def validate_evidence(evidence: dict[str, Any], root: Path | None = ROOT) -> list[str]:
    errors: list[str] = []
    if evidence.get("format_version") != FORMAT_VERSION:
        errors.append(f"format_version must be {FORMAT_VERSION}")
    if evidence.get("gate") != GATE_ID:
        errors.append(f"gate must be {GATE_ID}")
    if evidence.get("status") != "passed":
        errors.append("gate status must be passed")

    scenarios = evidence.get("scenarios")
    if not isinstance(scenarios, dict):
        return errors + ["scenarios must be an object"]
    web, pos = scenarios.get("storefront"), scenarios.get("pos")
    if not isinstance(web, dict) or not isinstance(pos, dict):
        return errors + ["storefront and pos scenarios are required"]
    for label, scenario, source in (("storefront", web, "ecommerce"), ("pos", pos, "pos")):
        if scenario.get("status") != "passed":
            errors.append(f"{label}.status must be passed")
        for key in ("product_id", "sellable_id", "channel_id", "order_id", "unit_price_minor", "stock_consumption_count", "activity_count"):
            if not _positive_int(scenario.get(key)):
                errors.append(f"{label}.{key} must be a positive integer")
        if scenario.get("order_source") != source:
            errors.append(f"{label}.order_source must be {source}")
        schema = scenario.get("order_schema")
        if not isinstance(schema, list) or not REQUIRED_ORDER_FIELDS.issubset(set(schema)):
            errors.append(f"{label}.order_schema is incomplete")
        if scenario.get("return_created") is not True:
            errors.append(f"{label}.return_created must be true")
    if web.get("refund_completed") is not True or web.get("sandbox_payment_captured") is not True:
        errors.append("storefront capture and refund must be complete")
    if web.get("fulfillment_created") is not True:
        errors.append("storefront.fulfillment_created must be true")
    if pos.get("session_closed") is not True:
        errors.append("pos.session_closed must be true")
    if web.get("product_id") != pos.get("product_id") or web.get("sellable_id") != pos.get("sellable_id"):
        errors.append("product and sellable must be shared between storefront and POS")
    if web.get("channel_id") == pos.get("channel_id") or web.get("order_source") == pos.get("order_source"):
        errors.append("channel and source must differ between storefront and POS")
    if web.get("order_schema") != pos.get("order_schema"):
        errors.append("order schemas differ between storefront and POS")

    comparisons = evidence.get("comparisons")
    if not isinstance(comparisons, dict):
        errors.append("comparisons must be an object")
    else:
        for key in sorted(REQUIRED_COMPARISONS):
            if comparisons.get(key) is not True:
                errors.append(f"comparison {key} must be true")

    controls = evidence.get("failure_controls")
    if not isinstance(controls, dict) or set(controls) != REQUIRED_FAILURE_CONTROLS:
        errors.append("failure_controls must cover every required regression")
    else:
        for name, control in controls.items():
            if not isinstance(control, dict) or control.get("detected") is not True or control.get("recovery_verified") is not True or not str(control.get("evidence_source", "")).strip():
                errors.append(f"failure control {name} is incomplete")

    roles = evidence.get("roles")
    if not isinstance(roles, dict) or set(roles) != REQUIRED_ROLES:
        errors.append("roles must cover customer, POS, fulfillment, finance and CRM")
    else:
        for name, metric in roles.items():
            if not isinstance(metric, dict) or metric.get("task_success") is not True or metric.get("no_dead_end") is not True:
                errors.append(f"role {name} did not complete without a dead end")
                continue
            if not _positive_int(metric.get("significant_steps")) or not _non_negative_int(metric.get("errors")) or not _non_negative_int(metric.get("recovery_steps")) or not _non_negative_int(metric.get("automated_duration_ms")):
                errors.append(f"role {name} metrics are incomplete")

    ux = evidence.get("ux")
    if not isinstance(ux, dict) or set(ux) != REQUIRED_UX or any(value is not True for value in ux.values()):
        errors.append("ux proof must pass every required journey")

    crm = evidence.get("crm")
    if not isinstance(crm, dict):
        errors.append("crm evidence is required")
    else:
        for key in ("first_missing_events", "second_missing_events", "duplicate_events", "second_repaired_events"):
            if crm.get(key) != 0:
                errors.append(f"crm.{key} must be zero")
        if not _positive_int(crm.get("activity_count", 0)) or not SHA256.fullmatch(str(crm.get("sha256", ""))):
            errors.append("crm activity evidence must be counted and hashed")

    errors.extend(_validate_package(evidence))
    security = evidence.get("security")
    if not isinstance(security, dict) or security.get("contains_pii") is not False or security.get("contains_secret") is not False or security.get("contains_card_data") is not False:
        errors.append("security proof must explicitly contain no PII, secret or card data")
    errors.extend(_proof_leaks(evidence))
    if root is not None:
        errors.extend(_source_contract_errors(root))
    return errors


def validate_report_file(path: Path, root: Path | None = ROOT) -> tuple[dict[str, Any] | None, list[str]]:
    if not path.is_file():
        return None, [f"omnichannel report missing: {path}"]
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        return None, [f"invalid omnichannel report: {exc}"]
    if not isinstance(payload, dict):
        return None, ["omnichannel report root must be an object"]
    return payload, validate_evidence(payload, root)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Valide la preuve E2E omnicanale machine-readable.")
    parser.add_argument("--report", type=Path, required=True)
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args(argv)
    payload, errors = validate_report_file(args.report)
    result = {"ok": not errors, "gate": GATE_ID, "report": str(args.report), "errors": errors}
    if args.json:
        print(json.dumps(result, ensure_ascii=False, indent=2))
    elif errors:
        print("Gate E2E omnicanale échouée:\n- " + "\n- ".join(errors), file=sys.stderr)
    else:
        print(f"Gate E2E omnicanale validée: {args.report}")
    return 0 if payload is not None and not errors else 1


if __name__ == "__main__":
    raise SystemExit(main())
