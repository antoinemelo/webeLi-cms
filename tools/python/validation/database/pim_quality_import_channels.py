from __future__ import annotations

import sqlite3

from tools.python.validation.checks import ROOT
from tools.python.validation.model import ValidationReport

VALIDATOR_ID = "PIM_QUALITY_IMPORT_CHANNELS"
DOMAIN = "database"
MODES = ("fast", "full")


def validate(mode: str = "fast") -> ValidationReport:
    report = ValidationReport(VALIDATOR_ID, DOMAIN, mode)
    path = ROOT / "storage/database/business.sqlite"
    report.checked()
    if not path.is_file():
        report.add("PIMQ-001", "Base Business absente pour valider la qualité PIM")
        return report

    required = {
        "business_products": {"site_id", "external_id"},
        "business_product_completeness_rules": {"product_type", "severity", "required_language"},
        "business_product_channel_visibility": {"site_id", "product_id", "channel", "status", "starts_at", "ends_at"},
        "business_catalog_import_runs": {"site_id", "idempotency_key", "format_version", "checksum", "summary_json"},
    }
    with sqlite3.connect(path) as db:
        db.row_factory = sqlite3.Row
        for table, columns in required.items():
            present = {str(row[1]) for row in db.execute(f"PRAGMA table_info({table})")}
            report.checked(len(columns) + 1)
            if not present:
                report.add("PIMQ-002", "Table PIM qualité absente", table=table)
                continue
            missing = sorted(columns - present)
            if missing:
                report.add("PIMQ-003", "Colonnes PIM qualité absentes", table=table, details={"columns": missing})

        if any(not _table_exists(db, table) for table in required):
            return report

        invalid_visibility = db.execute(
            """
            SELECT cv.id
            FROM business_product_channel_visibility cv
            LEFT JOIN business_products p ON p.id=cv.product_id
            WHERE p.id IS NULL OR p.site_id<>cv.site_id
               OR (cv.starts_at IS NOT NULL AND cv.ends_at IS NOT NULL AND cv.ends_at<=cv.starts_at)
            """
        ).fetchall()
        report.checked()
        if invalid_visibility:
            report.add("PIMQ-004", "Visibilité canal inter-site ou période invalide", details={"ids": [int(row[0]) for row in invalid_visibility]})

        invalid_runs = db.execute(
            "SELECT id FROM business_catalog_import_runs WHERE json_valid(summary_json)=0 OR trim(format_version)='' OR trim(checksum)=''"
        ).fetchall()
        report.checked()
        if invalid_runs:
            report.add("PIMQ-005", "Journal d'import invalide", details={"ids": [int(row[0]) for row in invalid_runs]})
    return report


def _table_exists(db: sqlite3.Connection, table: str) -> bool:
    return db.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?", (table,)).fetchone() is not None
