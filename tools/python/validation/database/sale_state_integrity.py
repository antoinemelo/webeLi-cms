from __future__ import annotations

import sqlite3

from tools.python.validation.checks import ROOT
from tools.python.validation.model import ValidationReport

VALIDATOR_ID = "SALE_STATE_INTEGRITY"
DOMAIN = "database"
MODES = ("fast", "full")


def validate(mode: str = "fast") -> ValidationReport:
    report = ValidationReport(VALIDATOR_ID, DOMAIN, mode)
    path = ROOT / "storage/database/sale.sqlite"
    report.checked()
    if not path.is_file():
        report.add("SALST-001", "Base Sale absente pour diagnostiquer les états")
        return report

    required = {
        "sale_orders": {"source_cart_id", "correlation_id", "version", "shipping_method_snapshot_json"},
        "sale_carts": {"version", "shipping_method_snapshot_json"},
        "sale_fulfillments": {"order_id", "status", "correlation_id", "version"},
        "sale_state_transitions": {"aggregate_type", "aggregate_id", "from_status", "to_status", "correlation_id"},
        "sale_events": {"correlation_id"},
    }
    with sqlite3.connect(path) as db:
        db.row_factory = sqlite3.Row
        for table, columns in required.items():
            present = {str(row[1]) for row in db.execute(f"PRAGMA table_info({table})")}
            report.checked(len(columns) + 1)
            if not present:
                report.add("SALST-002", "Table de machine à états absente", table=table)
                continue
            missing = sorted(columns - present)
            if missing:
                report.add("SALST-003", "Colonnes de machine à états absentes", table=table, details={"columns": missing})
        if any(not _table_exists(db, table) for table in required):
            return report

        checks = [
            (
                "SALST-004",
                "Panier converti sans commande source cohérente",
                """SELECT c.id FROM sale_carts c LEFT JOIN sale_orders o ON o.id=c.converted_order_id
                   WHERE c.status='converted' AND (o.id IS NULL OR o.source_cart_id<>c.id)""",
            ),
            (
                "SALST-005",
                "Statut de paiement incohérent avec les totaux",
                """SELECT id FROM sale_orders
                   WHERE (payment_status='paid' AND paid_total_minor<grand_total_minor)
                      OR (payment_status='unpaid' AND paid_total_minor<>0)
                      OR (payment_status='refunded' AND refunded_total_minor<paid_total_minor)
                      OR (payment_status='partially_refunded' AND (refunded_total_minor<=0 OR refunded_total_minor>=paid_total_minor))""",
            ),
            (
                "SALST-006",
                "Horodatage terminal incohérent",
                """SELECT id FROM sale_orders
                   WHERE (status='completed' AND completed_at IS NULL)
                      OR (status='cancelled' AND cancelled_at IS NULL)
                      OR (status NOT IN ('completed','cancelled') AND (completed_at IS NOT NULL OR cancelled_at IS NOT NULL))""",
            ),
            (
                "SALST-007",
                "Fulfillment incohérent avec la commande",
                """SELECT f.id FROM sale_fulfillments f LEFT JOIN sale_orders o ON o.id=f.order_id
                   WHERE o.id IS NULL OR o.status='cancelled'
                      OR (f.status='delivered' AND f.delivered_at IS NULL)
                      OR (f.status='shipped' AND f.shipped_at IS NULL)""",
            ),
            (
                "SALST-008",
                "Dernière transition différente de l'état courant",
                """SELECT t.id FROM sale_state_transitions t
                   WHERE t.id=(SELECT MAX(x.id) FROM sale_state_transitions x WHERE x.aggregate_type=t.aggregate_type AND x.aggregate_id=t.aggregate_id)
                     AND t.to_status<>CASE t.aggregate_type
                       WHEN 'cart' THEN (SELECT status FROM sale_carts WHERE id=t.aggregate_id)
                       WHEN 'order' THEN (SELECT status FROM sale_orders WHERE id=t.aggregate_id)
                       WHEN 'payment_intent' THEN (SELECT status FROM sale_payment_intents WHERE id=t.aggregate_id)
                       WHEN 'fulfillment' THEN (SELECT status FROM sale_fulfillments WHERE id=t.aggregate_id)
                       WHEN 'return' THEN (SELECT status FROM sale_returns WHERE id=t.aggregate_id)
                       WHEN 'refund' THEN (SELECT status FROM sale_refunds WHERE id=t.aggregate_id)
                     END""",
            ),
        ]
        for code, message, query in checks:
            rows = db.execute(query).fetchall()
            report.checked()
            if rows:
                report.add(code, message, details={"ids": [int(row[0]) for row in rows]})
    return report


def _table_exists(db: sqlite3.Connection, table: str) -> bool:
    return db.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?", (table,)).fetchone() is not None
