from __future__ import annotations

import unittest
from argparse import _SubParsersAction

from tools.python.cms import cli


class CliSmokeTests(unittest.TestCase):
    def test_release_smoke_command_is_registered(self) -> None:
        parser = cli.parser()
        subparsers = next(
            action for action in parser._actions if isinstance(action, _SubParsersAction)
        )
        self.assertIn("smoke", subparsers.choices)
        self.assertIn("validate", subparsers.choices)
        self.assertIn("docs", subparsers.choices)
        self.assertIn("test", subparsers.choices)

    def test_global_options_are_normalized_after_subcommand(self) -> None:
        normalized = cli._normalize_global_options(["smoke", "--json", "--root", "/tmp/cms"])
        self.assertEqual(normalized[:3], ["--json", "--root", "/tmp/cms"])
        self.assertEqual(normalized[3:], ["smoke"])


if __name__ == "__main__":
    unittest.main()
