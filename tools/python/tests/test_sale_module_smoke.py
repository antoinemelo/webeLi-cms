from __future__ import annotations

import json
import re
import sqlite3
import subprocess
import sys
import unittest
from pathlib import Path

from tools.python.lib.database_inventory import database_specs, module_keys

ROOT = Path(__file__).resolve().parents[3]
SALE_DB = ROOT / "storage/database/sale.sqlite"
CMS = ROOT / "tools/cms.py"

EXPECTED_TABLES = {
    "sale_channels",
    "sale_channel_catalog_scopes",
    "sale_settings",
    "sale_customer_refs",
    "sale_catalog_variant_refs",
    "sale_carts",
    "sale_cart_lines",
    "sale_cart_adjustments",
    "sale_orders",
    "sale_order_lines",
    "sale_order_adjustments",
    "sale_order_tax_lines",
    "sale_order_status_history",
    "sale_payment_methods",
    "sale_payment_intents",
    "sale_payment_transactions",
    "sale_payment_allocations",
    "sale_pos_registers",
    "sale_pos_devices",
    "sale_cash_sessions",
    "sale_cash_movements",
    "sale_stock_locations",
    "sale_inventory_items",
    "sale_stock_reservations",
    "sale_stock_movements",
    "sale_receipts",
    "sale_returns",
    "sale_return_lines",
    "sale_refunds",
    "sale_promotions",
    "sale_coupons",
    "sale_idempotency_keys",
    "sale_events",
    "sale_outbox",
}

EXPECTED_PERMISSIONS = {
    "sale.read",
    "sale.manage",
    "sale.orders.read",
    "sale.orders.manage",
    "sale.payments.read",
    "sale.payments.manage",
    "sale.refunds.manage",
    "sale.pos.use",
    "sale.pos.manage",
    "sale.cash.manage",
    "sale.stock.read",
    "sale.stock.manage",
    "sale.reports.read",
    "sale.settings.manage",
}


class SaleModuleSmokeTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.connection = sqlite3.connect(f"file:{SALE_DB}?mode=ro", uri=True)

    @classmethod
    def tearDownClass(cls) -> None:
        cls.connection.close()

    def names(self, kind: str) -> set[str]:
        rows = self.connection.execute(
            "SELECT name FROM sqlite_master WHERE type = ?",
            (kind,),
        ).fetchall()
        return {str(row[0]) for row in rows}

    def test_manifest_declares_sale_as_system_module(self) -> None:
        manifest = json.loads((ROOT / "backend/src/Modules/Sale/module.json").read_text(encoding="utf-8"))
        self.assertEqual("sale", manifest["key"])
        self.assertEqual("Vente", manifest["name"])
        self.assertEqual("system", manifest["type"])
        self.assertTrue(manifest["enabled_by_default"])
        self.assertEqual("App\\Modules\\Sale\\SaleModuleProvider", manifest["provider_class"])
        database = manifest["databases"][0]
        self.assertEqual("storage/database/sale.sqlite", database["path"])
        self.assertEqual("database/modules/sale.sql", database["schema"])

    def test_sale_database_is_discovered_and_seeded(self) -> None:
        self.assertIn("sale", module_keys(root=ROOT))
        sale_specs = [spec for spec in database_specs(root=ROOT) if spec.key == "sale"]
        self.assertEqual(1, len(sale_specs))
        self.assertEqual("sale", sale_specs[0].module_key)
        self.assertTrue(SALE_DB.is_file(), "sale.sqlite doit exister après rebuild")
        self.assertTrue(EXPECTED_TABLES.issubset(self.names("table")))
        rows = self.connection.execute(
            "SELECT code, channel_type, status, is_public FROM sale_channels ORDER BY code"
        ).fetchall()
        self.assertEqual(
            [
                ("admin-manual", "admin", "active", 0),
                ("pos-main", "pos", "draft", 0),
                ("web-main", "ecommerce", "draft", 0),
            ],
            rows,
        )

    def test_provider_declares_permissions_admin_and_optional_public_ecommerce_routes(self) -> None:
        provider = (ROOT / "backend/src/Modules/Sale/SaleModuleProvider.php").read_text(encoding="utf-8")
        for permission in EXPECTED_PERMISSIONS:
            self.assertIn(permission, provider)
        for route in [
            "GET', '/admin/api/sale/schema'",
            "GET', '/admin/api/sale/orders'",
            "GET', '/admin/api/sale/pos/registers'",
            "GET', '/admin/api/sale/stock'",
            "GET', '/admin/api/sale/settings'",
        ]:
            self.assertIn(route, provider)
        for route in [
            "GET', '/api/v1/sale/channels/{code}/bootstrap'",
            "POST', '/api/v1/sale/channels/{code}/cart'",
            "POST', '/api/v1/sale/channels/{code}/checkout'",
        ]:
            self.assertIn(route, provider)
        self.assertNotIn("/api/v1/sale/orders", provider)
        self.assertNotIn("/api/v1/modules/sale", provider)

    def test_migrate_plan_lists_sale_without_mutating(self) -> None:
        before = SALE_DB.read_bytes()
        completed = subprocess.run(
            [sys.executable, str(CMS), "migrate", "--module", "sale", "--plan"],
            cwd=ROOT,
            text=True,
            capture_output=True,
            timeout=60,
        )
        self.assertEqual(completed.returncode, 0, completed.stderr or completed.stdout)
        self.assertIn("[sale]", completed.stdout)
        self.assertEqual(before, SALE_DB.read_bytes())

    def test_sale_sql_uses_integer_minor_amounts(self) -> None:
        sql = (ROOT / "database/modules/sale.sql").read_text(encoding="utf-8")
        self.assertNotRegex(sql, re.compile(r"\bREAL\b", re.IGNORECASE))
        self.assertIn("amount_minor INTEGER", sql)
        self.assertIn("grand_total_minor INTEGER", sql)


if __name__ == "__main__":
    unittest.main()
