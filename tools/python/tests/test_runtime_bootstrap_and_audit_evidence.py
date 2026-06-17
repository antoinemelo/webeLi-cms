from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[3]
RUNTIME = ROOT / "backend" / "bootstrap" / "runtime.php"
REGISTRY = ROOT / "backend" / "src" / "Module" / "ModuleRegistry.php"


class RuntimeBootstrapAndAuditEvidenceTest(unittest.TestCase):
    def setUp(self) -> None:
        self.runtime = RUNTIME.read_text(encoding="utf-8")
        self.registry = REGISTRY.read_text(encoding="utf-8")

    def test_native_providers_are_not_preloaded_before_composer(self) -> None:
        self.assertNotIn("cms_preload_native_module_providers", self.runtime)
        self.assertNotIn("/config/modules.php", self.runtime)

    def test_composer_is_the_canonical_app_autoloader(self) -> None:
        composer = self.runtime.index("$backendComposerAutoload = dirname(__DIR__) . '/vendor/autoload.php';")
        fallback = self.runtime.index("if (!$projectAutoloadAvailable)")
        self.assertLess(composer, fallback)

    def test_runtime_uses_only_backend_vendor_as_composer_source(self) -> None:
        self.assertIn("dirname(__DIR__) . '/vendor/autoload.php'", self.runtime)
        self.assertNotIn("dirname(__DIR__, 2) . '/vendor/autoload.php'", self.runtime)
        self.assertNotIn("cms_choose_composer_autoload", self.runtime)

    def test_registry_deduplicates_config_and_database_provider_classes(self) -> None:
        self.assertIn("array_merge(", self.registry)
        self.assertIn("$this->providerClassesFromConfig()", self.registry)
        self.assertIn("$this->providerClassesFromDatabase()", self.registry)
        self.assertIn("$class = $this->normalizeProviderClass($class);", self.registry)
        self.assertIn("normalizeProviderClass", self.registry)
        self.assertIn("$normalized[strtolower($class)] = $class;", self.registry)
        self.assertEqual(self.registry.count("$this->registerProviderClass($class);"), 1)

    def test_sql_provider_fqcns_use_single_sqlite_backslashes(self) -> None:
        for relative in (
            "database/migrations/core/071_backfill_forms_module_blueprint_storage.sql",
            "database/migrations/core/073_backfill_ai_module_blueprint_storage.sql",
        ):
            sql = (ROOT / relative).read_text(encoding="utf-8")
            self.assertNotIn("App\\\\Modules", sql, relative)
            self.assertIn("App\\Modules", sql, relative)

    def test_native_app_fallback_keeps_loaded_symbol_guard(self) -> None:
        self.assertIn("class_exists($class, false)", self.runtime)
        self.assertIn("interface_exists($class, false)", self.runtime)
        self.assertIn("trait_exists($class, false)", self.runtime)


if __name__ == "__main__":
    unittest.main()
