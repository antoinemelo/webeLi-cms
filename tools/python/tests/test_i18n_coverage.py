from __future__ import annotations

import unittest

from tools.python.validation.content.i18n import validate
from tools.python.validation.registry import BY_NAME


class I18nCoverageValidatorTest(unittest.TestCase):
    def test_validator_is_registered(self) -> None:
        self.assertIn("I18N_COVERAGE", BY_NAME)
        self.assertEqual("content", BY_NAME["I18N_COVERAGE"].domain)

    def test_catalogues_and_covered_views_are_valid(self) -> None:
        report = validate("fast")
        self.assertGreater(report.checks, 20)
        self.assertEqual([], [finding for finding in report.findings if finding.severity == "error"])


if __name__ == "__main__":
    unittest.main()
