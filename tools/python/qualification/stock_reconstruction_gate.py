#!/usr/bin/env python3
"""Validate the machine-readable M6.5 stock reconstruction evidence."""
from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
GATE_ID = "sale.stock-reconstruction.m6.5.v1"
COMPARISONS = {"materialized_state", "movements", "reservations", "fulfillments", "returns", "transfers", "business_shop_projection"}
SCENARIO = {"initial_stock", "reservation", "payment", "sale", "partial_fulfillment", "return", "correction", "transfer", "expiration"}


def _read(path: Path) -> str:
    return path.read_text(encoding="utf-8", errors="ignore") if path.is_file() else ""


def source_errors(root: Path) -> list[str]:
    errors: list[str] = []
    schema = _read(root / "database/modules/sale.sql")
    for needle in ("mode TEXT NOT NULL DEFAULT 'dry_run'", "remaining_differences_count", "backup_path TEXT", "partially_repaired"):
        if needle not in schema:
            errors.append(f"reconciliation schema evidence missing: {needle}")
    service = _read(root / "backend/src/Modules/Sale/Services/SaleInventoryReconciliationService.php")
    for needle in ("bool $repair = false", "derived_cache_rebuild", "corrective_movement", "movement_balance_chain", "business_shop_projection", "private function backup"):
        if needle not in service:
            errors.append(f"reconstruction service evidence missing: {needle}")
    cli = _read(root / "tools/python/commands/inventory.py") + _read(root / "backend/bin/console")
    for needle in ("inventory:reconcile", "--repair", "--reason", "--correction", "--only"):
        if needle not in cli:
            errors.append(f"reconstruction CLI evidence missing: {needle}")
    ui = _read(root / "frontend/admin-vue/src/views/modules/SaleOperationsView.vue")
    for needle in ("runDiagnostic", "repairDiagnostic", "downloadDiagnostic", "retryFailures", "Réparer après sauvegarde"):
        if needle not in ui:
            errors.append(f"reconstruction UX evidence missing: {needle}")
    browser = _read(root / "frontend/admin-vue/tests/e2e/sale-stock-reconstruction.spec.ts")
    for needle in ("without database access", "Lancer l’aperçu", "Réparer après sauvegarde", "Comptage opérateur vérifié"):
        if needle not in browser:
            errors.append(f"reconstruction browser evidence missing: {needle}")
    tests = _read(root / "tools/php/tests/unit/sale_stock_reconstruction_scenario_test.php") + _read(root / "tools/php/tests/unit/sale_inventory_reconciliation_test.php")
    for needle in ("initial stock", "reservation", "payment", "partial fulfillment", "return", "corrective movement", "transfer", "expiration", "dry-run"):
        if needle not in tests.lower():
            errors.append(f"M6.5 scenario evidence missing: {needle}")
    return errors


def validate_evidence(payload: dict[str, Any], root: Path | None = ROOT) -> list[str]:
    errors: list[str] = []
    if payload.get("format_version") != 1 or payload.get("gate") != GATE_ID or payload.get("status") != "passed":
        errors.append("M6.5 reconstruction evidence header is invalid")
    if payload.get("source_of_truth") != "sale_stock_movements" or set(payload.get("comparisons", [])) != COMPARISONS:
        errors.append("M6.5 source or comparison coverage is incomplete")
    invariants = payload.get("invariants", {})
    for key in ("current_equals_movement_sum", "available_equals_physical_minus_reserved", "movement_balance_chain_checked", "exceptions_explicit"):
        if invariants.get(key) is not True:
            errors.append(f"M6.5 invariant not proven: {key}")
    diagnostic = payload.get("diagnostic", {})
    if diagnostic.get("dry_run_default") is not True or diagnostic.get("mutates_stock") is not False or diagnostic.get("before_after_preview") is not True:
        errors.append("M6.5 dry-run safety is incomplete")
    repair = payload.get("repair", {})
    for key in ("reason_required", "backup_before_write", "proof_per_correction", "external_count_uses_corrective_movement", "derived_cache_rebuilt_from_ledger"):
        if repair.get(key) is not True:
            errors.append(f"M6.5 repair safety not proven: {key}")
    if repair.get("automatic_by_default") is not False or repair.get("direct_ledger_mutation") is not False:
        errors.append("M6.5 repair must never be silent or mutate the ledger")
    scenario = payload.get("scenario", {})
    if set(scenario) != SCENARIO or any(value != "passed" for value in scenario.values()):
        errors.append("M6.5 complete scenario is not passed")
    projection = payload.get("projection", {})
    if projection.get("business_checked") is not True or projection.get("stale_public_state_rejected") is not True or projection.get("shop_contract") != "sale.inventory.availability.v1":
        errors.append("M6.5 Shop projection proof is incomplete")
    fresh = payload.get("fresh_instance", {})
    if fresh.get("empty_database_supported") is not True or fresh.get("canonical_schema_rebuild") is not True or fresh.get("migration_required") is not False or fresh.get("backup_restore_verified") is not True:
        errors.append("M6.5 fresh-instance proof is incomplete")
    security = payload.get("security", {})
    if security.get("contains_pii") is not False or security.get("contains_secret") is not False:
        errors.append("M6.5 evidence contains sensitive data")
    ux = payload.get("ux", {})
    for key in ("admin_without_database_access", "preview_before_repair", "visible_progress", "mobile_readable", "permissions_separate_diagnostic_repair"):
        if ux.get(key) is not True:
            errors.append(f"M6.5 operator UX not proven: {key}")
    if root is not None:
        errors.extend(source_errors(root))
    return errors


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--report", type=Path, default=ROOT / "docs/evaluation/machine-readable/sale-stock-reconstruction-m6.json")
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args(argv)
    try:
        payload = json.loads(args.report.read_text(encoding="utf-8"))
        errors = validate_evidence(payload if isinstance(payload, dict) else {}, ROOT)
    except (OSError, json.JSONDecodeError) as exc:
        errors = [f"invalid M6.5 evidence: {exc}"]
    result = {"ok": not errors, "gate": GATE_ID, "errors": errors}
    print(json.dumps(result, ensure_ascii=False, indent=2) if args.json else (f"Gate M6.5 validée: {args.report}" if not errors else "Gate M6.5 échouée:\n- " + "\n- ".join(errors)))
    return 0 if not errors else 1


if __name__ == "__main__":
    raise SystemExit(main())
