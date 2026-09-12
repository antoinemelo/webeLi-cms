#!/usr/bin/env python3
"""Validate the M5 provider-interchangeability proof and source invariants."""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path
from typing import Any

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
GATE_ID = "sale.payment-provider-interchangeability.m5.v1"
PROVIDERS = {"test", "manual_card", "bank_transfer", "stripe_checkout", "revolut_checkout"}
PROVIDER_KINDS = {
    "test": "test",
    "manual_card": "manual",
    "bank_transfer": "offline",
    "stripe_checkout": "real",
    "revolut_checkout": "real",
}
CAPABILITIES = {"authorize", "delayed_capture", "partial_capture", "refund", "partial_refund", "webhook", "reconciliation"}
SCENARIOS = {"success", "failure", "retry", "capture", "refund", "duplicate_webhook", "invalid_signature", "reconciliation"}
COMPARISONS = {
    "same_shop_contract", "same_checkout_steps", "same_success_states", "same_failure_states", "same_recovery_action",
    "same_layout", "same_fr_en_labels", "keyboard_accessible", "mobile_accessible", "test_mode_isolated",
    "backoffice_consistent", "no_controller_provider_selection", "no_dead_end",
}


def _source_errors(root: Path) -> list[str]:
    errors: list[str] = []
    controller_paths = [
        root / "backend/src/Application/Api/Admin/SaleAdminApiController.php",
        root / "backend/src/Application/PublicApi/PublicSaleApiHandler.php",
    ]
    controller_source = "\n".join(path.read_text(encoding="utf-8", errors="ignore") for path in controller_paths if path.is_file())
    if "stripe_checkout" in controller_source or "revolut_checkout" in controller_source:
        errors.append("a controller selects a real payment provider")
    shop = root / "frontend/theme-default/assets/js/guest-checkout.js"
    shop_source = shop.read_text(encoding="utf-8", errors="ignore") if shop.is_file() else ""
    if "stripe_checkout" in shop_source or "revolut_checkout" in shop_source:
        errors.append("Shop contains a real-provider-specific branch")
    checks = {
        "channel-scoped payment method selection": (root / "backend/src/Modules/Sale/Services/SalePaymentMethodService.php", "channel_id=?"),
        "second provider environment alias": (root / "backend/config/app.php", "PROVIDER_REAL_2"),
        "common PHP contract suite": (root / "tools/php/tests/unit/sale_payment_provider_interchangeability_test.php", "PaymentProviderContractV1::VERSION"),
        "provider UX E2E suite": (root / "frontend/admin-vue/tests/e2e/payment-provider-interchangeability.spec.ts", "revolut_checkout"),
        "generic Shop continuation": (shop, "Continue payment"),
    }
    for label, (path, needle) in checks.items():
        if not path.is_file() or needle not in path.read_text(encoding="utf-8", errors="ignore"):
            errors.append(f"source evidence missing: {label}")
    return errors


def validate_evidence(payload: dict[str, Any], root: Path | None = ROOT) -> list[str]:
    errors: list[str] = []
    if payload.get("format_version") != 1:
        errors.append("format_version must be 1")
    if payload.get("gate") != GATE_ID:
        errors.append(f"gate must be {GATE_ID}")
    if payload.get("status") != "passed":
        errors.append("gate status must be passed")
    if payload.get("contract_version") != "sale.payment_provider.v1":
        errors.append("canonical contract version changed")
    primary = payload.get("primary_real_provider")
    second = payload.get("second_real_provider")
    if primary == second:
        errors.append("two distinct real providers are required")
    providers = payload.get("providers")
    if not isinstance(providers, dict) or set(providers) != PROVIDERS:
        return errors + ["the five required provider profiles are required"]
    for key, provider in providers.items():
        if not isinstance(provider, dict):
            errors.append(f"{key} profile must be an object")
            continue
        if provider.get("kind") != PROVIDER_KINDS[key]:
            errors.append(f"{key} kind must be {PROVIDER_KINDS[key]}")
        capabilities = provider.get("capabilities")
        if not isinstance(capabilities, dict) or set(capabilities) != CAPABILITIES or not all(isinstance(value, bool) for value in capabilities.values()):
            errors.append(f"{key} capabilities must be explicit booleans")
            continue
        scenarios = provider.get("scenarios")
        if not isinstance(scenarios, dict) or set(scenarios) != SCENARIOS:
            errors.append(f"{key} scenarios are incomplete")
            continue
        if any(value not in {"passed", "not_applicable"} for value in scenarios.values()):
            errors.append(f"{key} has a failing or unknown scenario")
        for scenario, capability in (("capture", "delayed_capture"), ("refund", "refund"), ("duplicate_webhook", "webhook"), ("invalid_signature", "webhook"), ("reconciliation", "reconciliation")):
            expected = "passed" if capabilities[capability] else "not_applicable"
            if scenarios[scenario] != expected:
                errors.append(f"{key}.{scenario} must be {expected}")
    if not all(isinstance(key, str) and key in providers and providers[key].get("kind") == "real" for key in (primary, second)):
        errors.append("primary and second providers must identify real provider profiles")
    else:
        real_ux = [providers[key].get("ux") for key in (primary, second)]
        if not all(isinstance(item, dict) for item in real_ux) or real_ux[0] != real_ux[1]:
            errors.append("real provider UX contracts differ")
    comparisons = payload.get("comparisons")
    if not isinstance(comparisons, dict) or any(comparisons.get(key) is not True for key in COMPARISONS):
        errors.append("all interchangeability comparisons must pass")
    fields = payload.get("public_contract_fields")
    if not isinstance(fields, list) or len(fields) != len(set(fields)) or not {"code", "label", "next_action", "capabilities", "provider_key", "contract_version"}.issubset(fields):
        errors.append("public Shop contract fields are incomplete")
    security = payload.get("security")
    if not isinstance(security, dict) or security.get("contains_pii") is not False or security.get("contains_secret") is not False:
        errors.append("security proof must explicitly contain no PII or secret")
    if root is not None:
        errors.extend(_source_errors(root))
    return errors


def validate_report_file(path: Path, root: Path | None = ROOT) -> tuple[dict[str, Any] | None, list[str]]:
    if not path.is_file():
        return None, [f"provider gate report missing: {path}"]
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        return None, [f"invalid provider gate report: {exc}"]
    if not isinstance(payload, dict):
        return None, ["provider gate report root must be an object"]
    return payload, validate_evidence(payload, root)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Valide la gate M5 d'interchangeabilité des providers de paiement.")
    parser.add_argument("--report", type=Path, default=ROOT / "docs/evaluation/machine-readable/sale-payment-provider-interchangeability.json")
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args(argv)
    _payload, errors = validate_report_file(args.report)
    result = {"ok": not errors, "gate": GATE_ID, "report": str(args.report), "errors": errors}
    if args.json:
        print(json.dumps(result, ensure_ascii=False, indent=2))
    elif errors:
        print("Gate providers M5 échouée:\n- " + "\n- ".join(errors), file=sys.stderr)
    else:
        print(f"Gate providers M5 validée: {args.report}")
    return 0 if not errors else 1


if __name__ == "__main__":
    raise SystemExit(main())
