from __future__ import annotations

import copy
import json
import unittest

from tools.python.qualification.usability_commerce_gate import (
    EVENTS, GATE_ID, ROLE_TASKS, RUNTIME_REPORT, SCREENS, STATIC_REPORT,
    TELEMETRY_FIELDS, validate_runtime_evidence, validate_static_evidence,
)


def static_evidence() -> dict:
    return json.loads(STATIC_REPORT.read_text(encoding="utf-8"))


def runtime_evidence() -> dict:
    screen = {
        "viewport": "mobile", "unnamed_controls": 0, "unlabelled_fields": 0,
        "contrast_violations": 0, "horizontal_overflow": False, "focus_visible": True,
        "interactive_count": 4, "capture": "capture.png", "capture_sha256": "a" * 64,
    }
    return {
        "format_version": 1, "gate": GATE_ID, "status": "passed",
        "screens": {name: copy.deepcopy(screen) for name in SCREENS},
        "interactions": {role: len(tasks) for role, tasks in ROLE_TASKS.items()},
        "microcopy": {key: True for key in {"availability", "next_action", "recoverable_error", "permission", "test_mode"}},
        "telemetry_samples": [
            {"contract": "commerce.usability.v1", "event": event, "journey": "checkout", "role": "mobile_customer", "outcome": "observed", "duration_bucket": "under_60s", "viewport": "mobile", "language": "fr"}
            for event in EVENTS
        ],
        "security": {"contains_pii": False, "contains_secret": False, "contains_card_data": False, "contains_free_text": False},
    }


class UsabilityCommerceGateTest(unittest.TestCase):
    def test_static_review_passes(self) -> None:
        self.assertEqual([], validate_static_evidence(static_evidence(), root=None))

    def test_runtime_review_passes(self) -> None:
        self.assertEqual([], validate_runtime_evidence(runtime_evidence()))

    def test_every_role_task_is_mandatory(self) -> None:
        for role, tasks in ROLE_TASKS.items():
            for task in tasks:
                payload = static_evidence()
                payload["roles"][role]["tasks"].pop(task)
                with self.subTest(role=role, task=task):
                    self.assertTrue(validate_static_evidence(payload, root=None))

    def test_zero_and_unwaived_one_block_heuristic_gate(self) -> None:
        payload = static_evidence()
        payload["screens"]["checkout_mobile"]["scores"]["recovery"] = 0
        self.assertTrue(validate_static_evidence(payload, root=None))
        payload = static_evidence()
        payload["screens"]["checkout_mobile"]["scores"]["recovery"] = 1
        self.assertTrue(validate_static_evidence(payload, root=None))
        payload["screens"]["checkout_mobile"]["waiver"] = "accepted limitation"
        self.assertEqual([], validate_static_evidence(payload, root=None))

    def test_each_runtime_screen_and_accessibility_metric_is_enforced(self) -> None:
        for screen in SCREENS:
            payload = runtime_evidence()
            payload["screens"][screen]["unlabelled_fields"] = 1
            with self.subTest(screen=screen):
                self.assertTrue(validate_runtime_evidence(payload))

    def test_telemetry_rejects_non_allowlisted_or_sensitive_data(self) -> None:
        payload = runtime_evidence()
        payload["telemetry_samples"][0]["opaque_identifier"] = "42"
        self.assertTrue(validate_runtime_evidence(payload))
        payload = runtime_evidence()
        payload["telemetry_samples"][0]["email"] = "person@example.test"
        self.assertTrue(validate_runtime_evidence(payload))
        self.assertNotIn("email", TELEMETRY_FIELDS)

    def test_open_p0_and_missing_real_user_limitation_fail(self) -> None:
        payload = static_evidence()
        payload["blockers"] = ["critical dead end"]
        self.assertTrue(validate_static_evidence(payload, root=None))
        payload = static_evidence()
        payload["limitations"] = []
        self.assertTrue(validate_static_evidence(payload, root=None))


if __name__ == "__main__":
    unittest.main()
