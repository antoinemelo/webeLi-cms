from __future__ import annotations

import io
import subprocess
import tempfile
import unittest
from contextlib import redirect_stdout
from pathlib import Path
from unittest.mock import call, patch

from tools import admin
from tools.python.cms.cli import parser


class AdminMenuTest(unittest.TestCase):
    def test_clone_vendor_candidates_are_relative_to_the_instance_root(self) -> None:
        destination = Path("/srv/www/cms/main")

        self.assertEqual(
            admin.clone_vendor_candidates(destination, Path("/srv/www/cms/dev")),
            (
                Path("/srv/www/cms/main/backend/vendor"),
                Path("/srv/www/cms/main/vendor"),
                Path("/srv/www/cms/vendor"),
                Path("/srv/www/cms/vendor"),
                Path("/srv/www/vendor"),
            ),
        )

        direct_instance = Path("/srv/www/eve")
        self.assertEqual(
            admin.clone_vendor_candidates(direct_instance, Path("/srv/www/cms/dev")),
            (
                Path("/srv/www/eve/backend/vendor"),
                Path("/srv/www/eve/vendor"),
                Path("/srv/www/vendor"),
                Path("/srv/www/cms/vendor"),
                Path("/srv/vendor"),
            ),
        )

        nested_instance = Path("/srv/www/cms/edu/edu1")
        self.assertEqual(
            admin.clone_vendor_candidates(nested_instance, Path("/srv/www/cms/dev")),
            (
                Path("/srv/www/cms/edu/edu1/backend/vendor"),
                Path("/srv/www/cms/edu/edu1/vendor"),
                Path("/srv/www/cms/edu/vendor"),
                Path("/srv/www/cms/vendor"),
                Path("/srv/www/cms/vendor"),
            ),
        )

    def test_clone_public_url_uses_the_explicit_target_base_path(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / "dev"
            (source / "ops").mkdir(parents=True)
            (source / "ops/.env").write_text(
                "APP_BASE_PATH=/cms\nAPP_PUBLIC_BASE_URL=https://webe.li/cms\n",
                encoding="utf-8",
            )

            self.assertEqual(
                admin.suggested_clone_public_url(source, "/new/main"),
                "https://webe.li/new/main",
            )
            self.assertEqual(
                admin.suggested_clone_public_url(source, "/cms2"),
                "https://webe.li/cms2",
            )

    def test_declared_actions_are_accepted_by_canonical_cli(self) -> None:
        cli_parser = parser()
        actions = (
            *admin.ACTIONS,
            *admin.QUALIFICATION_ACTIONS.values(),
            *admin.DATABASE_REBUILD_ACTIONS.values(),
        )

        for action in actions:
            with self.subTest(action=action.key):
                arguments = cli_parser.parse_args(list(action.command))
                self.assertIsNotNone(arguments.command)

    def test_database_rebuild_menu_selects_seed_profiles(self) -> None:
        with (
            patch("builtins.input", side_effect=["b"]),
            redirect_stdout(io.StringIO()),
        ):
            action = admin.choose_database_rebuild()

        self.assertIsNotNone(action)
        self.assertEqual(action.command, ("rebuild", "--without-commerce-seed"))

        with (
            patch("builtins.input", side_effect=["c"]),
            redirect_stdout(io.StringIO()),
        ):
            action = admin.choose_database_rebuild()

        self.assertIsNotNone(action)
        self.assertEqual(
            action.command,
            ("rebuild", "--without-commerce-seed", "--without-core-seed"),
        )

    def test_ftp_update_target_accepts_urls_and_uses_a_target_specific_manifest(self) -> None:
        self.assertEqual(
            admin.normalized_ftp_remote_root("ftp://example.test/www/eve"),
            "/www/eve",
        )
        first = admin.ftp_update_manifest_path("ops/client.json", "/www/eve")
        second = admin.ftp_update_manifest_path("ops/client.json", "/www/cms/main")
        self.assertNotEqual(first, second)
        self.assertEqual(first.parent.name, "ftp-manifests")
        self.assertEqual(admin.ftp_update_plan_path(first).name, f"{first.stem}-plan.json")

    def test_default_update_stage_is_refreshed_without_databases_or_vendor(self) -> None:
        completed = subprocess.CompletedProcess(args=[], returncode=0)
        with (
            patch.object(admin.subprocess, "run", return_value=completed) as run,
            redirect_stdout(io.StringIO()),
        ):
            self.assertEqual(admin.refresh_default_update_stage(str(admin.DEFAULT_UPDATE_STAGE)), 0)

        command = run.call_args.args[0]
        self.assertIn("--exclude-databases", command)
        self.assertIn("--no-zip", command)
        self.assertIn("--skip-generated-artifacts-refresh", command)
        self.assertEqual(command[command.index("--stage-dir") + 1], str(admin.DEFAULT_UPDATE_STAGE))

    def test_point_11_ftp_runs_a_dry_run_before_the_differential_upload(self) -> None:
        completed = subprocess.CompletedProcess(args=[], returncode=0)
        with (
            patch("builtins.input", side_effect=[
                "/tmp/release.zip",
                "f",
                "/tmp/client.json",
                "ftp://example.test/www/eve",
                "n",
                "O",
            ]),
            patch.object(admin.subprocess, "run", return_value=completed) as run,
            patch.object(
                admin,
                "read_ftp_update_plan",
                return_value={"delta": {"new": ["backend/src/App.php"], "changed": [], "removed": []}},
            ),
            redirect_stdout(io.StringIO()),
        ):
            self.assertEqual(admin.run_instance_update(), 0)

        self.assertEqual(run.call_count, 2)
        plan_command = run.call_args_list[0].args[0]
        apply_command = run.call_args_list[1].args[0]
        self.assertIn("--dry-run", plan_command)
        self.assertIn("--plan-output", plan_command)
        self.assertNotIn("--dry-run", apply_command)
        self.assertIn("--apply-plan", apply_command)
        self.assertIn("--protect-instance-data", apply_command)
        self.assertIn("--no-delete-removed", apply_command)
        self.assertEqual(apply_command[apply_command.index("--remote-root") + 1], "/www/eve")

    def test_point_11_ftp_stops_after_a_zero_difference_plan(self) -> None:
        completed = subprocess.CompletedProcess(args=[], returncode=0)
        with (
            patch("builtins.input", side_effect=[
                "/tmp/client.json",
                "ftp://example.test/www/eve",
                "n",
            ]),
            patch.object(admin.subprocess, "run", return_value=completed) as run,
            patch.object(
                admin,
                "read_ftp_update_plan",
                return_value={"delta": {"new": [], "changed": [], "removed": []}},
            ),
            redirect_stdout(io.StringIO()) as output,
        ):
            self.assertEqual(admin.run_ftp_instance_update("/tmp/release.zip"), 0)

        self.assertEqual(run.call_count, 1)
        self.assertIn("Aucune différence applicative", output.getvalue())

    def test_git_sync_can_stage_and_commit_dirty_worktree(self) -> None:
        with (
            patch.object(admin, "is_git_repository", return_value=True),
            patch.object(admin, "print_git_status"),
            patch.object(admin, "git_has_changes", return_value=True),
            patch.object(admin, "git_upstream", return_value=None),
            patch.object(admin, "git_current_branch", return_value=None),
            patch.object(admin, "git_command") as git_command,
            patch("builtins.input", side_effect=["o", "Commit depuis admin.py"]),
            redirect_stdout(io.StringIO()) as output,
        ):
            git_command.return_value.returncode = 0
            self.assertEqual(admin.run_git_sync(), 0)

        git_command.assert_has_calls([
            call("add", "-A", capture=False),
            call("commit", "-m", "Commit depuis admin.py", capture=False),
        ])
        self.assertIn("Commit Git créé", output.getvalue())

    def test_git_sync_can_push_commits_to_upstream(self) -> None:
        with (
            patch.object(admin, "is_git_repository", return_value=True),
            patch.object(admin, "print_git_status"),
            patch.object(admin, "git_has_changes", return_value=False),
            patch.object(admin, "git_upstream", return_value="origin/main"),
            patch.object(admin, "git_ahead_count", return_value=2),
            patch.object(admin, "git_command") as git_command,
            patch("builtins.input", return_value="o"),
            redirect_stdout(io.StringIO()) as output,
        ):
            git_command.return_value.returncode = 0
            self.assertEqual(admin.run_git_sync(), 0)

        git_command.assert_called_once_with("push", capture=False)
        self.assertIn("Push Git terminé", output.getvalue())

    def test_git_sync_is_available_from_main_menu(self) -> None:
        with (
            patch.object(admin, "print_intro"),
            patch.object(admin, "print_menu"),
            patch.object(admin, "run_git_sync", return_value=0) as run_git_sync,
            patch.object(admin, "pause_before_menu", return_value=True),
            patch("builtins.input", side_effect=["12", "0"]),
            redirect_stdout(io.StringIO()),
        ):
            self.assertEqual(admin.main(), 0)

        run_git_sync.assert_called_once_with()

    def test_special_action_failure_is_returned_when_quitting(self) -> None:
        with (
            patch.object(admin, "print_intro"),
            patch.object(admin, "print_menu"),
            patch.object(admin, "run_instance_clone", return_value=7),
            patch.object(admin, "pause_before_menu", return_value=True),
            patch("builtins.input", side_effect=["9", "0"]),
            redirect_stdout(io.StringIO()),
        ):
            self.assertEqual(admin.main(), 7)


if __name__ == "__main__":
    unittest.main()
