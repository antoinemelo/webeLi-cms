#!/usr/bin/env python3
"""Validate the M6 Sale inventory ledger proof and source-of-truth invariants."""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path
from typing import Any

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
GATE_ID = "sale.inventory-ledger.m6.v1"
PUBLIC_STATUSES = {"in_stock", "deliverable", "backorder", "unavailable"}
REQUIRED_FIELDS = {
    "id", "inventory_item_id", "stock_location_id", "quantity", "movement_type", "reference_type",
    "reference_id", "idempotency_key", "created_by_iam_user_id", "created_at", "correlation_id",
    "balance_after_quantity",
}
REQUIRED_MOVEMENTS = {
    "initial", "receipt", "sale", "return", "correction", "adjustment", "inventory_adjustment",
    "transfer_out", "transfer_in", "bundle_consumption",
}


def _read(path: Path) -> str:
    return path.read_text(encoding="utf-8", errors="ignore") if path.is_file() else ""


def _source_errors(root: Path) -> list[str]:
    errors: list[str] = []
    schema = _read(root / "database/modules/sale.sql")
    for needle in (
        "stock_location_id INTEGER NOT NULL", "correlation_id TEXT NOT NULL", "balance_after_quantity INTEGER NOT NULL",
        "trg_sale_stock_movements_immutable_update", "trg_sale_stock_movements_immutable_delete",
        "idx_sale_stock_movements_correlation",
    ):
        if needle not in schema:
            errors.append(f"canonical ledger schema missing: {needle}")

    mutation = re.compile(r"(?:UPDATE|INSERT\s+(?:OR\s+\w+\s+)?INTO|DELETE\s+FROM)\s+sale_inventory_items", re.IGNORECASE)
    allowed = {
        "backend/src/Modules/Sale/Repositories/SaleInventoryRepository.php",
        "backend/src/Modules/Sale/Services/SaleInventoryReconciliationService.php",
    }
    backend = root / "backend/src"
    for path in backend.rglob("*.php"):
        if mutation.search(_read(path)) and path.relative_to(root).as_posix() not in allowed:
            errors.append(f"direct inventory quantity mutation outside Inventory services: {path.relative_to(root).as_posix()}")

    business_stock = _read(root / "backend/src/Modules/Business/Repositories/CatalogStockRepository.php")
    if "business.catalog.stock_transactional_source_sale" not in business_stock:
        errors.append("Business transactional stock writes are not disabled")
    if re.search(r"(?:UPDATE\s+business_product_variants\s+SET\s+stock_|INSERT\s+INTO\s+business_stock_movements)", business_stock, re.IGNORECASE):
        errors.append("Business still contains a transactional stock write")

    seed = _read(root / "tools/python/operations/database/b0_db_seed.py")
    for needle in ("seed_sale_opening_inventory", "movement_type,quantity,balance_after_quantity", "'initial'", "ROUND_HALF_UP"):
        if needle not in seed:
            errors.append(f"opening movement seed evidence missing: {needle}")

    reconciliation = _read(root / "backend/src/Modules/Sale/Services/SaleInventoryReconciliationService.php")
    for needle in ("movement_type NOT IN ('reservation','release')", "on_hand_quantity=?,reserved_quantity=?,available_quantity=?"):
        if needle not in reconciliation:
            errors.append(f"reconstructible state evidence missing: {needle}")

    public_projection = _read(root / "backend/src/Modules/Sale/Services/SaleCatalogSnapshotService.php")
    for needle in ("sale.inventory.availability.v1", "last_available", "unset($payload['stock_quantity']"):
        if needle not in public_projection:
            errors.append(f"public availability contract evidence missing: {needle}")

    ui = _read(root / "frontend/admin-vue/src/views/modules/SaleStockView.vue")
    for needle in ("amcms.sale.stock.filters.v1", "searchPlaceholder", "wizardStep", "beforeQuantity", "afterQuantity", "@media(max-width:900px)"):
        if needle not in ui:
            errors.append(f"stock UX evidence missing: {needle}")
    if re.search(r'v-model[^>]*(?:on_hand_quantity|reserved_quantity|available_quantity)', ui):
        errors.append("stock UI exposes a direct total edit")
    return errors


def validate_evidence(payload: dict[str, Any], root: Path | None = ROOT) -> list[str]:
    errors: list[str] = []
    if payload.get("format_version") != 1:
        errors.append("format_version must be 1")
    if payload.get("gate") != GATE_ID or payload.get("status") != "passed":
        errors.append("M6 ledger gate must be passed")
    if payload.get("source_of_truth") != "sale.sqlite":
        errors.append("Sale must be the inventory source of truth")
    public = payload.get("public_contract")
    if not isinstance(public, dict) or public.get("version") != "sale.inventory.availability.v1":
        errors.append("public availability contract must be versioned")
    elif set(public.get("statuses", [])) != PUBLIC_STATUSES or public.get("exposes_internal_quantity") is not False or public.get("last_available_indicator") is not True:
        errors.append("public availability contract leaks or has invalid statuses")
    ledger = payload.get("ledger")
    if not isinstance(ledger, dict) or ledger.get("immutable") is not True:
        errors.append("ledger must be immutable")
    else:
        if not REQUIRED_FIELDS.issubset(set(ledger.get("fields", []))):
            errors.append("ledger fields are incomplete")
        if not REQUIRED_MOVEMENTS.issubset(set(ledger.get("movement_types", []))):
            errors.append("ledger movement types are incomplete")
        if ledger.get("opening_movements_seeded") is not True:
            errors.append("opening movements must be seeded")
    derived = payload.get("derived_state")
    if not isinstance(derived, dict) or any(derived.get(key) is not True for key in ("reconstructible", "reconciliation_repairs_derived_state")):
        errors.append("derived state must be reconstructible and repairable")
    business = payload.get("business")
    if not isinstance(business, dict) or business.get("transactional_writes_enabled") is not False or business.get("projection_table") != "business_inventory_availability_projections":
        errors.append("Business must expose projections only")
    ux = payload.get("ux")
    ux_booleans = ("persistent_filters", "mobile_read", "empty_state", "permission_error", "controlled_export")
    if not isinstance(ux, dict) or ux.get("movement_wizard_steps") != 5 or ux.get("direct_total_edit") is not False or any(ux.get(key) is not True for key in ux_booleans):
        errors.append("stock UX proof is incomplete")
    security = payload.get("security")
    if not isinstance(security, dict) or security.get("contains_pii") is not False or security.get("contains_secret") is not False:
        errors.append("proof must contain no PII or secret")
    if root is not None:
        errors.extend(_source_errors(root))
    return errors


def validate_report_file(path: Path, root: Path | None = ROOT) -> tuple[dict[str, Any] | None, list[str]]:
    if not path.is_file():
        return None, [f"inventory ledger report missing: {path}"]
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        return None, [f"invalid inventory ledger report: {exc}"]
    if not isinstance(payload, dict):
        return None, ["inventory ledger report root must be an object"]
    return payload, validate_evidence(payload, root)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Valide le ledger de stock M6 et sa source de vérité Sale.")
    parser.add_argument("--report", type=Path, default=ROOT / "docs/evaluation/machine-readable/sale-inventory-ledger.json")
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args(argv)
    _payload, errors = validate_report_file(args.report)
    result = {"ok": not errors, "gate": GATE_ID, "report": str(args.report), "errors": errors}
    if args.json:
        print(json.dumps(result, ensure_ascii=False, indent=2))
    elif errors:
        print("Gate ledger M6 échouée:\n- " + "\n- ".join(errors), file=sys.stderr)
    else:
        print(f"Gate ledger M6 validée: {args.report}")
    return 0 if not errors else 1


if __name__ == "__main__":
    raise SystemExit(main())
