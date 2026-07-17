from __future__ import annotations

import io
import unittest
from contextlib import redirect_stdout
from unittest.mock import call, patch

from tools import admin
from tools.python.cms.cli import parser


class AdminMenuTest(unittest.TestCase):
    def test_declared_actions_are_accepted_by_canonical_cli(self) -> None:
        cli_parser = parser()
        actions = (*admin.ACTIONS, *admin.QUALIFICATION_ACTIONS.values())

        for action in actions:
            with self.subTest(action=action.key):
                arguments = cli_parser.parse_args(list(action.command))
                self.assertIsNotNone(arguments.command)

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
