from __future__ import annotations

import copy
import json
import unittest
from pathlib import Path

from tools.python.qualification.inventory_ledger_gate import validate_evidence


REPORT = Path(__file__).resolve().parents[3] / "docs/evaluation/machine-readable/sale-inventory-ledger.json"


class InventoryLedgerGateTest(unittest.TestCase):
    def setUp(self) -> None:
        self.evidence = json.loads(REPORT.read_text(encoding="utf-8"))

    def assert_mutation_fails(self, mutate) -> None:
        evidence = copy.deepcopy(self.evidence)
        mutate(evidence)
        self.assertTrue(validate_evidence(evidence, root=None))

    def test_canonical_evidence_passes(self) -> None:
        self.assertEqual([], validate_evidence(self.evidence, root=None))

    def test_business_cannot_be_source_of_truth(self) -> None:
        self.assert_mutation_fails(lambda data: data.update(source_of_truth="business.sqlite"))

    def test_public_quantity_leak_fails(self) -> None:
        self.assert_mutation_fails(lambda data: data["public_contract"].update(exposes_internal_quantity=True))

    def test_missing_ledger_correlation_fails(self) -> None:
        self.assert_mutation_fails(lambda data: data["ledger"]["fields"].remove("correlation_id"))

    def test_non_reconstructible_state_fails(self) -> None:
        self.assert_mutation_fails(lambda data: data["derived_state"].update(reconstructible=False))

    def test_direct_total_edit_fails(self) -> None:
        self.assert_mutation_fails(lambda data: data["ux"].update(direct_total_edit=True))


if __name__ == "__main__":
    unittest.main()
