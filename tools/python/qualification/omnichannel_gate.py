#!/usr/bin/env python3
"""Validate the machine-readable storefront/POS release-gate evidence."""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path
from typing import Any

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
GATE_ID = "cms-crm-sale-pos.omnichannel.v1"
REQUIRED_ORDER_FIELDS = {
    "id",
    "site_id",
    "channel_id",
    "order_number",
    "source",
    "status",
    "payment_status",
    "currency",
    "customer_snapshot_json",
    "subtotal_minor",
    "discount_total_minor",
    "tax_total_minor",
    "shipping_total_minor",
    "grand_total_minor",
    "lines",
}
REQUIRED_COMPARISONS = {
    "same_product_id",
    "same_sellable_id",
    "shared_order_schema",
    "distinct_channel_id",
    "distinct_order_source",
    "snapshots_immutable",
    "crm_idempotent",
    "stock_coherent",
    "channel_pricing_applied",
    "permission_enforced",
    "contracts_aligned",
    "cross_database_boundaries_respected",
    "backup_restore_covered",
    "proof_redacted",
}
FORBIDDEN_PROOF_KEY = re.compile(r"(?:email|password|token|secret|address|phone|recipient|customer_snapshot)", re.I)
EMAIL_LIKE = re.compile(r"\b[^\s@]+@[^\s@]+\.[^\s@]+\b")


def _positive_int(value: Any) -> bool:
    return isinstance(value, int) and not isinstance(value, bool) and value > 0


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
        "public checkout route": (
            root / "backend/src/Modules/Sale/SaleModuleProvider.php",
            "/api/v1/sale/channels/{code}/checkout",
        ),
        "POS checkout route": (
            root / "backend/src/Modules/Sale/SaleModuleProvider.php",
            "/admin/api/sale/pos/checkout",
        ),
        "public OpenAPI checkout": (
            root / "docs/reference/contracts/public-api/openapi.v1.json",
            '"/api/v1/sale/channels/{code}/checkout"',
        ),
        "SDK checkout response": (
            root / "packages/amcms-client/src/generated/openapi-types.ts",
            "OpenApiPublicSaleCheckoutResponse",
        ),
        "owned cross-database connections": (
            root / "backend/src/Modules/Business/Services/SaleCrmActivityProjectionService.php",
            "SaleDatabaseConnection",
        ),
        "backup covers declared database inventory": (
            root / "tools/python/validation/qualification/backup_restore_roundtrip.py",
            "_check_expected_inventory",
        ),
        "restored databases receive an integrity check": (
            root / "tools/python/validation/qualification/backup_restore_roundtrip.py",
            "PRAGMA integrity_check",
        ),
    }
    errors: list[str] = []
    for label, (path, needle) in checks.items():
        if not path.is_file() or needle not in path.read_text(encoding="utf-8", errors="ignore"):
            errors.append(f"contract source missing: {label}")
    projection = root / "backend/src/Modules/Business/Services/SaleCrmActivityProjectionService.php"
    projection_source = projection.read_text(encoding="utf-8", errors="ignore") if projection.is_file() else ""
    if "ATTACH DATABASE" in projection_source.upper() or "storage/database" in projection_source:
        errors.append("forbidden cross-database access in CRM Sale projection")
    return errors


def validate_evidence(evidence: dict[str, Any], root: Path | None = ROOT) -> list[str]:
    errors: list[str] = []
    if evidence.get("format_version") != 1:
        errors.append("format_version must be 1")
    if evidence.get("gate") != GATE_ID:
        errors.append(f"gate must be {GATE_ID}")
    if evidence.get("status") != "passed":
        errors.append("gate status must be passed")

    scenarios = evidence.get("scenarios")
    if not isinstance(scenarios, dict):
        return errors + ["scenarios must be an object"]
    web = scenarios.get("storefront")
    pos = scenarios.get("pos")
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

    if web.get("refund_completed") is not True:
        errors.append("storefront.refund_completed must be true")
    if web.get("sandbox_payment_captured") is not True:
        errors.append("storefront.sandbox_payment_captured must be true")
    if pos.get("session_closed") is not True:
        errors.append("pos.session_closed must be true")

    if web.get("product_id") != pos.get("product_id"):
        errors.append("product_id differs between storefront and POS")
    if web.get("sellable_id") != pos.get("sellable_id"):
        errors.append("sellable_id differs between storefront and POS")
    if web.get("channel_id") == pos.get("channel_id"):
        errors.append("channel_id must differ between storefront and POS")
    if web.get("order_source") == pos.get("order_source"):
        errors.append("order_source must differ between storefront and POS")
    if web.get("order_schema") != pos.get("order_schema"):
        errors.append("order schemas differ between storefront and POS")

    comparisons = evidence.get("comparisons")
    if not isinstance(comparisons, dict):
        errors.append("comparisons must be an object")
    else:
        for key in sorted(REQUIRED_COMPARISONS):
            if comparisons.get(key) is not True:
                errors.append(f"comparison {key} must be true")

    crm = evidence.get("crm")
    if not isinstance(crm, dict):
        errors.append("crm evidence is required")
    else:
        for key in ("first_missing_events", "second_missing_events", "duplicate_events", "second_repaired_events"):
            if crm.get(key) != 0:
                errors.append(f"crm.{key} must be zero")

    security = evidence.get("security")
    if not isinstance(security, dict) or security.get("contains_pii") is not False or security.get("contains_secret") is not False:
        errors.append("security proof must explicitly contain no PII or secret")
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
