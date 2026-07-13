from __future__ import annotations

import unittest
from pathlib import Path

from tools.python.commands import qualify
from tools.python.qualification.performance_baseline import _measure
from tools.python.qualification.run_all import (
    E2E_INPUTS,
    FRONTEND_BUILD_INPUTS,
    ROOT,
    Result,
    _display_path,
    _exit_code,
    _gate_matrix,
    parse_args,
    steps,
)


class QualificationOrchestratorTest(unittest.TestCase):
    def test_performance_baseline_rejects_client_errors(self):
        result = _measure("invalid-contract", 2000.0, 1, lambda: (422, 1.0, "validation failed"))
        self.assertEqual("failed", result["status"])

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
        self.assertIn("tools/python/qualification/performance_baseline.py", registry["performance-baseline"].files)

    def test_release_gate_matrix_distinguishes_source_and_release_controls(self):
        matrix = _gate_matrix()
        requirements = {entry["requirement"]: entry for entry in matrix}

        self.assertIn("baseline performance", requirements)
        self.assertIn("gate E2E omnicanale storefront/POS", requirements)
        self.assertEqual(["performance-baseline"], requirements["baseline performance"]["source_steps"])
        self.assertEqual(["browser-e2e"], requirements["gate E2E omnicanale storefront/POS"]["source_steps"])
        self.assertIn("tools/cms.py validate", requirements["validation statique"]["release_commands"])
        self.assertIn("tools/cms.py backup --restore", requirements["backup/restore et intégrité SQLite"]["release_commands"])

    def test_qualification_cache_is_explicit_and_forceable(self):
        args = parse_args(["--profile", "release", "--no-cache"])
        self.assertTrue(args.no_cache)
        self.assertIn("frontend/admin-vue/src", FRONTEND_BUILD_INPUTS)
        self.assertIn("frontend/admin-vue/tests/e2e", E2E_INPUTS)
        self.assertIn("backend/src", E2E_INPUTS)
        self.assertIn("tools/python/qualification/omnichannel_gate.py", E2E_INPUTS)

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
