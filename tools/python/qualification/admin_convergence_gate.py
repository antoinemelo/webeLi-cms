#!/usr/bin/env python3
"""Validate the 38e UX convergence gate for the administration."""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path
from typing import Any

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
GATE_ID = "admin-convergence.ux.38e.v1"
STATIC_REPORT = ROOT / "docs/evaluation/machine-readable/admin-convergence-ux-38e.json"
RUNTIME_REPORT = ROOT / "storage/qualification/admin-convergence/latest.json"

SCENARIO_TASKS = {
    "service_relation": {"global_search", "understand_next_action", "linked_order_documents", "submitted_form", "memo_context_return"},
    "sales_operator": {"open_work_queue", "select_order", "understand_dossier", "request_or_record_payment", "prepare_or_deliver"},
    "backorder_product": {"open_uninvoiced_order", "record_arrival", "request_payment", "recover_expired_authorization", "invoice_then_deliver"},
    "pos": {"immediate_handover", "deferred_handover", "backorder_payment_policy", "same_order_dossier"},
    "product_stock": {"find_product", "understand_availability", "count_or_receive", "observe_consumers", "immutable_audit"},
    "offers_marketing": {"create_offer", "understand_scope_stacking", "preview_before_activation", "audience_not_consent"},
    "commerce": {"enable_module", "shop_remains_unchanged", "open_authorized_panel", "disable_enable_preserves_configuration"},
    "advanced_tools": {"open_from_objects", "inspect_diagnostics", "ordinary_role_denied"},
}
CONTROLS = {
    "single_primary_action", "business_vocabulary", "compact_navigation", "closable_contextual_help",
    "preserved_filters_return", "complete_states", "input_preservation", "desktop_mobile",
    "keyboard_focus_screen_reader", "fr_en", "pii_free_evidence", "advanced_routes_protected",
    "financial_truth", "commerce_shop_independence", "fresh_rebuild",
}
MEASURES = {"task_success", "significant_steps", "backtracks", "errors", "duration_baseline_ms", "next_action_visible", "recovery"}
SHA256 = re.compile(r"^[a-f0-9]{64}$")
EMAIL_LIKE = re.compile(r"\b[^\s@]+@[^\s@]+\.[^\s@]+\b")
SENSITIVE_KEY = re.compile(r"(?:^|_)(?:email|phone|address|card|password|secret|token|free_?text|customer_?id|contact_?id)(?:$|_)", re.I)


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
            if SENSITIVE_KEY.search(str(key)) and str(key) not in {
                "contains_pii", "contains_secret", "contains_card_data", "contains_free_text",
            }:
                errors.append(f"sensitive key in convergence evidence: {child}")
            errors.extend(_leaks(item, child))
    elif isinstance(value, list):
        for index, item in enumerate(value):
            errors.extend(_leaks(item, f"{path}[{index}]") )
    elif isinstance(value, str) and EMAIL_LIKE.search(value):
        errors.append(f"email-like value in convergence evidence: {path}")
    return errors


def _source_errors(root: Path) -> list[str]:
    checks = {
        "architecture": (root / "docs/development/architecture/admin-modules-navigation.md", "Cockpit"),
        "route map": (root / "docs/reference/admin-convergence-routes.md", "/sale/advanced/stock"),
        "role matrix": (root / "docs/administration/admin-convergence-roles.md", "business.advanced_tools.manage"),
        "human review": (root / "docs/evaluation/admin-convergence-ux-gate-38e.md", "Revue manuelle structurée"),
        "global navigation E2E": (root / "frontend/admin-vue/tests/e2e/admin-architecture-navigation.spec.ts", "permission-aware global search"),
        "relation E2E": (root / "frontend/admin-vue/tests/e2e/business-relation-360.spec.ts", "Relation 360"),
        "sales and deferred E2E": (root / "frontend/admin-vue/tests/e2e/sale-order-dossier-38c.spec.ts", "deferred order"),
        "operations and offers E2E": (root / "frontend/admin-vue/tests/e2e/business-operations-products-stock-offers-38d.spec.ts", "Audience semantics"),
        "convergence runtime E2E": (root / "frontend/admin-vue/tests/e2e/admin-convergence-gate-38e.spec.ts", GATE_ID),
        "Sale HTTP permission": (root / "tools/php/tests/unit/sale_admin_api_controller_test.php", "cannot bypass the dedicated advanced-tools permission"),
        "Business HTTP permission": (root / "tools/php/tests/unit/business_pim_api_controller_test.php", "cannot rebuild storefront projections without the dedicated advanced-tools permission"),
        "Sale E-Commerce ownership": (root / "tools/php/tests/unit/commerce_module_lifecycle_test.php", "no longer exposed as an autonomous module"),
    }
    errors: list[str] = []
    for label, (path, needle) in checks.items():
        if not path.is_file() or needle not in path.read_text(encoding="utf-8", errors="ignore"):
            errors.append(f"source evidence missing: {label}")
    return errors


def validate_static_evidence(payload: dict[str, Any], root: Path | None = ROOT) -> list[str]:
    errors: list[str] = []
    if payload.get("format_version") != 1 or payload.get("gate") != GATE_ID or payload.get("status") != "passed":
        errors.append("static convergence gate identity/status is invalid")
    if payload.get("charters") != ["02_CHARTE_UX_PRODUIT.md", "04_SPEC_ADMIN_ORIENTEE_UTILISATEUR.md"]:
        errors.append("both administration UX sources must be identified")
    if payload.get("extends_gates") != [37, 38]:
        errors.append("38e must extend, not replace, gates 37 and 38")
    scenarios = payload.get("scenarios")
    if not isinstance(scenarios, dict) or set(scenarios) != set(SCENARIO_TASKS):
        errors.append("all 38e scenarios are required")
    else:
        for scenario, expected_tasks in SCENARIO_TASKS.items():
            evidence = scenarios[scenario]
            tasks = evidence.get("tasks") if isinstance(evidence, dict) else None
            if not isinstance(tasks, dict) or set(tasks) != expected_tasks or any(status != "passed" for status in tasks.values()):
                errors.append(f"{scenario} tasks are incomplete or failing")
            if not isinstance(evidence.get("evidence"), list) or not evidence["evidence"]:
                errors.append(f"{scenario} needs explicit evidence")
            if not str(evidence.get("role", "")).strip() or not str(evidence.get("start", "")).strip() or not str(evidence.get("expected", "")).strip():
                errors.append(f"{scenario} needs role, start and expected outcome")
    controls = payload.get("controls")
    if not isinstance(controls, dict) or set(controls) != CONTROLS or any(value is not True for value in controls.values()):
        errors.append("all mandatory convergence controls must pass")
    measures = payload.get("measures")
    if not isinstance(measures, dict) or set(measures) != MEASURES or any(value is not True for value in measures.values()):
        errors.append("all mandatory task measures must be collected")
    if payload.get("duration_policy") != {"mode": "baseline_only", "arbitrary_threshold": False}:
        errors.append("duration must be a baseline without an arbitrary threshold")
    gaps = payload.get("gaps")
    if not isinstance(gaps, dict) or set(gaps) != {"P0", "P1", "P2"} or gaps.get("P0") != []:
        errors.append("gaps must be classified P0/P1/P2 with no open P0")
    security = payload.get("security")
    if not isinstance(security, dict) or security.get("contains_pii") is not False or security.get("contains_secret") is not False or security.get("contains_card_data") is not False:
        errors.append("static convergence evidence must explicitly contain no sensitive data")
    limits = payload.get("limitations")
    if not isinstance(limits, list) or not limits:
        errors.append("convergence limitations must be explicit")
    errors.extend(_leaks(payload))
    if root is not None:
        errors.extend(_source_errors(root))
    return errors


def validate_runtime_evidence(payload: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    if payload.get("format_version") != 1 or payload.get("gate") != GATE_ID or payload.get("status") != "passed":
        errors.append("runtime convergence gate identity/status is invalid")
    scenarios = payload.get("scenarios")
    if not isinstance(scenarios, dict) or set(scenarios) != set(SCENARIO_TASKS):
        errors.append("runtime report must measure every 38e scenario")
    else:
        for name, result in scenarios.items():
            if not isinstance(result, dict) or result.get("status") != "passed":
                errors.append(f"runtime scenario {name} did not pass")
                continue
            for field in ("steps", "backtracks", "errors", "duration_ms"):
                value = result.get(field)
                minimum = 1 if field in {"steps", "duration_ms"} else 0
                if not isinstance(value, int) or isinstance(value, bool) or value < minimum:
                    errors.append(f"runtime scenario {name} has invalid {field}")
            if result.get("next_action_visible") is not True or result.get("recovery_verified") is not True:
                errors.append(f"runtime scenario {name} lacks next-action or recovery proof")
            captures = result.get("captures")
            if not isinstance(captures, dict) or set(captures) != {"desktop", "mobile"}:
                errors.append(f"runtime scenario {name} needs desktop and mobile captures")
                continue
            for viewport, capture in captures.items():
                if not isinstance(capture, dict) or not SHA256.fullmatch(str(capture.get("sha256", ""))):
                    errors.append(f"runtime scenario {name} has invalid {viewport} capture")
                    continue
                if capture.get("horizontal_overflow") is not False or capture.get("focus_visible") is not True:
                    errors.append(f"runtime scenario {name} fails responsive/focus checks on {viewport}")
                if capture.get("unnamed_controls") != 0 or capture.get("unlabelled_fields") != 0:
                    errors.append(f"runtime scenario {name} has unnamed controls or fields on {viewport}")
    permissions = payload.get("permissions")
    if not isinstance(permissions, dict) or permissions.get("ordinary_navigation_hides_advanced") is not True or permissions.get("advanced_navigation_visible") is not True:
        errors.append("runtime role distinction is incomplete")
    security = payload.get("security")
    if not isinstance(security, dict) or any(security.get(key) is not False for key in ("contains_pii", "contains_secret", "contains_card_data", "contains_free_text")):
        errors.append("runtime convergence proof must explicitly contain no sensitive data")
    errors.extend(_leaks(payload))
    return errors


def validate_report_files(
    static_path: Path = STATIC_REPORT,
    runtime_path: Path = RUNTIME_REPORT,
    root: Path | None = ROOT,
) -> list[str]:
    static, errors = _read(static_path, "static convergence report")
    runtime, runtime_errors = _read(runtime_path, "runtime convergence report")
    errors.extend(runtime_errors)
    if static is not None:
        errors.extend(validate_static_evidence(static, root))
    if runtime is not None:
        errors.extend(validate_runtime_evidence(runtime))
    return errors


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Valide la gate UX de convergence admin 38e.")
    parser.add_argument("--static-report", type=Path, default=STATIC_REPORT)
    parser.add_argument("--runtime-report", type=Path, default=RUNTIME_REPORT)
    parser.add_argument("--static-only", action="store_true")
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args(argv)
    static, errors = _read(args.static_report, "static convergence report")
    if static is not None:
        errors.extend(validate_static_evidence(static))
    if not args.static_only:
        runtime, runtime_errors = _read(args.runtime_report, "runtime convergence report")
        errors.extend(runtime_errors)
        if runtime is not None:
            errors.extend(validate_runtime_evidence(runtime))
    result = {
        "ok": not errors,
        "gate": GATE_ID,
        "static_report": str(args.static_report),
        "runtime_report": None if args.static_only else str(args.runtime_report),
        "errors": errors,
    }
    if args.json:
        print(json.dumps(result, ensure_ascii=False, indent=2))
    elif errors:
        print("Gate UX de convergence admin échouée:\n- " + "\n- ".join(errors), file=sys.stderr)
    else:
        print("Gate UX de convergence admin validée.")
    return 0 if not errors else 1


if __name__ == "__main__":
    raise SystemExit(main())
