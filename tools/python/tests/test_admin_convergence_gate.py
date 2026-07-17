from __future__ import annotations

import copy
import json
import unittest

from tools.python.qualification.admin_convergence_gate import (
    GATE_ID,
    SCENARIO_TASKS,
    STATIC_REPORT,
    validate_runtime_evidence,
    validate_static_evidence,
)


def static_evidence() -> dict:
    return json.loads(STATIC_REPORT.read_text(encoding="utf-8"))


def runtime_evidence() -> dict:
    capture = {
        "file": "synthetic.png",
        "sha256": "a" * 64,
        "viewport": "390x844",
        "unnamed_controls": 0,
        "unlabelled_fields": 0,
        "horizontal_overflow": False,
        "focus_visible": True,
    }
    scenario = {
        "status": "passed",
        "steps": 3,
        "backtracks": 0,
        "errors": 0,
        "duration_ms": 10,
        "next_action_visible": True,
        "recovery_verified": True,
        "captures": {"desktop": copy.deepcopy(capture), "mobile": copy.deepcopy(capture)},
    }
    return {
        "format_version": 1,
        "gate": GATE_ID,
        "status": "passed",
        "scenarios": {name: copy.deepcopy(scenario) for name in SCENARIO_TASKS},
        "permissions": {"ordinary_navigation_hides_advanced": True, "advanced_navigation_visible": True},
        "security": {"contains_pii": False, "contains_secret": False, "contains_card_data": False, "contains_free_text": False},
    }


class AdminConvergenceGateTest(unittest.TestCase):
    def test_static_evidence_passes(self) -> None:
        self.assertEqual([], validate_static_evidence(static_evidence(), root=None))

    def test_runtime_evidence_passes(self) -> None:
        self.assertEqual([], validate_runtime_evidence(runtime_evidence()))

    def test_every_scenario_task_is_mandatory(self) -> None:
        for scenario, tasks in SCENARIO_TASKS.items():
            for task in tasks:
                payload = static_evidence()
                payload["scenarios"][scenario]["tasks"].pop(task)
                with self.subTest(scenario=scenario, task=task):
                    self.assertTrue(validate_static_evidence(payload, root=None))

    def test_open_p0_or_arbitrary_duration_threshold_blocks_gate(self) -> None:
        payload = static_evidence()
        payload["gaps"]["P0"] = ["dead end"]
        self.assertTrue(validate_static_evidence(payload, root=None))
        payload = static_evidence()
        payload["duration_policy"]["arbitrary_threshold"] = True
        self.assertTrue(validate_static_evidence(payload, root=None))

    def test_each_runtime_accessibility_and_recovery_control_is_enforced(self) -> None:
        for scenario in SCENARIO_TASKS:
            payload = runtime_evidence()
            payload["scenarios"][scenario]["captures"]["mobile"]["unlabelled_fields"] = 1
            with self.subTest(scenario=scenario, control="labels"):
                self.assertTrue(validate_runtime_evidence(payload))
            payload = runtime_evidence()
            payload["scenarios"][scenario]["recovery_verified"] = False
            with self.subTest(scenario=scenario, control="recovery"):
                self.assertTrue(validate_runtime_evidence(payload))

    def test_sensitive_or_personal_evidence_is_rejected(self) -> None:
        payload = runtime_evidence()
        payload["customer_email"] = "person@example.test"
        self.assertTrue(validate_runtime_evidence(payload))
        payload = runtime_evidence()
        payload["security"]["contains_pii"] = True
        self.assertTrue(validate_runtime_evidence(payload))


if __name__ == "__main__":
    unittest.main()
