#!/usr/bin/env python3
"""Validate M6.2 reservation, expiry and explicit backorder invariants."""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path
from typing import Any

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
GATE_ID = "sale.reservations-availability.m6.2.v1"
TRIGGERS = {"checkout_start", "order_placement", "payment_authorization", "payment_capture"}
STATES = {"active", "confirmed", "consumed", "released", "expired", "cancelled"}


def _read(path: Path) -> str:
    return path.read_text(encoding="utf-8", errors="ignore") if path.is_file() else ""


def source_errors(root: Path) -> list[str]:
    errors: list[str] = []
    schema = _read(root / "database/modules/sale.sql")
    for needle in ("reservation_policy TEXT", "reservation_max_lifetime_seconds", "sale_stock_backorders", "max_expires_at"):
        if needle not in schema:
            errors.append(f"reservation schema evidence missing: {needle}")
    repository = _read(root / "backend/src/Modules/Sale/Repositories/SaleInventoryRepository.php")
    for needle in ("available_quantity>=?", "renewReservation", "expireDueReservations", "sale.stock_reservation_not_renewable", "backorder_policy", "status IN ('active','confirmed')"):
        if needle not in repository:
            errors.append(f"atomic lifecycle evidence missing: {needle}")
    checkout = _read(root / "backend/src/Modules/Sale/Services/SaleCheckoutService.php")
    payments = _read(root / "backend/src/Modules/Sale/Services/SaleOnlinePaymentService.php")
    if "order_placement" not in checkout or "payment_authorization" not in payments or "payment_capture" not in payments:
        errors.append("checkout/payment trigger integration is incomplete")
    console = _read(root / "backend/bin/console")
    if "worker:reservations" not in console:
        errors.append("reservation expiry worker is missing")
    public = _read(root / "backend/src/Application/PublicApi/PublicSaleApiHandler.php") + _read(root / "frontend/theme-default/assets/js/guest-checkout.js")
    for needle in ("cart_preserved", "reduce_quantity", "choose_variant", "remove_line"):
        if needle not in public:
            errors.append(f"public recovery evidence missing: {needle}")
    ui = _read(root / "frontend/admin-vue/src/views/modules/SaleReservationsView.vue")
    for needle in ("humanTtl", "expiring_soon", "sale.stock.manage", "reservation-policies", "@media"):
        if needle not in ui:
            errors.append(f"reservation UX evidence missing: {needle}")
    tests = _read(root / "tools/php/tests/unit/sale_reservation_lifecycle_test.php")
    for needle in ("second client", "simulated crash", "worker retry", "POS backorder"):
        if needle not in tests:
            errors.append(f"concurrency/retry test evidence missing: {needle}")
    return errors


def validate_evidence(payload: dict[str, Any], root: Path | None = ROOT) -> list[str]:
    errors: list[str] = []
    if payload.get("format_version") != 1 or payload.get("gate") != GATE_ID or payload.get("status") != "passed":
        errors.append("M6.2 reservation evidence header is invalid")
    if set(payload.get("triggers", [])) != TRIGGERS:
        errors.append("reservation triggers are incomplete")
    if set(payload.get("states", [])) != STATES:
        errors.append("reservation lifecycle states are incomplete")
    invariants = payload.get("invariants", {})
    for key in ("atomic_claim", "negative_reservation_forbidden", "consumed_release_forbidden", "expiry_idempotent", "renewal_bounded", "cart_preserved_on_conflict"):
        if invariants.get(key) is not True:
            errors.append(f"reservation invariant not proven: {key}")
    backorder = payload.get("backorder", {})
    if backorder.get("implicit") is not False or backorder.get("explicit_table") != "sale_stock_backorders":
        errors.append("backorder must be explicit and separate from stock")
    ux = payload.get("back_office", {})
    if ux.get("human_ttl") is not True or ux.get("permission_controlled_release") != "sale.stock.manage":
        errors.append("back-office reservation UX proof is incomplete")
    security = payload.get("security", {})
    if security.get("contains_pii") is not False or security.get("contains_secret") is not False:
        errors.append("M6.2 evidence contains sensitive data")
    if root is not None:
        errors.extend(source_errors(root))
    return errors


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--report", type=Path, default=ROOT / "docs/evaluation/machine-readable/sale-reservations-availability.json")
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args(argv)
    try:
        payload = json.loads(args.report.read_text(encoding="utf-8"))
        errors = validate_evidence(payload if isinstance(payload, dict) else {}, ROOT)
    except (OSError, json.JSONDecodeError) as exc:
        errors = [f"invalid M6.2 evidence: {exc}"]
    result = {"ok": not errors, "gate": GATE_ID, "errors": errors}
    print(json.dumps(result, ensure_ascii=False, indent=2) if args.json else (f"Gate M6.2 validée: {args.report}" if not errors else "Gate M6.2 échouée:\n- " + "\n- ".join(errors)))
    return 0 if not errors else 1


if __name__ == "__main__":
    raise SystemExit(main())
