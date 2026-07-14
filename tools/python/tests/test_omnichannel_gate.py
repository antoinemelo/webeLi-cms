from __future__ import annotations

import copy
import unittest

from tools.python.qualification.omnichannel_gate import (
    FORMAT_VERSION, GATE_ID, REQUIRED_FAILURE_CONTROLS, REQUIRED_ORDER_FIELDS,
    REQUIRED_ROLES, REQUIRED_UX, validate_evidence,
)

HASH = "a" * 64


def valid_evidence() -> dict:
    schema = sorted(REQUIRED_ORDER_FIELDS)
    scenario = {
        "status": "passed", "product_id": 10, "sellable_id": 20, "channel_id": 1,
        "order_id": 100, "order_source": "ecommerce", "unit_price_minor": 2900,
        "stock_consumption_count": 1, "activity_count": 2, "order_schema": schema,
        "return_created": True, "refund_completed": True, "sandbox_payment_captured": True,
        "fulfillment_created": True,
    }
    pos = scenario | {
        "channel_id": 2, "order_id": 101, "order_source": "pos", "refund_completed": False,
        "sandbox_payment_captured": False, "fulfillment_created": False, "session_closed": True,
    }
    comparisons = {key: True for key in {
        "same_product_id", "same_sellable_id", "shared_order_schema", "distinct_channel_id",
        "distinct_order_source", "snapshots_immutable", "crm_idempotent", "stock_coherent",
        "channel_pricing_applied", "permission_enforced", "contracts_aligned",
        "cross_database_boundaries_respected", "backup_restore_covered", "proof_redacted",
        "ui_cart_checkout", "partial_fulfillment", "payment_reconciled", "storefront_rebuilt",
    }}
    controls = {name: {"detected": True, "recovery_verified": True, "evidence_source": "runtime_or_release_contract"} for name in REQUIRED_FAILURE_CONTROLS}
    roles = {name: {"task_success": True, "significant_steps": 2, "errors": 0, "recovery_steps": 1, "automated_duration_ms": 10, "no_dead_end": True} for name in REQUIRED_ROLES}
    return {
        "format_version": FORMAT_VERSION, "gate": GATE_ID, "status": "passed",
        "build": {"commit": "abcdef0", "technical_version": "from-scratch-v1"},
        "providers": ["sandbox_online", "cash"],
        "scenarios": {"storefront": scenario, "pos": pos}, "comparisons": comparisons,
        "failure_controls": controls, "roles": roles, "ux": {name: True for name in REQUIRED_UX},
        "state_transitions": {"before_count": 1, "after_count": 2},
        "ledger": {"movement_count": 2, "sha256": HASH},
        "reservations": {"created_count": 1, "consumed_count": 1, "sha256": HASH},
        "payments": {"intent_count": 1, "capture_count": 1, "refund_count": 1, "sha256": HASH},
        "events": {"provider_event_count": 1, "business_event_count": 1, "correlation_count": 1, "sha256": HASH},
        "crm": {"first_missing_events": 0, "second_missing_events": 0, "duplicate_events": 0, "second_repaired_events": 0, "activity_count": 4, "sha256": HASH},
        "reconciliation": {"payment_consistent": True, "stock_remaining_differences": 0, "shop_rebuilt": True},
        "artifacts": {key: HASH for key in {"orders", "ledger", "reservations", "payments", "events", "crm", "reconciliation"}},
        "security": {"contains_pii": False, "contains_secret": False, "contains_card_data": False},
    }


class OmnichannelGateTest(unittest.TestCase):
    def assert_mutation_fails(self, mutate) -> None:
        evidence = copy.deepcopy(valid_evidence())
        mutate(evidence)
        self.assertTrue(validate_evidence(evidence, root=None))

    def test_valid_cross_channel_evidence_passes(self) -> None:
        self.assertEqual([], validate_evidence(valid_evidence(), root=None))

    def test_core_comparison_regressions_fail_gate(self) -> None:
        mutations = [
            lambda data: data["scenarios"]["pos"].update(channel_id=1),
            lambda data: data["scenarios"]["pos"].update(unit_price_minor=0),
            lambda data: data["scenarios"]["storefront"].update(stock_consumption_count=0),
            lambda data: data["comparisons"].update(permission_enforced=False),
            lambda data: data["reconciliation"].update(stock_remaining_differences=1),
        ]
        for mutate in mutations:
            with self.subTest(mutate=mutate):
                self.assert_mutation_fails(mutate)

    def test_each_required_failure_control_is_enforced(self) -> None:
        for name in sorted(REQUIRED_FAILURE_CONTROLS):
            with self.subTest(regression=name):
                self.assert_mutation_fails(lambda data, key=name: data["failure_controls"][key].update(detected=False))

    def test_each_role_and_ux_journey_is_enforced(self) -> None:
        for name in sorted(REQUIRED_ROLES):
            with self.subTest(role=name):
                self.assert_mutation_fails(lambda data, key=name: data["roles"][key].update(no_dead_end=False))
        for name in sorted(REQUIRED_UX):
            with self.subTest(journey=name):
                self.assert_mutation_fails(lambda data, key=name: data["ux"].update({key: False}))

    def test_evidence_package_rejects_missing_or_invalid_hashes(self) -> None:
        self.assert_mutation_fails(lambda data: data["artifacts"].pop("ledger"))
        self.assert_mutation_fails(lambda data: data["payments"].update(sha256="not-a-digest"))

    def test_report_rejects_pii_secrets_and_card_data(self) -> None:
        self.assert_mutation_fails(lambda data: data.update(customer_email="person@example.test"))
        self.assert_mutation_fails(lambda data: data.update(sandbox_token="opaque"))
        self.assert_mutation_fails(lambda data: data["security"].update(contains_card_data=True))


if __name__ == "__main__":
    unittest.main()
