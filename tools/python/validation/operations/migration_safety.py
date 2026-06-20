from __future__ import annotations

import hashlib
import subprocess
import sys
from pathlib import Path

from tools.python.validation.model import ValidationReport

VALIDATOR_ID = "MIGRATION_SAFETY"
DOMAIN = "operations"
MODES = ("fast", "full")
ROOT = Path(__file__).resolve().parents[4]


def _sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def _run(*args: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        [sys.executable, str(ROOT / "tools/cms.py"), *args],
        cwd=ROOT,
        text=True,
        capture_output=True,
        timeout=90,
    )


def validate(mode: str = "fast") -> ValidationReport:
    report = ValidationReport(VALIDATOR_ID, DOMAIN, mode)
    dbs = sorted((ROOT / "storage/database").glob("*.sqlite"))
    report.checked()
    if not dbs:
        report.add("MIG-001", "Aucune base SQLite à contrôler pour migrate --plan", severity="warning", path="storage/database")
        return report

    before = {path.relative_to(ROOT).as_posix(): _sha256(path) for path in dbs}
    result = _run("migrate", "--plan")
    report.checked(2)
    if result.returncode != 0:
        report.add("MIG-002", "migrate --plan échoue", details={"stdout": result.stdout[-1000:], "stderr": result.stderr[-1000:]})
        return report
    after = {path.relative_to(ROOT).as_posix(): _sha256(path) for path in dbs}
    if before != after:
        report.add("MIG-003", "migrate --plan modifie au moins un fichier SQLite", details={"before": before, "after": after})

    refused = _run("migrate", "--apply", "--yes")
    report.checked()
    if refused.returncode == 0:
        report.add("MIG-004", "migrate --apply doit refuser sans --backup ou option de risque explicite")
    elif "--backup" not in (refused.stdout + refused.stderr):
        report.add("MIG-005", "Le refus de migrate --apply n'explique pas l'option --backup attendue", details={"stdout": refused.stdout[-1000:], "stderr": refused.stderr[-1000:]})
    return report
