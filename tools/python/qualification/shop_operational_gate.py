#!/usr/bin/env python3
"""Validate the single release gate for the operational Shop (point 48)."""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path
from typing import Any

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
GATE_ID = "shop-operational.release.48.v1"
STATIC_REPORT = ROOT / "docs/evaluation/machine-readable/shop-operational-release-48.json"
RUNTIME_REPORT = ROOT / "storage/qualification/shop-operational/latest.json"

SCENARIOS = {
    "A_activation_studio": {"inactive_draft", "explicit_activation", "system_page_route_menu_cart", "studio_preview_publish", "no_cms_product_pages", "deactivate_reactivate_idempotent"},
    "B_product_discovery": {"desktop_mobile", "search_taxonomies_availability", "group_attributes", "sorts", "merchandising", "filter_return", "ssr_api_parity", "site_language_isolation"},
    "C_product_cart": {"keyboard_touch_preview", "variant_media_price_attributes", "availability_delivery_payment", "related_products", "shop_product_studio_add", "drawer_quantities", "price_stock_recovery"},
    "D_purchases": {"guest_optional_account", "success_decline_recovery", "idempotent_checkout_webhook", "gift_card_orders", "gift_card_partial_payment", "deferred_on_order", "expired_authorization_recovery", "pos_immediate_deferred"},
    "E_operations_admin": {"confirmation_fulfillment_tracking", "invoice_send_credit_note", "return_refund", "sales_dashboard_export", "inventory_transfer_rebuild", "crm_no_implicit_optin", "relation_360", "product_stock_correction", "advanced_permission_boundary"},
}
NEGATIVE_CONTROLS = {
    "inactive_shop_scope", "cross_site_product", "cross_site_order", "cross_site_metric", "cross_site_document",
    "permission_denied", "private_incomplete_product", "unavailable_variant", "last_item_race",
    "invalid_expired_cart_tracking", "invalid_duplicate_out_of_order_webhook", "gift_card_abuse",
    "dependency_outage", "stale_projection", "immutable_invoice_snapshot", "deferred_invoice_forbidden",
    "advanced_api_forbidden", "no_autonomous_commerce",
}
UX_CONTROLS = {
    "mobile_tablet_desktop", "keyboard_focus", "screen_reader_names", "contrast_not_color_only", "fr_en",
    "loading_empty_error_partial_denied_recovery", "input_preservation", "no_dead_end", "performance_budgets",
}
PREPARATION = {
    "from_scratch_without_migrations", "two_sites_distinct_base_paths", "two_languages",
    "role_matrix", "catalog_fixtures", "selective_shop_activation",
}
SHA256 = re.compile(r"^[a-f0-9]{64}$")
EMAIL = re.compile(r"\b[^\s@]+@[^\s@]+\.[^\s@]+\b")
SENSITIVE_KEY = re.compile(r"(?:^|_)(?:email|phone|address|password|secret|token|gift_?code|card_?number|customer_?name)(?:$|_)", re.I)

SOURCE_EVIDENCE = {
    "activation": ("frontend/admin-vue/tests/e2e/shop-system-activation-39.spec.ts", "keeps Studio publication separate"),
    "catalog": ("frontend/admin-vue/tests/e2e/storefront-catalog-search-facets-40.spec.ts", "catalogue"),
    "merchandising": ("frontend/admin-vue/tests/e2e/storefront-merchandising-popularity-41.spec.ts", "merchandising"),
    "product": ("frontend/admin-vue/tests/e2e/storefront-product-cards-details-relations-42.spec.ts", "product"),
    "studio": ("frontend/admin-vue/tests/e2e/studio-commerce-blocks-43.spec.ts", "Studio"),
    "cart": ("frontend/admin-vue/tests/e2e/public-cart-checkout-resilience-44.spec.ts", "double checkout"),
    "gift_card": ("frontend/admin-vue/tests/e2e/gift-card-lifecycle-45.spec.ts", "gift"),
    "logistics": ("frontend/admin-vue/tests/e2e/order-logistics-tracking-46.spec.ts", "tracking"),
    "invoicing": ("frontend/admin-vue/tests/e2e/sale-invoicing-sales-inventory-47.spec.ts", "snapshot indicators"),
    "omnichannel": ("frontend/admin-vue/tests/e2e/omnichannel-release-gate.spec.ts", "cms-crm-sale-pos.omnichannel.v1"),
    "admin_convergence": ("frontend/admin-vue/tests/e2e/admin-convergence-gate-38e.spec.ts", "admin-convergence.ux.38e.v1"),
    "usability": ("frontend/admin-vue/tests/e2e/commerce-usability-gate.spec.ts", "accessibility"),
    "runtime_gate": ("frontend/admin-vue/tests/e2e/shop-operational-release-gate-48.spec.ts", GATE_ID),
    "fresh_harness": ("tools/python/operations/testing/run_playwright_e2e.py", "rebuild_databases(instance)"),
}


def _read(path: Path, label: str) -> tuple[dict[str, Any] | None, list[str]]:
    if not path.is_file():
        return None, [f"{label} missing: {path}"]
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        return None, [f"invalid {label}: {exc}"]
    return (value, []) if isinstance(value, dict) else (None, [f"{label} root must be an object"])


def _leaks(value: Any, path: str = "$") -> list[str]:
    errors: list[str] = []
    if isinstance(value, dict):
        for key, item in value.items():
            child = f"{path}.{key}"
            if SENSITIVE_KEY.search(str(key)) and str(key) not in {"contains_pii", "contains_secret", "contains_token", "contains_gift_code"}:
                errors.append(f"sensitive key in Shop evidence: {child}")
            errors.extend(_leaks(item, child))
    elif isinstance(value, list):
        for index, item in enumerate(value):
            errors.extend(_leaks(item, f"{path}[{index}]"))
    elif isinstance(value, str) and EMAIL.search(value):
        errors.append(f"email-like value in Shop evidence: {path}")
    return errors


def _source_errors(root: Path) -> list[str]:
    errors: list[str] = []
    for label, (relative, needle) in SOURCE_EVIDENCE.items():
        path = root / relative
        if not path.is_file() or needle.lower() not in path.read_text(encoding="utf-8", errors="ignore").lower():
            errors.append(f"source evidence missing: {label}")
    return errors


def validate_static_evidence(payload: dict[str, Any], root: Path | None = ROOT) -> list[str]:
    errors: list[str] = []
    if payload.get("format_version") != 1 or payload.get("gate") != GATE_ID or payload.get("status") != "defined":
        errors.append("static Shop gate identity/status is invalid")
    if payload.get("extends") != ["38a", "38b", "38c", "38d", "38e", "39", "40", "41", "42", "43", "44", "45", "46", "47"]:
        errors.append("Shop gate must extend the canonical 38a-47 journeys")
    scenarios = payload.get("scenarios")
    if not isinstance(scenarios, dict) or set(scenarios) != set(SCENARIOS):
        errors.append("all Shop scenario groups A-E are required")
    else:
        for name, expected in SCENARIOS.items():
            tasks = scenarios[name].get("tasks") if isinstance(scenarios[name], dict) else None
            if not isinstance(tasks, dict) or set(tasks) != expected or any(value != "covered" for value in tasks.values()):
                errors.append(f"static scenario {name} coverage is incomplete")
            if not isinstance(scenarios[name].get("evidence"), list) or not scenarios[name]["evidence"]:
                errors.append(f"static scenario {name} needs evidence")
    for key, expected in (("negative_controls", NEGATIVE_CONTROLS), ("ux_controls", UX_CONTROLS), ("preparation", PREPARATION)):
        value = payload.get(key)
        if not isinstance(value, dict) or set(value) != expected or any(item != "covered" for item in value.values()):
            errors.append(f"static {key} coverage is incomplete")
    if payload.get("single_release_command") != "python3 tools/cms.py qualify --profile release":
        errors.append("the gate must remain in the canonical release qualification")
    limits = payload.get("limitations")
    if not isinstance(limits, list) or not limits:
        errors.append("static Shop limitations must be explicit")
    errors.extend(_leaks(payload))
    if root is not None:
        errors.extend(_source_errors(root))
    return errors


def validate_runtime_evidence(payload: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    if payload.get("format_version") != 1 or payload.get("gate") != GATE_ID or payload.get("status") != "passed":
        errors.append("runtime Shop gate identity/status is invalid")
    build = payload.get("build")
    if not isinstance(build, dict) or not re.fullmatch(r"[a-f0-9]{7,40}", str(build.get("commit", ""))):
        errors.append("runtime Shop gate needs a source commit")
    preparation = payload.get("preparation")
    if not isinstance(preparation, dict) or set(preparation) != PREPARATION or any(value is not True for value in preparation.values()):
        errors.append("runtime from-scratch preparation is incomplete")
    scopes = payload.get("scopes")
    if not isinstance(scopes, dict) or int(scopes.get("site_count", 0)) < 2 or int(scopes.get("language_count", 0)) < 2:
        errors.append("runtime evidence needs two sites and two languages")
    elif scopes.get("distinct_base_paths") is not True or scopes.get("selective_activation") is not True:
        errors.append("runtime base-path or activation isolation is missing")
    scenarios = payload.get("scenarios")
    if not isinstance(scenarios, dict) or set(scenarios) != set(SCENARIOS):
        errors.append("runtime report must include scenarios A-E")
    else:
        for name, result in scenarios.items():
            if not isinstance(result, dict) or result.get("status") != "passed" or not SHA256.fullmatch(str(result.get("sha256", ""))):
                errors.append(f"runtime scenario {name} is missing passed/hash evidence")
    negatives = payload.get("negative_controls")
    if not isinstance(negatives, dict) or set(negatives) != NEGATIVE_CONTROLS:
        errors.append("runtime negative controls are incomplete")
    else:
        for name, result in negatives.items():
            if not isinstance(result, dict) or result.get("blocked") is not True or not str(result.get("evidence_source", "")).strip():
                errors.append(f"negative control {name} lacks blocking evidence")
    ux = payload.get("ux")
    if not isinstance(ux, dict) or set(ux) != UX_CONTROLS or any(value is not True for value in ux.values()):
        errors.append("runtime UX/accessibility controls are incomplete")
    artifacts = payload.get("artifacts")
    if not isinstance(artifacts, dict) or not artifacts or any(not SHA256.fullmatch(str(value)) for value in artifacts.values()):
        errors.append("runtime artifact hashes are missing or invalid")
    security = payload.get("security")
    if not isinstance(security, dict) or any(security.get(key) is not False for key in ("contains_pii", "contains_secret", "contains_token", "contains_gift_code", "contains_provider_payload")):
        errors.append("runtime Shop proof must explicitly contain no sensitive data")
    limits = payload.get("limitations")
    if not isinstance(limits, list) or not limits:
        errors.append("runtime Shop limitations must be explicit")
    errors.extend(_leaks(payload))
    return errors


def validate_report_files(static_path: Path = STATIC_REPORT, runtime_path: Path = RUNTIME_REPORT, root: Path | None = ROOT) -> list[str]:
    static, errors = _read(static_path, "static Shop report")
    runtime, runtime_errors = _read(runtime_path, "runtime Shop report")
    errors.extend(runtime_errors)
    if static is not None:
        errors.extend(validate_static_evidence(static, root))
    if runtime is not None:
        errors.extend(validate_runtime_evidence(runtime))
    return errors


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Valide la gate release du Shop opérationnel.")
    parser.add_argument("--static-report", type=Path, default=STATIC_REPORT)
    parser.add_argument("--runtime-report", type=Path, default=RUNTIME_REPORT)
    parser.add_argument("--static-only", action="store_true")
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args(argv)
    static, errors = _read(args.static_report, "static Shop report")
    if static is not None:
        errors.extend(validate_static_evidence(static))
    if not args.static_only:
        runtime, runtime_errors = _read(args.runtime_report, "runtime Shop report")
        errors.extend(runtime_errors)
        if runtime is not None:
            errors.extend(validate_runtime_evidence(runtime))
    result = {"ok": not errors, "gate": GATE_ID, "static_report": str(args.static_report), "runtime_report": None if args.static_only else str(args.runtime_report), "errors": errors}
    if args.json:
        print(json.dumps(result, ensure_ascii=False, indent=2))
    elif errors:
        print("Gate Shop opérationnel échouée:\n- " + "\n- ".join(errors), file=sys.stderr)
    else:
        print("Gate Shop opérationnel validée.")
    return 0 if not errors else 1


if __name__ == "__main__":
    raise SystemExit(main())
