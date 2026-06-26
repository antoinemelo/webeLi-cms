from __future__ import annotations

import subprocess

from tools.python.cms.runtime import resolve_php_binary
from tools.python.validation.checks import ROOT
from tools.python.validation.model import ValidationReport

VALIDATOR_ID = "STATIC_EXPORT_DRY_RUN"
DOMAIN = "qualification"
MODES = ("slow",)


def validate(mode: str = "slow") -> ValidationReport:
    report = ValidationReport(VALIDATOR_ID, DOMAIN, mode)
    console = ROOT / "backend" / "bin" / "console"
    report.checked(2)
    try:
        php = resolve_php_binary()
    except (FileNotFoundError, PermissionError) as exc:
        report.add("EXP-201", str(exc))
        return report
    if not console.is_file():
        report.add("EXP-202", "Console backend absente", path="backend/bin/console")
        return report
    try:
        result = subprocess.run(
            [php, str(console), "static:export", "--dry-run"],
            cwd=ROOT, text=True, capture_output=True, timeout=120,
        )
    except subprocess.TimeoutExpired:
        report.add("EXP-203", "Le dry-run de l’export statique dépasse 120 secondes")
        return report
    report.checked()
    if result.returncode != 0:
        report.add("EXP-204", "Le dry-run de l’export statique échoue", details={"stdout": result.stdout[-4000:], "stderr": result.stderr[-4000:]})
    return report
