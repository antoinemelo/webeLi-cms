from __future__ import annotations

import unittest

from tools.python.operations.deployment.d4_verify_release_archive import has_forbidden_prefix


class ReleaseArchiveVendorContractTest(unittest.TestCase):
    def test_vendor_is_forbidden_for_source_release(self) -> None:
        self.assertTrue(has_forbidden_prefix("backend/vendor/autoload.php", include_vendor=False))
        self.assertTrue(has_forbidden_prefix("vendor/autoload.php", include_vendor=False))

    def test_vendor_is_allowed_when_manifest_declares_it(self) -> None:
        self.assertFalse(has_forbidden_prefix("backend/vendor/autoload.php", include_vendor=True))
        self.assertFalse(has_forbidden_prefix("vendor/autoload.php", include_vendor=True))

    def test_other_local_paths_remain_forbidden(self) -> None:
        self.assertTrue(has_forbidden_prefix("storage/audit-results/report.json", include_vendor=True))
        self.assertTrue(has_forbidden_prefix("frontend/admin-vue/node_modules/pkg/index.js", include_vendor=True))


if __name__ == "__main__":
    unittest.main()
