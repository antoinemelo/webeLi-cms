from __future__ import annotations

import copy
import json
import unittest

from tools.python.qualification.shop_operational_gate import (
    GATE_ID,
    NEGATIVE_CONTROLS,
    PREPARATION,
    SCENARIOS,
    STATIC_REPORT,
    UX_CONTROLS,
    validate_runtime_evidence,
    validate_static_evidence,
)


def static_evidence() -> dict:
    return json.loads(STATIC_REPORT.read_text(encoding="utf-8"))


def runtime_evidence() -> dict:
    return {
        "format_version": 1,
        "gate": GATE_ID,
        "status": "passed",
        "build": {"commit": "a" * 40, "version": "dec_v09-e04a"},
        "preparation": {name: True for name in PREPARATION},
        "scopes": {"site_count": 2, "language_count": 2, "distinct_base_paths": True, "selective_activation": True},
        "scenarios": {name: {"status": "passed", "sha256": "b" * 64} for name in SCENARIOS},
        "negative_controls": {name: {"blocked": True, "evidence_source": f"release:{name}"} for name in NEGATIVE_CONTROLS},
        "ux": {name: True for name in UX_CONTROLS},
        "artifacts": {"orders": "c" * 64, "documents": "d" * 64},
        "security": {"contains_pii": False, "contains_secret": False, "contains_token": False, "contains_gift_code": False, "contains_provider_payload": False},
        "limitations": ["Synthetic unit fixture."],
    }


class ShopOperationalGateTest(unittest.TestCase):
    def test_static_contract_passes(self) -> None:
        self.assertEqual([], validate_static_evidence(static_evidence(), root=None))

    def test_runtime_contract_passes(self) -> None:
        self.assertEqual([], validate_runtime_evidence(runtime_evidence()))

    def test_every_scenario_task_is_mandatory(self) -> None:
        for scenario, tasks in SCENARIOS.items():
            for task in tasks:
                payload = static_evidence()
                payload["scenarios"][scenario]["tasks"].pop(task)
                with self.subTest(scenario=scenario, task=task):
                    self.assertTrue(validate_static_evidence(payload, root=None))

    def test_every_negative_and_ux_control_is_blocking(self) -> None:
        for control in NEGATIVE_CONTROLS:
            payload = runtime_evidence()
            payload["negative_controls"][control]["blocked"] = False
            with self.subTest(control=control):
                self.assertTrue(validate_runtime_evidence(payload))
        for control in UX_CONTROLS:
            payload = runtime_evidence()
            payload["ux"][control] = False
            with self.subTest(control=control):
                self.assertTrue(validate_runtime_evidence(payload))

    def test_scope_migration_and_sensitive_failures_block_release(self) -> None:
        payload = runtime_evidence()
        payload["preparation"]["from_scratch_without_migrations"] = False
        self.assertTrue(validate_runtime_evidence(payload))
        payload = runtime_evidence()
        payload["scopes"]["site_count"] = 1
        self.assertTrue(validate_runtime_evidence(payload))
        payload = runtime_evidence()
        payload["security"]["contains_token"] = True
        self.assertTrue(validate_runtime_evidence(payload))
        payload = runtime_evidence()
        payload["operator_email"] = "person@example.test"
        self.assertTrue(validate_runtime_evidence(payload))


if __name__ == "__main__":
    unittest.main()
