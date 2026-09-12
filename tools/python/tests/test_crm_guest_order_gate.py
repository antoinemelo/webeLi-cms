from __future__ import annotations

import copy
import json
import unittest

from tools.python.qualification.crm_guest_order_gate import REPORT, validate


class CrmGuestOrderGateTest(unittest.TestCase):
    def payload(self):
        return json.loads(REPORT.read_text(encoding="utf-8"))

    def test_reference(self):
        self.assertEqual([], validate(self.payload()))

    def test_rejects_missing_scenario(self):
        value = copy.deepcopy(self.payload())
        value["scenarios"].pop()
        self.assertTrue(validate(value))

    def test_rejects_implicit_consent(self):
        value = copy.deepcopy(self.payload())
        value["assertions"]["implicit_marketing_consent"] = True
        self.assertTrue(validate(value))

    def test_rejects_activity_duplicates(self):
        value = copy.deepcopy(self.payload())
        value["assertions"]["duplicate_activity_count"] = 1
        self.assertTrue(validate(value))

    def test_rejects_migration(self):
        value = copy.deepcopy(self.payload())
        value["fresh_instance"]["migration_required"] = True
        self.assertTrue(validate(value))


if __name__ == "__main__":
    unittest.main()
