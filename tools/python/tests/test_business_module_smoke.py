from __future__ import annotations

import hashlib
import re
import sqlite3
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
BUSINESS_DB = ROOT / "storage/database/business.sqlite"
BUSINESS_SCHEMA = ROOT / "database/modules/business.sql"
CMS = ROOT / "tools/cms.py"


EXPECTED_TABLES = {
    "business_companies",
    "business_contacts",
    "business_tags",
    "business_tag_links",
    "crm_memos",
    "crm_memo_shares",
    "crm_memo_comments",
    "crm_contact_channels",
    "crm_consents",
    "crm_mailing_lists",
    "crm_mailing_list_members",
    "crm_mailings",
    "crm_mailing_recipients",
    "crm_message_outbox",
    "crm_message_delivery_events",
    "crm_message_templates",
    "crm_messaging_providers",
    "business_product_assets",
    "business_attribute_groups",
    "business_attributes",
    "business_product_attribute_values",
    "business_variant_attribute_values",
    "business_product_completeness_scores",
}

EXPECTED_INDEXES = {
    "idx_business_companies_system_individuals",
    "idx_business_contacts_active_iam_user",
    "idx_business_contacts_company",
    "idx_crm_memos_site_company",
    "idx_crm_memo_shares_memo",
    "idx_crm_consents_channel_status",
    "idx_crm_mailing_lists_site",
    "idx_crm_message_outbox_status",
    "idx_business_product_assets_product",
    "idx_business_attributes_group",
    "idx_business_product_completeness_scores_sellable",
}

EXPECTED_BUSINESS_ROUTES = {
    "GET /admin/api/business/companies",
    "GET /admin/api/business/companies/export.csv",
    "GET /admin/api/business/contacts",
    "GET /admin/api/business/contacts/export.csv",
    "POST /admin/api/business/contacts/import.csv",
    "GET /admin/api/business/memos",
    "POST /admin/api/business/memos/{id}/public-share",
    "GET /admin/api/business/mailing/lists",
    "POST /admin/api/business/mailing/campaigns/{id}/preview-recipients",
    "POST /admin/api/business/messaging/send-test",
    "GET /admin/api/business/pim/products/{id}/assets",
    "POST /admin/api/business/pim/products/{id}/assets",
    "GET /admin/api/business/pim/attribute-groups",
    "GET /admin/api/business/pim/attributes",
    "GET /admin/api/business/pim/sellable-variants",
    "POST /admin/api/business/pim/products/bulk-update",
}


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def php_test_list() -> str:
    return (ROOT / "tools/php/tests/run.php").read_text(encoding="utf-8")


class BusinessModuleSmokeTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.temporary_directory = tempfile.TemporaryDirectory(prefix="dec-business-smoke-")
        cls.fixture_database = Path(cls.temporary_directory.name) / "business.sqlite"
        cls.connection = sqlite3.connect(cls.fixture_database)
        cls.connection.executescript(BUSINESS_SCHEMA.read_text(encoding="utf-8"))

    @classmethod
    def tearDownClass(cls) -> None:
        cls.connection.close()
        cls.temporary_directory.cleanup()

    def names(self, kind: str) -> set[str]:
        rows = self.connection.execute(
            "SELECT name FROM sqlite_master WHERE type = ?",
            (kind,),
        ).fetchall()
        return {str(row[0]) for row in rows}

    def test_business_sqlite_schema_is_seeded(self) -> None:
        self.assertTrue(self.fixture_database.is_file(), "la fixture business.sqlite doit exister")
        self.assertTrue(EXPECTED_TABLES.issubset(self.names("table")))
        self.assertTrue(EXPECTED_INDEXES.issubset(self.names("index")))
        row = self.connection.execute(
            "SELECT name, company_kind, is_system FROM business_companies "
            "WHERE company_kind = 'system_individuals' AND archived_at IS NULL"
        ).fetchone()
        self.assertEqual(("Individus", "system_individuals", 1), row)

    def test_business_migrate_plan_is_non_mutating(self) -> None:
        before = sha256(BUSINESS_DB)
        completed = subprocess.run(
            [sys.executable, str(CMS), "migrate", "--module", "business", "--plan"],
            cwd=ROOT,
            text=True,
            capture_output=True,
            timeout=60,
        )
        self.assertEqual(completed.returncode, 0, completed.stderr or completed.stdout)
        self.assertIn("[business]", completed.stdout)
        self.assertEqual(before, sha256(BUSINESS_DB))

    def test_business_routes_and_contracts_are_registered(self) -> None:
        routes_text = (ROOT / "backend/routes/api.php").read_text(encoding="utf-8")
        provider_text = (ROOT / "backend/src/Modules/Business/BusinessModuleProvider.php").read_text(encoding="utf-8")
        registered = {
            f"{method} {path}"
            for method, path in re.findall(r"\['(GET|POST|PATCH|DELETE)', '([^']+)'", routes_text)
            if path.startswith("/admin/api/business/")
        }
        self.assertTrue(EXPECTED_BUSINESS_ROUTES.issubset(registered))
        for route in EXPECTED_BUSINESS_ROUTES:
            method, path = route.split(" ", 1)
            self.assertIn(f"'{method}', '{path}'", provider_text)
        self.assertIn("business.crm.manage", provider_text)
        self.assertIn("business.mailing.manage", provider_text)
        self.assertIn("business.messaging.admin", provider_text)

    def test_business_php_suites_cover_crm_mailing_messaging_csv(self) -> None:
        run_php = php_test_list()
        for test_name in [
            "unit/business_crm_service_test.php",
            "unit/business_crm_api_controller_test.php",
            "unit/business_csv_service_test.php",
            "unit/business_pim_api_controller_test.php",
            "unit/business_pim_lite_blueprints_test.php",
            "unit/business_mailing_service_test.php",
            "unit/business_messaging_provider_test.php",
        ]:
            self.assertIn(test_name, run_php)


if __name__ == "__main__":
    unittest.main()
