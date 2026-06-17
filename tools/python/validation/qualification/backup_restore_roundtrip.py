from __future__ import annotations

import importlib.util
import subprocess
import sys
import tempfile
from pathlib import Path

from tools.python.validation.checks import ROOT
from tools.python.validation.model import ValidationReport

VALIDATOR_ID = "BACKUP_RESTORE_ROUNDTRIP"
DOMAIN = "qualification"
MODES = ("slow",)


def _load_restore_module():
    path = ROOT / "tools/python/operations/backup/d7_restore_sqlite.py"
    spec = importlib.util.spec_from_file_location("amcms_restore_qualification", path)
    if spec is None or spec.loader is None:
        raise RuntimeError("Impossible de charger d7_restore_sqlite.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def validate(mode: str = "slow") -> ValidationReport:
    report = ValidationReport(VALIDATOR_ID, DOMAIN, mode)
    backup_script = ROOT / "tools/python/operations/backup/d6_backup_sqlite.py"
    restore_script = ROOT / "tools/python/operations/backup/d7_restore_sqlite.py"
    for path in (backup_script, restore_script):
        report.checked()
        if not path.is_file():
            report.add("BKP-201", "Script natif de sauvegarde/restauration absent", path=path.relative_to(ROOT).as_posix())
    if report.status == "failed":
        return report
    with tempfile.TemporaryDirectory(prefix="amcms-real-backup-") as tmp:
        archive = Path(tmp) / "backup.zip"
        try:
            result = subprocess.run([sys.executable, str(backup_script), "--output", str(archive)], cwd=ROOT, text=True, capture_output=True, timeout=120)
        except subprocess.TimeoutExpired:
            report.add("BKP-202", "Le script natif de sauvegarde dépasse 120 secondes")
            return report
        report.checked(2)
        if result.returncode != 0:
            report.add("BKP-203", "Le script natif de sauvegarde échoue", details={"stdout": result.stdout[-4000:], "stderr": result.stderr[-4000:]})
            return report
        if not archive.is_file():
            report.add("BKP-204", "Le script natif n’a pas produit l’archive attendue")
            return report
        try:
            restore = _load_restore_module()
            extract = Path(tmp) / "validated"
            extract.mkdir()
            manifest = restore.validate_archive(archive, extract)
            report.checked(2)
            if int(manifest.get("schema_version", 0)) < 2:
                report.add("BKP-205", "Version de manifeste de sauvegarde obsolète", details={"schema_version": manifest.get("schema_version")})
            if not manifest.get("technical_version"):
                report.add("BKP-206", "Version technique absente du manifeste de sauvegarde")
        except Exception as exc:
            report.add("BKP-207", "Le validateur natif de restauration rejette la sauvegarde produite", error=str(exc))
    return report
