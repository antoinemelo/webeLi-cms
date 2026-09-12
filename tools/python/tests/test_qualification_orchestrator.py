from __future__ import annotations

import unittest
from pathlib import Path
from subprocess import CompletedProcess
from unittest.mock import patch

from tools.python.commands import qualify
from tools.python.qualification.performance_baseline import _measure, _stock_measurement
from tools.python.qualification.run_all import (
    E2E_INPUTS,
    FRONTEND_BUILD_INPUTS,
    ROOT,
    Result,
    _display_path,
    _exit_code,
    _gate_matrix,
    _php_dependencies_check,
    parse_args,
    steps,
)


class QualificationOrchestratorTest(unittest.TestCase):
    @patch("tools.python.qualification.run_all.time.sleep")
    @patch("tools.python.qualification.run_all.shutil.which", return_value="/usr/bin/composer")
    @patch("tools.python.qualification.run_all.subprocess.run")
    def test_php_audit_retries_transient_registry_failure(self, run, _which, _sleep):
        run.side_effect = [
            CompletedProcess([], 0, "valid", ""),
            CompletedProcess([], 1, "", "HTTP/2 502 from Packagist"),
            CompletedProcess([], 0, '{"advisories": [], "abandoned": []}', ""),
        ]

        code, output, error = _php_dependencies_check()

        self.assertEqual(0, code)
        self.assertIn("rétabli à la tentative 2/2", output)
        self.assertEqual("", error)
        self.assertEqual(3, run.call_count)

    @patch("tools.python.qualification.run_all.time.sleep")
    @patch("tools.python.qualification.run_all.shutil.which", return_value="/usr/bin/composer")
    @patch("tools.python.qualification.run_all.subprocess.run")
    def test_php_audit_reports_registry_failure_without_claiming_an_advisory(self, run, _which, _sleep):
        run.side_effect = [
            CompletedProcess([], 0, "valid", ""),
            CompletedProcess([], 1, "", "HTTP/2 502 from Packagist"),
            CompletedProcess([], 1, "", "HTTP/2 503 from Packagist"),
        ]

        code, output, error = _php_dependencies_check()

        self.assertEqual(1, code)
        self.assertIn("HTTP/2 502", output)
        self.assertIn("HTTP/2 503", output)
        self.assertIn("indisponible après 2 tentatives", error)
        self.assertNotIn("détecté", error)

    @patch("tools.python.qualification.run_all.time.sleep")
    @patch("tools.python.qualification.run_all.shutil.which", return_value="/usr/bin/composer")
    @patch("tools.python.qualification.run_all.subprocess.run")
    def test_php_audit_does_not_retry_a_structured_security_finding(self, run, _which, _sleep):
        run.side_effect = [
            CompletedProcess([], 0, "valid", ""),
            CompletedProcess([], 1, '{"advisories": {"vendor/package": [{"advisoryId": "CVE-test"}]}, "abandoned": []}', ""),
        ]

        code, _output, error = _php_dependencies_check()

        self.assertEqual(1, code)
        self.assertIn("advisory", error)
        self.assertEqual(2, run.call_count)

    def test_performance_baseline_rejects_client_errors(self):
        result = _measure("invalid-contract", 2000.0, 1, lambda: (422, 1.0, "validation failed"))
        self.assertEqual("failed", result["status"])

    def test_performance_baseline_uses_median_and_reports_outliers(self):
        durations = iter([100.0, 2100.0, 120.0])
        result = _measure("one-spike", 2000.0, 3, lambda: (200, next(durations), "ok"))
        self.assertEqual("passed", result["status"])
        self.assertEqual(120.0, result["median_ms"])
        self.assertEqual(2100.0, result["p95_ms"])
        self.assertEqual(1, result["outlier_count"])
        self.assertEqual("median_ms", result["threshold_statistic"])

        slow_durations = iter([2100.0, 2200.0, 100.0])
        slow = _measure("typically-slow", 2000.0, 3, lambda: (200, next(slow_durations), "slow"))
        self.assertEqual("failed", slow["status"])
        self.assertEqual(2100.0, slow["median_ms"])

    def test_stock_baseline_does_not_count_checkout_twice(self):
        status, duration, detail = _stock_measurement(
            (201, 1900.0, "order_id=1"),
            (200, 4.5, "reservations=1"),
        )
        self.assertEqual(200, status)
        self.assertEqual(4.5, duration)
        self.assertIn("reservations=1", detail)
        self.assertIn("setup_checkout_ms=1900.00", detail)

        failed = _stock_measurement((422, 42.0, "checkout failed"), (200, 3.0, "reservations=0"))
        self.assertEqual((422, 42.0, "checkout failed"), failed)

    def test_profiles_have_expected_depth_without_database_rebuild(self):
        registry = steps()
        profiles = ("quick", "complete", "release")
        by_profile = {
            profile: {step.id for step in registry if profile in step.profiles}
            for profile in profiles
        }

        self.assertIn("validate-fast", by_profile["quick"])
        self.assertIn("tests", by_profile["complete"])
        self.assertIn("backup-restore", by_profile["complete"])
        self.assertNotIn("package", by_profile["complete"])
        self.assertNotIn("fresh-install", by_profile["complete"])

        self.assertIn("browser-e2e", by_profile["release"])
        self.assertIn("payment-provider-gate", by_profile["complete"])
        self.assertIn("payment-provider-gate", by_profile["release"])
        self.assertIn("inventory-ledger-gate", by_profile["complete"])
        self.assertIn("inventory-ledger-gate", by_profile["release"])
        self.assertIn("bundle-stock-strategy-gate", by_profile["complete"])
        self.assertIn("bundle-stock-strategy-gate", by_profile["release"])
        self.assertIn("admin-convergence-gate", by_profile["complete"])
        self.assertIn("admin-convergence-gate", by_profile["release"])
        self.assertIn("shop-operational-gate", by_profile["complete"])
        self.assertIn("shop-operational-gate", by_profile["release"])
        self.assertIn("php-dependencies", by_profile["release"])
        self.assertIn("performance-baseline", by_profile["release"])
        self.assertIn("preflight", by_profile["release"])
        self.assertIn("package", by_profile["release"])
        self.assertIn("fresh-install", by_profile["release"])

        for profile, step_ids in by_profile.items():
            self.assertNotIn("rebuild", step_ids, profile)

        for step in registry:
            self.assertNotIn("rebuild", step.command, step.id)

    def test_only_three_profiles_are_registered(self):
        registered = {
            profile
            for step in steps()
            for profile in step.profiles
        }
        self.assertEqual({"quick", "complete", "release"}, registered)

    def test_skipped_is_not_success(self):
        skipped = Result("x", "x", "skipped", 0, [], None, "", "", "missing")
        failed = Result("x", "x", "failed", 0, [], 1, "", "", "")
        passed = Result("x", "x", "passed", 0, [], 0, "", "", "")
        self.assertEqual(2, _exit_code([passed, skipped]))
        self.assertEqual(1, _exit_code([passed, failed]))
        self.assertEqual(0, _exit_code([passed]))

    def test_report_paths_outside_repository_are_printable(self):
        self.assertEqual("storage/report.json", _display_path(ROOT / "storage/report.json"))
        self.assertTrue(_display_path(Path("/tmp/dec-report.json")).endswith("/tmp/dec-report.json"))

    def test_frontend_steps_use_node_entrypoints_not_bin_wrappers(self):
        registry = {step.id: step for step in steps()}
        build = registry["frontend-build"]
        e2e = registry["browser-e2e"]
        self.assertIn(
            "frontend/admin-vue/node_modules/vue-tsc/bin/vue-tsc.js",
            build.files,
        )
        self.assertIn(
            "frontend/admin-vue/node_modules/vite/bin/vite.js",
            build.files,
        )
        self.assertNotIn("frontend/admin-vue/node_modules/.bin/vite", build.files)
        self.assertIn(
            "frontend/admin-vue/node_modules/@playwright/test/cli.js",
            e2e.files,
        )
        self.assertNotIn(
            "frontend/admin-vue/node_modules/.bin/playwright",
            e2e.files,
        )
        self.assertEqual((), e2e.env_vars)
        self.assertIn("e2e", e2e.command)
        self.assertIn("--use-built-assets", e2e.command)
        self.assertEqual(3600, e2e.timeout)
        self.assertIn("tools/python/qualification/performance_baseline.py", registry["performance-baseline"].files)

    def test_release_gate_matrix_distinguishes_source_and_release_controls(self):
        matrix = _gate_matrix()
        requirements = {entry["requirement"]: entry for entry in matrix}

        self.assertIn("baseline performance", requirements)
        self.assertIn("gate E2E omnicanale storefront/POS", requirements)
        self.assertIn("gate M5 interchangeabilité providers", requirements)
        self.assertIn("gate M6 ledger stock Sale", requirements)
        self.assertIn("gate M6.3 bundles et stock composé", requirements)
        self.assertIn("gate UX convergence admin 38e", requirements)
        self.assertIn("gate E2E Shop opérationnel 48", requirements)
        self.assertEqual(["performance-baseline"], requirements["baseline performance"]["source_steps"])
        self.assertEqual(["browser-e2e"], requirements["gate E2E omnicanale storefront/POS"]["source_steps"])
        self.assertEqual(["payment-provider-gate", "browser-e2e"], requirements["gate M5 interchangeabilité providers"]["source_steps"])
        self.assertEqual(["inventory-ledger-gate", "browser-e2e"], requirements["gate M6 ledger stock Sale"]["source_steps"])
        self.assertEqual(["bundle-stock-strategy-gate", "browser-e2e"], requirements["gate M6.3 bundles et stock composé"]["source_steps"])
        self.assertEqual(["admin-convergence-gate", "browser-e2e"], requirements["gate UX convergence admin 38e"]["source_steps"])
        self.assertEqual(
            ["shop-operational-gate", "browser-e2e", "performance-baseline"],
            requirements["gate E2E Shop opérationnel 48"]["source_steps"],
        )
        self.assertIn("tools/cms.py validate", requirements["validation statique"]["release_commands"])
        self.assertIn("tools/cms.py backup --restore", requirements["backup/restore et intégrité SQLite"]["release_commands"])

    def test_qualification_cache_is_explicit_and_forceable(self):
        args = parse_args(["--profile", "release", "--no-cache"])
        self.assertTrue(args.no_cache)
        self.assertIn("frontend/admin-vue/src", FRONTEND_BUILD_INPUTS)
        self.assertIn("frontend/admin-vue/tests/e2e", E2E_INPUTS)
        self.assertIn("backend/src", E2E_INPUTS)
        self.assertIn("tools/python/qualification/omnichannel_gate.py", E2E_INPUTS)
        self.assertIn("tools/python/qualification/payment_provider_gate.py", E2E_INPUTS)
        self.assertIn("tools/python/qualification/inventory_ledger_gate.py", E2E_INPUTS)
        self.assertIn("tools/python/qualification/reservation_availability_gate.py", E2E_INPUTS)
        self.assertIn("tools/python/qualification/admin_convergence_gate.py", E2E_INPUTS)
        self.assertIn("docs/evaluation/machine-readable/admin-convergence-ux-38e.json", E2E_INPUTS)
        self.assertIn("tools/python/qualification/shop_operational_gate.py", E2E_INPUTS)
        self.assertIn("docs/evaluation/machine-readable/shop-operational-release-48.json", E2E_INPUTS)

        class Parser:
            def __init__(self) -> None:
                self.options: list[tuple[tuple[object, ...], dict[str, object]]] = []

            def add_argument(self, *args: object, **kwargs: object) -> None:
                self.options.append((args, kwargs))

        parser = Parser()
        qualify.configure(parser)  # type: ignore[arg-type]
        option_names = {name for args, _kwargs in parser.options for name in args}
        self.assertIn("--no-cache", option_names)


if __name__ == "__main__":
    unittest.main()
