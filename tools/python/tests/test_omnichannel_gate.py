from __future__ import annotations

import copy
import unittest

from tools.python.qualification.omnichannel_gate import GATE_ID, REQUIRED_ORDER_FIELDS, validate_evidence


def valid_evidence() -> dict:
    schema = sorted(REQUIRED_ORDER_FIELDS)
    scenario = {
        "status": "passed",
        "product_id": 10,
        "sellable_id": 20,
        "channel_id": 1,
        "order_id": 100,
        "order_source": "ecommerce",
        "unit_price_minor": 2900,
        "stock_consumption_count": 1,
        "activity_count": 2,
        "order_schema": schema,
        "return_created": True,
        "refund_completed": True,
        "sandbox_payment_captured": True,
    }
    pos = scenario | {
        "channel_id": 2,
        "order_id": 101,
        "order_source": "pos",
        "refund_completed": False,
        "sandbox_payment_captured": False,
        "session_closed": True,
    }
    comparisons = {
        "same_product_id": True,
        "same_sellable_id": True,
        "shared_order_schema": True,
        "distinct_channel_id": True,
        "distinct_order_source": True,
        "snapshots_immutable": True,
        "crm_idempotent": True,
        "stock_coherent": True,
        "channel_pricing_applied": True,
        "permission_enforced": True,
        "contracts_aligned": True,
        "cross_database_boundaries_respected": True,
        "backup_restore_covered": True,
        "proof_redacted": True,
    }
    return {
        "format_version": 1,
        "gate": GATE_ID,
        "status": "passed",
        "scenarios": {"storefront": scenario, "pos": pos},
        "comparisons": comparisons,
        "crm": {"first_missing_events": 0, "second_missing_events": 0, "duplicate_events": 0, "second_repaired_events": 0},
        "security": {"contains_pii": False, "contains_secret": False},
    }


class OmnichannelGateTest(unittest.TestCase):
    def assert_mutation_fails(self, mutate) -> None:
        evidence = copy.deepcopy(valid_evidence())
        mutate(evidence)
        self.assertTrue(validate_evidence(evidence, root=None))

    def test_valid_cross_channel_evidence_passes(self) -> None:
        self.assertEqual([], validate_evidence(valid_evidence(), root=None))

    def test_channel_regression_fails_gate(self) -> None:
        self.assert_mutation_fails(lambda data: data["scenarios"]["pos"].update(channel_id=1))

    def test_price_regression_fails_gate(self) -> None:
        self.assert_mutation_fails(lambda data: data["scenarios"]["pos"].update(unit_price_minor=0))

    def test_stock_regression_fails_gate(self) -> None:
        self.assert_mutation_fails(lambda data: data["scenarios"]["storefront"].update(stock_consumption_count=0))

    def test_permission_regression_fails_gate(self) -> None:
        self.assert_mutation_fails(lambda data: data["comparisons"].update(permission_enforced=False))

    def test_report_rejects_pii_and_secrets_even_when_marked_redacted(self) -> None:
        self.assert_mutation_fails(lambda data: data.update(customer_email="person@example.test"))
        self.assert_mutation_fails(lambda data: data.update(sandbox_token="opaque"))


if __name__ == "__main__":
    unittest.main()
