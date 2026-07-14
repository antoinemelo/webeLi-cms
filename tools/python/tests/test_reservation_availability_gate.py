from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path

from tools.python.qualification.reservation_availability_gate import ROOT, validate_evidence


class ReservationAvailabilityGateTest(unittest.TestCase):
    def payload(self) -> dict:
        return json.loads((ROOT / "docs/evaluation/machine-readable/sale-reservations-availability.json").read_text(encoding="utf-8"))

    def test_canonical_evidence_passes(self) -> None:
        self.assertEqual([], validate_evidence(self.payload(), ROOT))

    def test_missing_atomic_claim_fails(self) -> None:
        payload = self.payload(); payload["invariants"]["atomic_claim"] = False
        self.assertIn("reservation invariant not proven: atomic_claim", validate_evidence(payload, None))

    def test_implicit_backorder_fails(self) -> None:
        payload = self.payload(); payload["backorder"]["implicit"] = True
        self.assertIn("backorder must be explicit and separate from stock", validate_evidence(payload, None))

    def test_missing_trigger_fails(self) -> None:
        payload = self.payload(); payload["triggers"].remove("payment_capture")
        self.assertIn("reservation triggers are incomplete", validate_evidence(payload, None))


if __name__ == "__main__":
    unittest.main()
