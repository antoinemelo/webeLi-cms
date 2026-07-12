from __future__ import annotations

import sqlite3

from tools.python.validation.checks import ROOT
from tools.python.validation.model import ValidationReport

VALIDATOR_ID = "PRICING_OFFERS_BUNDLES"
DOMAIN = "database"
MODES = ("fast", "full")


def validate(mode: str = "fast") -> ValidationReport:
    report = ValidationReport(VALIDATOR_ID, DOMAIN, mode)
    path = ROOT / "storage/database/business.sqlite"
    report.checked()
    if not path.is_file():
        report.add("PRC-001", "Base Business absente pour valider pricing, offres et bundles")
        return report

    with sqlite3.connect(path) as db:
        db.row_factory = sqlite3.Row
        required = {
            "business_price_lists": {"site_id", "currency", "channel", "customer_segment", "priority", "status"},
            "business_price_list_items": {"price_list_id", "product_id", "variant_id", "adjustment_type", "adjustment_value"},
            "business_catalog_discounts": {"customer_segment", "currency", "channel", "priority"},
            "business_product_bundles": {"composition_type", "unavailable_strategy", "stock_mode"},
            "business_product_relations": {"product_id", "related_product_id", "relation_type"},
            "business_gift_card_policies": {"product_id", "currency", "value_mode"},
        }
        for table, columns in required.items():
            present = {str(row[1]) for row in db.execute(f"PRAGMA table_info({table})")}
            report.checked(len(columns) + 1)
            if not present:
                report.add("PRC-002", "Table commerciale absente", table=table)
                continue
            missing = sorted(columns - present)
            if missing:
                report.add("PRC-003", "Colonnes commerciales absentes", table=table, details={"columns": missing})

        if any(not _table_exists(db, table) for table in required):
            return report

        cross_site_items = db.execute(
            """
            SELECT pli.id
            FROM business_price_list_items pli
            JOIN business_price_lists pl ON pl.id=pli.price_list_id
            JOIN business_products p ON p.id=pli.product_id
            WHERE pl.site_id<>p.site_id
            """
        ).fetchall()
        report.checked()
        if cross_site_items:
            report.add("PRC-004", "Éléments de liste de prix inter-sites", details={"ids": [int(row[0]) for row in cross_site_items]})

        invalid_gifts = db.execute(
            """
            SELECT gp.id
            FROM business_gift_card_policies gp
            JOIN business_products p ON p.id=gp.product_id
            WHERE gp.site_id<>p.site_id OR p.type<>'gift_card'
            """
        ).fetchall()
        report.checked()
        if invalid_gifts:
            report.add("PRC-005", "Politique de bon cadeau liée à un produit incompatible", details={"ids": [int(row[0]) for row in invalid_gifts]})

        invalid_relations = db.execute(
            """
            SELECT r.id
            FROM business_product_relations r
            JOIN business_products source ON source.id=r.product_id
            JOIN business_products target ON target.id=r.related_product_id
            WHERE r.site_id<>source.site_id OR r.site_id<>target.site_id OR r.product_id=r.related_product_id
            """
        ).fetchall()
        report.checked()
        if invalid_relations:
            report.add("PRC-006", "Relation catalogue inter-site ou réflexive", details={"ids": [int(row[0]) for row in invalid_relations]})

        edges: dict[int, set[int]] = {}
        for row in db.execute(
            """
            SELECT b.bundle_product_id, c.component_product_id
            FROM business_product_bundles b
            JOIN business_bundle_components c ON c.bundle_id=b.id AND c.archived_at IS NULL
            WHERE b.is_active=1 AND b.archived_at IS NULL
            """
        ):
            edges.setdefault(int(row[0]), set()).add(int(row[1]))
        report.checked()
        cycle = _cycle(edges)
        if cycle:
            report.add("PRC-007", "Cycle détecté dans les compositions de bundles", details={"products": cycle})
    return report


def _table_exists(db: sqlite3.Connection, table: str) -> bool:
    return db.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?", (table,)).fetchone() is not None


def _cycle(edges: dict[int, set[int]]) -> list[int]:
    visited: set[int] = set()
    active: list[int] = []

    def visit(node: int) -> list[int]:
        if node in active:
            start = active.index(node)
            return active[start:] + [node]
        if node in visited:
            return []
        active.append(node)
        for child in edges.get(node, set()):
            found = visit(child)
            if found:
                return found
        active.pop()
        visited.add(node)
        return []

    for node in edges:
        found = visit(node)
        if found:
            return found
    return []
