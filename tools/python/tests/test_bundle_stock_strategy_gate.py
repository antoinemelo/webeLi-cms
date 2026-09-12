from __future__ import annotations

import json
import unittest

from tools.python.qualification.bundle_stock_strategy_gate import ROOT, validate_evidence


class BundleStockStrategyGateTest(unittest.TestCase):
    def payload(self) -> dict:
        return json.loads((ROOT / "docs/evaluation/machine-readable/bundle-stock-strategies.json").read_text(encoding="utf-8"))

    def test_canonical_evidence_passes(self) -> None:
        self.assertEqual([], validate_evidence(self.payload(), ROOT))

    def test_missing_strategy_fails(self) -> None:
        payload = self.payload(); payload["strategies"].remove("NON_STOCKED")
        self.assertIn("bundle stock strategies are incomplete", validate_evidence(payload, None))

    def test_partial_requires_fulfillment_support(self) -> None:
        payload = self.payload(); payload["partial_availability"]["allow_partial_enabled"] = True
        self.assertIn("v1 partial availability policy is unsafe", validate_evidence(payload, None))

    def test_non_stocked_movement_fails(self) -> None:
        payload = self.payload(); payload["inventory"]["non_stocked_has_physical_movement"] = True
        self.assertIn("NON_STOCKED must not create physical movements", validate_evidence(payload, None))


if __name__ == "__main__":
    unittest.main()
