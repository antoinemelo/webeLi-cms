from __future__ import annotations

import json
import unittest

from tools.python.qualification.stock_reconstruction_gate import ROOT, validate_evidence


class StockReconstructionGateTest(unittest.TestCase):
    def payload(self) -> dict:
        return json.loads((ROOT / "docs/evaluation/machine-readable/sale-stock-reconstruction-m6.json").read_text(encoding="utf-8"))

    def test_canonical_evidence_passes(self) -> None:
        self.assertEqual([], validate_evidence(self.payload(), ROOT))

    def test_repair_cannot_be_automatic(self) -> None:
        payload = self.payload(); payload["repair"]["automatic_by_default"] = True
        self.assertIn("M6.5 repair must never be silent or mutate the ledger", validate_evidence(payload, None))

    def test_backup_is_mandatory(self) -> None:
        payload = self.payload(); payload["repair"]["backup_before_write"] = False
        self.assertIn("M6.5 repair safety not proven: backup_before_write", validate_evidence(payload, None))

    def test_every_comparison_is_required(self) -> None:
        payload = self.payload(); payload["comparisons"].remove("transfers")
        self.assertIn("M6.5 source or comparison coverage is incomplete", validate_evidence(payload, None))


if __name__ == "__main__":
    unittest.main()
