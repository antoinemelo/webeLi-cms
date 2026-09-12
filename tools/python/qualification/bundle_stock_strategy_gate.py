#!/usr/bin/env python3
"""Validate M6.3 bundle stock strategy invariants and evidence."""
from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
GATE_ID = "business.bundle-stock-strategies.m6.3.v1"
STRATEGIES = {"OWN_STOCK", "COMPONENT_DERIVED", "NON_STOCKED"}


def _read(path: Path) -> str:
    return path.read_text(encoding="utf-8", errors="ignore") if path.is_file() else ""


def source_errors(root: Path) -> list[str]:
    errors: list[str] = []
    business_schema = _read(root / "database/modules/business.sql")
    for needle in ("stock_strategy TEXT", "OWN_STOCK", "COMPONENT_DERIVED", "NON_STOCKED", "partial_fulfillment_supported", "component_return_policy"):
        if needle not in business_schema:
            errors.append(f"bundle schema evidence missing: {needle}")
    sale_schema = _read(root / "database/modules/sale.sql")
    for needle in ("demand_kind", "bundle_parent_sellable_id", "component_returns_json", "bundle_consumption"):
        if needle not in sale_schema:
            errors.append(f"Sale bundle lifecycle evidence missing: {needle}")
    service = _read(root / "backend/src/Modules/Business/Services/BusinessProductBundleService.php")
    for needle in ("buildInventoryPlan", "count($visited) >= 8", "mergeLeaves", "limiting_factor", "business.bundle_loop_detected", "business.bundle_partial_fulfillment_not_supported"):
        if needle not in service:
            errors.append(f"derived plan evidence missing: {needle}")
    inventory = _read(root / "backend/src/Modules/Sale/Services/SaleInventoryService.php") + _read(root / "backend/src/Modules/Sale/Repositories/SaleInventoryRepository.php")
    for needle in ("bundle_inventory_plan", "bundle_component", "bundle_parent_sellable_id", "bundle_consumption"):
        if needle not in inventory:
            errors.append(f"component reservation evidence missing: {needle}")
    returns = _read(root / "backend/src/Modules/Sale/Services/SaleReturnService.php")
    for needle in ("COMPONENTS_ALLOWED", "component_returns_json", "bundle_inventory_plan", "NON_STOCKED"):
        if needle not in returns:
            errors.append(f"bundle return evidence missing: {needle}")
    public = _read(root / "backend/src/Application/PublicApi/PublicCatalogApiHandler.php")
    for needle in ("business.bundle.stock-strategy.v1", "bundle_components_public", "limiting_component"):
        if needle not in public:
            errors.append(f"Shop bundle evidence missing: {needle}")
    ui = _read(root / "frontend/admin-vue/src/views/modules/BusinessCatalogView.vue")
    for needle in ("componentSearch", "moveBundleComponent", "bundleStockEstimate", "bundle-strategy", "configuration_errors", "@media"):
        if needle not in ui:
            errors.append(f"bundle assistant UX evidence missing: {needle}")
    tests = _read(root / "tools/php/tests/unit/business_bundle_stock_strategies_test.php") + _read(root / "tools/php/tests/unit/sale_inventory_service_test.php") + _read(root / "tools/php/tests/unit/sale_internal_sales_test.php")
    for needle in ("nested component ratios", "concurrent bundle", "sales channel", "full derived-bundle return", "component-only return"):
        if needle not in tests:
            errors.append(f"bundle scenario test evidence missing: {needle}")
    return errors


def validate_evidence(payload: dict[str, Any], root: Path | None = ROOT) -> list[str]:
    errors: list[str] = []
    if payload.get("format_version") != 1 or payload.get("gate") != GATE_ID or payload.get("status") != "passed":
        errors.append("M6.3 bundle evidence header is invalid")
    if set(payload.get("strategies", [])) != STRATEGIES:
        errors.append("bundle stock strategies are incomplete")
    partial = payload.get("partial_availability", {})
    if partial.get("default") != "REQUIRE_ALL" or partial.get("allow_partial_enabled") is not False:
        errors.append("v1 partial availability policy is unsafe")
    derived = payload.get("derived_plan", {})
    for key in ("nested", "duplicate_leaves_merged", "limiting_factor_exposed", "cycles_rejected", "non_positive_ratios_rejected"):
        if derived.get(key) is not True:
            errors.append(f"derived bundle invariant not proven: {key}")
    if derived.get("max_depth") != 8:
        errors.append("bundle maximum depth must be explicit")
    inventory = payload.get("inventory", {})
    for key in ("atomic_component_reservation", "channel_location", "parent_bundle_traceable", "consumption_idempotent"):
        if inventory.get(key) is not True:
            errors.append(f"bundle inventory invariant not proven: {key}")
    if inventory.get("non_stocked_has_physical_movement") is not False:
        errors.append("NON_STOCKED must not create physical movements")
    storefront = payload.get("storefront", {})
    if storefront.get("route") != "/shop" or storefront.get("exact_internal_stock_exposed") is not False:
        errors.append("Shop bundle exposure contract is unsafe")
    security = payload.get("security", {})
    if security.get("contains_pii") is not False or security.get("contains_secret") is not False:
        errors.append("M6.3 evidence contains sensitive data")
    if root is not None:
        errors.extend(source_errors(root))
    return errors


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--report", type=Path, default=ROOT / "docs/evaluation/machine-readable/bundle-stock-strategies.json")
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args(argv)
    try:
        payload = json.loads(args.report.read_text(encoding="utf-8"))
        errors = validate_evidence(payload if isinstance(payload, dict) else {}, ROOT)
    except (OSError, json.JSONDecodeError) as exc:
        errors = [f"invalid M6.3 evidence: {exc}"]
    result = {"ok": not errors, "gate": GATE_ID, "errors": errors}
    print(json.dumps(result, ensure_ascii=False, indent=2) if args.json else (f"Gate M6.3 validée: {args.report}" if not errors else "Gate M6.3 échouée:\n- " + "\n- ".join(errors)))
    return 0 if not errors else 1


if __name__ == "__main__":
    raise SystemExit(main())
