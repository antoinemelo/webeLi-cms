from __future__ import annotations

from tools.python.validation.checks import load_json, require_paths, require_tokens
from tools.python.validation.model import ValidationReport

VALIDATOR_ID = "RELEASE_STRUCTURE"
DOMAIN = "operations"
MODES = ("fast", "full")


def validate(mode: str = "fast") -> ValidationReport:
    report = ValidationReport(VALIDATOR_ID, DOMAIN, mode)
    load_json(report, "config/release.json", "REL-003")
    require_paths(
        report,
        [
            "tools/python/operations/deployment/d2_package_release.py",
            "tools/python/operations/deployment/d4_verify_release_archive.py",
            "tools/python/operations/deployment/d5_smoke_test_release_structure.py",
            "tools/python/commands/smoke.py",
            ".github/workflows/release.yml",
        ],
    )
    require_tokens(
        report,
        "tools/python/operations/deployment/d2_package_release.py",
        [
            "tools/python/tests/**",
            "tools/php/tests/**",
            "frontend/admin-vue/tests/**",
            "smoke, validate et docs check",
        ],
        "REL-004",
    )
    require_tokens(
        report,
        "tools/python/operations/deployment/d4_verify_release_archive.py",
        ["RELEASE_COMMANDS", "smoke", "validate", "docs", "check"],
        "REL-005",
    )
    return report
