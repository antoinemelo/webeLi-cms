from __future__ import annotations

import copy
import json
import unittest
from pathlib import Path

from tools.python.qualification.payment_provider_gate import validate_evidence


REPORT = Path(__file__).resolve().parents[3] / "docs/evaluation/machine-readable/sale-payment-provider-interchangeability.json"


class PaymentProviderGateTest(unittest.TestCase):
    def setUp(self) -> None:
        self.evidence = json.loads(REPORT.read_text(encoding="utf-8"))

    def assert_mutation_fails(self, mutate) -> None:
        evidence = copy.deepcopy(self.evidence)
        mutate(evidence)
        self.assertTrue(validate_evidence(evidence, root=None))

    def test_canonical_evidence_passes(self) -> None:
        self.assertEqual([], validate_evidence(self.evidence, root=None))

    def test_missing_second_provider_fails(self) -> None:
        self.assert_mutation_fails(lambda data: data.update(second_real_provider="stripe_checkout"))

    def test_unknown_second_provider_fails_without_crashing(self) -> None:
        self.assert_mutation_fails(lambda data: data.update(second_real_provider="unknown"))

    def test_non_real_provider_alias_fails(self) -> None:
        self.assert_mutation_fails(lambda data: data.update(second_real_provider="manual_card"))

    def test_implicit_capability_fails(self) -> None:
        self.assert_mutation_fails(lambda data: data["providers"]["revolut_checkout"]["capabilities"].pop("partial_refund"))

    def test_unsupported_operation_cannot_claim_success(self) -> None:
        self.assert_mutation_fails(lambda data: data["providers"]["revolut_checkout"]["scenarios"].update(refund="passed"))

    def test_provider_specific_ux_fails(self) -> None:
        self.assert_mutation_fails(lambda data: data["providers"]["revolut_checkout"]["ux"].update(meaningful_step_count=3))

    def test_unjustified_comparison_fails(self) -> None:
        self.assert_mutation_fails(lambda data: data["comparisons"].update(no_dead_end=False))


if __name__ == "__main__":
    unittest.main()
