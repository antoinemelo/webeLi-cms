#!/usr/bin/env python3
"""Validate the M5-M7 commerce usability review and browser evidence."""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path
from typing import Any

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
GATE_ID = "commerce-foundations.usability.m5-m7.v1"
STATIC_REPORT = ROOT / "docs/evaluation/machine-readable/usability-commerce-foundations.json"
RUNTIME_REPORT = ROOT / "storage/qualification/usability/latest.json"

ROLE_TASKS = {
    "mobile_customer": {"find_product", "open_product", "add_product", "understand_availability", "guest_checkout", "payment_success", "payment_decline_recovery", "confirmation", "refund_followup"},
    "pos_operator": {"open_session", "scan_or_search", "optional_customer", "checkout", "terminal_error_recovery", "print_receipt", "return", "close_session"},
    "operations": {"blocked_reservation", "partial_fulfillment", "pickup", "transfer", "inventory", "reconcile_difference"},
    "customer_service_finance": {"find_order", "understand_payment", "partial_refund", "resolve_reconciliation", "navigate_to_crm"},
    "crm": {"understand_timeline", "review_duplicate", "verify_provenance", "create_segment", "control_consent"},
}
CONTROLS = {
    "desktop_mobile", "keyboard_only", "visible_focus", "accessible_labels", "contrast_and_non_color_errors",
    "fr_en", "complete_ui_states", "input_preserved_after_error", "refresh_recovery", "no_dead_end",
    "visible_next_action", "coherent_object_links", "progressive_disclosure", "destructive_preview", "pii_free_telemetry",
}
STATES = {"loading", "empty", "success", "recoverable_error", "blocking_error", "partial", "permission_denied", "unavailable"}
HEURISTICS = {"clarity", "consistency", "error_prevention", "recovery", "efficiency", "accessibility", "mobile_readability", "status_explainability"}
SCREENS = {"shop_mobile", "product_mobile", "checkout_mobile", "pos_desktop", "operations_mobile", "finance_desktop", "crm_mobile"}
EVENTS = {"task_success", "task_abandon", "task_error", "task_recovery", "task_duration", "action_cancelled"}
TELEMETRY_FIELDS = {"contract", "event", "journey", "role", "outcome", "duration_bucket", "error_kind", "recovery_kind", "cancelled", "viewport", "language"}
FORBIDDEN_FIELDS = {"card", "address", "email", "phone", "free_text", "name", "order_number", "contact_id", "customer_id"}
SHA256 = re.compile(r"^[a-f0-9]{64}$")
SENSITIVE_KEY = re.compile(r"(?:^|_)(?:card|address|email|phone|free_?text|password|secret|token|name|order_?number|contact_?id|customer_?id)(?:$|_)", re.I)
EMAIL_LIKE = re.compile(r"\b[^\s@]+@[^\s@]+\.[^\s@]+\b")


def _leaks(value: Any, path: str = "$") -> list[str]:
    errors: list[str] = []
    if isinstance(value, dict):
        for key, item in value.items():
            child = f"{path}.{key}"
            if SENSITIVE_KEY.search(str(key)) and str(key) not in {"contains_pii", "contains_secret", "contains_card_data", "contains_free_text", "forbidden_fields"}:
                errors.append(f"sensitive key in evidence: {child}")
            errors.extend(_leaks(item, child))
    elif isinstance(value, list):
        for index, item in enumerate(value):
            errors.extend(_leaks(item, f"{path}[{index}]") )
    elif isinstance(value, str) and EMAIL_LIKE.search(value):
        errors.append(f"email-like value in evidence: {path}")
    return errors


def _source_errors(root: Path) -> list[str]:
    checks = {
        "mobile Shop and UI cart": (root / "frontend/admin-vue/tests/e2e/omnichannel-release-gate.spec.ts", "data-storefront-add-to-cart"),
        "decline recovery": (root / "frontend/admin-vue/tests/e2e/payment-provider-interchangeability.spec.ts", "without dead end"),
        "POS open and close": (root / "frontend/admin-vue/src/views/modules/SalePosView.vue", "/sale/pos/sessions/${session.value.id}/close"),
        "POS terminal recovery": (root / "frontend/admin-vue/src/views/modules/SalePosView.vue", "previousQuantity"),
        "POS receipt": (root / "frontend/admin-vue/src/views/modules/SalePosView.vue", "receipt/reprint"),
        "operations workflow": (root / "frontend/admin-vue/src/views/modules/SaleOperationsView.vue", "Diagnostic de reconstruction"),
        "partial fulfillment": (root / "tools/php/tests/unit/sale_stock_reconstruction_scenario_test.php", "partial fulfillment"),
        "finance refund and reconciliation": (root / "frontend/admin-vue/src/views/modules/SaleView.vue", "refundReasonCode"),
        "CRM timeline": (root / "frontend/admin-vue/src/views/modules/business/RelationTimeline.vue", "business.timeline.openLinked"),
        "CRM duplicate review": (root / "frontend/admin-vue/tests/e2e/sale-identity-review.spec.ts", "conflict"),
        "CRM Audience and consent": (root / "frontend/admin-vue/tests/e2e/business-crm-smoke.spec.ts", "creates an explainable audience"),
        "FR EN mobile navigation": (root / "frontend/admin-vue/tests/e2e/admin-i18n.spec.ts", "localized on desktop and mobile"),
        "global visible focus": (root / "frontend/admin-vue/src/styles.css", "Focus rings accessibles"),
        "automated usability scan": (root / "frontend/admin-vue/tests/e2e/commerce-usability-gate.spec.ts", "scanAccessibility"),
        "manual review document": (root / "docs/evaluation/usability-commerce-foundations.md", "Revue structurée des captures"),
    }
    errors: list[str] = []
    for label, (path, needle) in checks.items():
        if not path.is_file() or needle not in path.read_text(encoding="utf-8", errors="ignore"):
            errors.append(f"source evidence missing: {label}")
    return errors


def validate_static_evidence(payload: dict[str, Any], root: Path | None = ROOT) -> list[str]:
    errors: list[str] = []
    if payload.get("format_version") != 1 or payload.get("gate") != GATE_ID or payload.get("status") != "passed":
        errors.append("static usability gate identity/status is invalid")
    if payload.get("charter") != "02_CHARTE_UX_PRODUIT.md":
        errors.append("the product UX charter must be identified")
    roles = payload.get("roles")
    if not isinstance(roles, dict) or set(roles) != set(ROLE_TASKS):
        errors.append("all usability roles are required")
    else:
        for role, expected in ROLE_TASKS.items():
            tasks = roles[role].get("tasks") if isinstance(roles[role], dict) else None
            if not isinstance(tasks, dict) or set(tasks) != expected or any(value != "passed" for value in tasks.values()):
                errors.append(f"{role} tasks are incomplete or failing")
            if not isinstance(roles[role].get("evidence"), list) or not roles[role]["evidence"]:
                errors.append(f"{role} needs source evidence")
    controls = payload.get("controls")
    if not isinstance(controls, dict) or set(controls) != CONTROLS or any(value is not True for value in controls.values()):
        errors.append("all mandatory usability controls must pass")
    states = payload.get("states")
    if not isinstance(states, dict) or set(states) != STATES or any(not isinstance(value, list) or not value for value in states.values()):
        errors.append("every mandatory UI state needs evidence")
    screens = payload.get("screens")
    if not isinstance(screens, dict) or set(screens) != SCREENS:
        errors.append("all critical screens must be reviewed")
    else:
        for screen, review in screens.items():
            scores = review.get("scores") if isinstance(review, dict) else None
            if not isinstance(scores, dict) or set(scores) != HEURISTICS:
                errors.append(f"{screen} heuristic scores are incomplete")
                continue
            if any(not isinstance(score, int) or isinstance(score, bool) or score < 0 or score > 3 for score in scores.values()):
                errors.append(f"{screen} heuristic scores must be integers from 0 to 3")
            if any(score == 0 for score in scores.values()):
                errors.append(f"{screen} has a blocking heuristic score")
            if any(score == 1 for score in scores.values()) and not review.get("waiver"):
                errors.append(f"{screen} score 1 needs a correction or documented waiver")
            if review.get("review_status") != "reviewed" or not str(review.get("capture", "")).strip():
                errors.append(f"{screen} needs a reviewed capture reference")
    blockers = payload.get("blockers")
    if blockers != []:
        errors.append("P0 blockers must be empty before release")
    recommendations = payload.get("recommendations")
    if not isinstance(recommendations, dict) or set(recommendations) != {"P0", "P1", "P2"} or recommendations.get("P0") != []:
        errors.append("recommendations must be classified P0/P1/P2 with no open P0")
    telemetry = payload.get("instrumentation")
    if not isinstance(telemetry, dict):
        errors.append("anonymous usability instrumentation contract is required")
    else:
        if set(telemetry.get("events", [])) != EVENTS or set(telemetry.get("allowed_fields", [])) != TELEMETRY_FIELDS:
            errors.append("instrumentation events/allowlist are incomplete")
        if set(telemetry.get("forbidden_fields", [])) != FORBIDDEN_FIELDS:
            errors.append("instrumentation forbidden fields are incomplete")
        if telemetry.get("collection_default") != "disabled" or telemetry.get("payload_mode") != "allowlist_only":
            errors.append("instrumentation must be opt-in and allowlist-only")
    limits = payload.get("limitations")
    if not isinstance(limits, list) or not any("utilisateur" in str(item).lower() for item in limits):
        errors.append("the lack of real-user testing must be stated honestly")
    security = payload.get("security")
    if not isinstance(security, dict) or security.get("contains_pii") is not False or security.get("contains_secret") is not False or security.get("contains_card_data") is not False:
        errors.append("static evidence must explicitly contain no PII, secret or card data")
    errors.extend(_leaks(payload))
    if root is not None:
        errors.extend(_source_errors(root))
    return errors


def validate_runtime_evidence(payload: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    if payload.get("format_version") != 1 or payload.get("gate") != GATE_ID or payload.get("status") != "passed":
        errors.append("runtime usability gate identity/status is invalid")
    screens = payload.get("screens")
    if not isinstance(screens, dict) or set(screens) != SCREENS:
        errors.append("runtime report must scan all critical screens")
    else:
        for name, screen in screens.items():
            if not isinstance(screen, dict):
                errors.append(f"runtime screen {name} must be an object")
                continue
            if screen.get("unnamed_controls") != 0 or screen.get("unlabelled_fields") != 0 or screen.get("contrast_violations") != 0:
                errors.append(f"runtime screen {name} has an accessibility violation")
            if screen.get("horizontal_overflow") is not False or screen.get("focus_visible") is not True:
                errors.append(f"runtime screen {name} fails responsive/focus checks")
            if not isinstance(screen.get("interactive_count"), int) or screen["interactive_count"] < 1:
                errors.append(f"runtime screen {name} has no audited interaction")
            if not SHA256.fullmatch(str(screen.get("capture_sha256", ""))):
                errors.append(f"runtime screen {name} capture hash is invalid")
    interactions = payload.get("interactions")
    if not isinstance(interactions, dict) or set(interactions) != set(ROLE_TASKS) or any(not isinstance(value, int) or value < 1 for value in interactions.values()):
        errors.append("runtime interaction counts must cover every role")
    microcopy = payload.get("microcopy")
    required_copy = {"availability", "next_action", "recoverable_error", "permission", "test_mode"}
    if not isinstance(microcopy, dict) or set(microcopy) != required_copy or any(value is not True for value in microcopy.values()):
        errors.append("key usability microcopy is incomplete")
    samples = payload.get("telemetry_samples")
    if not isinstance(samples, list) or {sample.get("event") for sample in samples if isinstance(sample, dict)} != EVENTS:
        errors.append("runtime telemetry samples must cover every anonymous event")
    else:
        for index, sample in enumerate(samples):
            if not isinstance(sample, dict) or not set(sample).issubset(TELEMETRY_FIELDS) or sample.get("contract") != "commerce.usability.v1":
                errors.append(f"runtime telemetry sample {index} violates the allowlist")
    security = payload.get("security")
    if not isinstance(security, dict) or security.get("contains_pii") is not False or security.get("contains_secret") is not False or security.get("contains_card_data") is not False or security.get("contains_free_text") is not False:
        errors.append("runtime proof must explicitly contain no sensitive data")
    errors.extend(_leaks(payload))
    return errors


def _read(path: Path, label: str) -> tuple[dict[str, Any] | None, list[str]]:
    if not path.is_file():
        return None, [f"{label} missing: {path}"]
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        return None, [f"invalid {label}: {exc}"]
    return (payload, []) if isinstance(payload, dict) else (None, [f"{label} root must be an object"])


def validate_report_files(static_path: Path = STATIC_REPORT, runtime_path: Path = RUNTIME_REPORT, root: Path | None = ROOT) -> list[str]:
    static, errors = _read(static_path, "static usability report")
    runtime, runtime_errors = _read(runtime_path, "runtime usability report")
    errors.extend(runtime_errors)
    if static is not None:
        errors.extend(validate_static_evidence(static, root))
    if runtime is not None:
        errors.extend(validate_runtime_evidence(runtime))
    return errors


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Valide la gate d'utilisabilité Commerce M5-M7.")
    parser.add_argument("--static-report", type=Path, default=STATIC_REPORT)
    parser.add_argument("--runtime-report", type=Path, default=RUNTIME_REPORT)
    parser.add_argument("--static-only", action="store_true")
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args(argv)
    static, errors = _read(args.static_report, "static usability report")
    if static is not None:
        errors.extend(validate_static_evidence(static))
    if not args.static_only:
        runtime, runtime_errors = _read(args.runtime_report, "runtime usability report")
        errors.extend(runtime_errors)
        if runtime is not None:
            errors.extend(validate_runtime_evidence(runtime))
    result = {"ok": not errors, "gate": GATE_ID, "static_report": str(args.static_report), "runtime_report": None if args.static_only else str(args.runtime_report), "errors": errors}
    if args.json:
        print(json.dumps(result, ensure_ascii=False, indent=2))
    elif errors:
        print("Gate d'utilisabilité Commerce échouée:\n- " + "\n- ".join(errors), file=sys.stderr)
    else:
        print("Gate d'utilisabilité Commerce validée.")
    return 0 if not errors else 1


if __name__ == "__main__":
    raise SystemExit(main())
