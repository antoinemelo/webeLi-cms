from __future__ import annotations

import unittest

from tools.python.qualification.run_all import Result, _exit_code, steps


class QualificationOrchestratorTest(unittest.TestCase):
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


if __name__ == "__main__":
    unittest.main()
