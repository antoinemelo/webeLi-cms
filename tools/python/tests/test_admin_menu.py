from __future__ import annotations

import io
import unittest
from contextlib import redirect_stdout
from unittest.mock import patch

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

    def test_dirty_worktree_disables_automatic_git_changes(self) -> None:
        with (
            patch.object(admin, "is_git_repository", return_value=True),
            patch.object(admin, "print_git_status"),
            patch.object(admin, "git_has_changes", return_value=True),
            patch.object(admin, "git_command") as git_command,
            redirect_stdout(io.StringIO()) as output,
        ):
            admin.maybe_push_after_ftp()

        git_command.assert_not_called()
        self.assertIn("push automatique désactivé", output.getvalue())
        self.assertIn("admin.py ne crée aucun commit", output.getvalue())

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
