from __future__ import annotations

import sqlite3

from tools.python.validation.checks import ROOT
from tools.python.validation.model import ValidationReport

VALIDATOR_ID = "PRODUCT_CONTENT_LINKS"
DOMAIN = "database"
MODES = ("fast", "full")


def validate(mode: str = "fast") -> ValidationReport:
    report = ValidationReport(VALIDATOR_ID, DOMAIN, mode)
    core_path = ROOT / "storage/database/core.sqlite"
    business_path = ROOT / "storage/database/business.sqlite"
    report.checked(2)
    if not core_path.is_file() or not business_path.is_file():
        report.add("PIM-001", "Bases core/business absentes pour valider les liaisons CMS-produit")
        return report

    with sqlite3.connect(core_path) as core, sqlite3.connect(business_path) as business:
        core.row_factory = sqlite3.Row
        business.row_factory = sqlite3.Row
        tables = {
            row[0]
            for row in core.execute(
                "SELECT name FROM sqlite_master WHERE type='table' AND name IN ('business_product_content_links','business_product_public_projections')"
            )
        }
        report.checked(2)
        for table in ("business_product_content_links", "business_product_public_projections"):
            if table not in tables:
                report.add("PIM-002", "Table de liaison/projection CMS-produit absente", table=table)
        if len(tables) != 2:
            return report

        links = core.execute("SELECT * FROM business_product_content_links").fetchall()
        for link in links:
            report.checked(3)
            product = business.execute(
                "SELECT id,site_id,status,is_public,archived_at FROM business_products WHERE id=? AND site_id=?",
                (int(link["product_id"]), int(link["site_id"])),
            ).fetchone()
            if product is None:
                report.add("PIM-003", "Lien vers un produit absent ou d'un autre site", link_id=int(link["id"]))
            content = core.execute(
                "SELECT id FROM content_entries WHERE id=? AND site_id=?",
                (int(link["content_entry_id"]), int(link["site_id"])),
            ).fetchone()
            if content is None:
                report.add("PIM-004", "Lien vers un contenu absent ou d'un autre site", link_id=int(link["id"]))
            projection = core.execute(
                "SELECT id FROM business_product_public_projections WHERE link_id=?",
                (int(link["id"]),),
            ).fetchone()
            if link["status"] == "active" and product is not None and projection is None:
                report.add("PIM-005", "Lien actif sans projection publique", link_id=int(link["id"]))

        duplicate = core.execute(
            """
            SELECT site_id,product_id,COALESCE(locale,''),COUNT(*) AS total
            FROM business_product_content_links
            WHERE status='active' AND is_canonical=1
            GROUP BY site_id,product_id,COALESCE(locale,'') HAVING COUNT(*)>1
            """
        ).fetchall()
        report.checked()
        if duplicate:
            report.add("PIM-006", "Plusieurs contenus canoniques pour un même produit et contexte", details={"contexts": [list(row) for row in duplicate]})
    return report
