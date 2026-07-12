from __future__ import annotations

import importlib.util
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

from tools.python.lib.database_inventory import database_specs
from tools.python.lib.deploylib import sha256_file
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
            validated = restore.validate_archive(archive, extract)
            # d7_restore_sqlite.validate_archive historically returned only the
            # manifest. Since module-aware backups it returns (manifest, entries)
            # so callers can restore additional database files safely. Keep this
            # validator compatible with both contracts.
            if isinstance(validated, tuple):
                manifest, entries = validated
            else:
                manifest = validated
                entries = {}
            report.checked(2)
            if int(manifest.get("schema_version", 0)) < 2:
                report.add("BKP-205", "Version de manifeste de sauvegarde obsolète", details={"schema_version": manifest.get("schema_version")})
            if not manifest.get("technical_version"):
                report.add("BKP-206", "Version technique absente du manifeste de sauvegarde")
            _check_expected_inventory(report, manifest)
            _check_extracted_hashes(report, manifest, entries, extract)
            _simulate_restore(report, entries, extract, Path(tmp) / "restored")
        except Exception as exc:
            report.add("BKP-207", "Le validateur natif de restauration rejette la sauvegarde produite", error=str(exc))
    return report


def _check_expected_inventory(report: ValidationReport, manifest: dict) -> None:
    expected = {spec.key for spec in database_specs(root=ROOT, backup=True)}
    actual = set((manifest.get("databases") or {}).keys())
    report.checked()
    missing = sorted(expected - actual)
    if missing:
        report.add("BKP-208", "La sauvegarde ne couvre pas toutes les bases attendues", details={"missing": missing, "expected": sorted(expected)})


def _check_extracted_hashes(report: ValidationReport, manifest: dict, entries: dict[str, dict], extract: Path) -> None:
    databases = manifest.get("databases") or {}
    for key, meta in databases.items():
        report.checked()
        entry = entries.get(key) or {"member": f"database/{meta.get('name', key)}"}
        path = extract / str(entry["member"])
        expected = str(meta.get("sha256", ""))
        actual = sha256_file(path) if path.is_file() else ""
        if not expected or expected != actual:
            report.add("BKP-209", "Hash restauré divergent du manifeste", details={"key": key, "expected": expected, "actual": actual})


def _simulate_restore(report: ValidationReport, entries: dict[str, dict], extract: Path, target: Path) -> None:
    for key, entry in entries.items():
        report.checked()
        destination = target / str(entry["path"])
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(extract / str(entry["member"]), destination)
        try:
            import sqlite3

            with sqlite3.connect(destination) as connection:
                integrity = str(connection.execute("PRAGMA integrity_check").fetchone()[0])
        except Exception as exc:
            report.add("BKP-210", "Base restaurée illisible dans le round-trip simulé", details={"key": key, "error": str(exc)})
            continue
        if integrity != "ok":
            report.add("BKP-211", "Base restaurée non intègre dans le round-trip simulé", details={"key": key, "integrity_check": integrity})
