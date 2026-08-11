from pathlib import Path
import shutil
import subprocess
import tempfile
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

    def test_runtime_can_use_parent_twig_vendor_without_parent_app_autoload(self) -> None:
        backend = self.runtime.index("$projectRoot . '/backend/vendor'")
        local = self.runtime.index("$projectRoot . '/vendor'")
        parent = self.runtime.index("dirname($projectRoot) . '/vendor'")
        named_cms = self.runtime.index("cms_named_shared_vendor_root()", parent)
        grandparent = self.runtime.index("dirname($projectRoot, 2) . '/vendor'")
        self.assertLess(backend, local)
        self.assertLess(local, parent)
        self.assertLess(parent, named_cms)
        self.assertLess(named_cms, grandparent)
        self.assertIn("function cms_vendor_roots(): array", self.runtime)
        self.assertIn("$vendorRoot . '/twig'", self.runtime)
        self.assertIn("cms_autoload_maps_prefix($autoload, 'App\\\\')", self.runtime)
        self.assertIn("cms_require_portable_composer_autoload($autoload)", self.runtime)
        self.assertIn("$loader->setPsr4('App\\\\', []);", self.runtime)
        self.assertNotIn("dirname(__DIR__, 2) . '/vendor/autoload.php'", self.runtime)

    def test_shared_cms_vendor_loads_dependencies_but_not_shared_app_namespace(self) -> None:
        shared_vendor = ROOT.parent / "vendor"
        if not (shared_vendor / "autoload.php").is_file():
            self.skipTest("shared cms/vendor is not installed")

        with tempfile.TemporaryDirectory() as directory:
            web_root = Path(directory) / "www"
            instance = web_root / "eve"
            (web_root / "cms").mkdir(parents=True)
            (web_root / "cms/vendor").symlink_to(shared_vendor, target_is_directory=True)
            (instance / "backend/bootstrap").mkdir(parents=True)
            (instance / "backend/src/Shared/Support").mkdir(parents=True)
            (instance / "ops").mkdir(parents=True)
            (instance / "backend/bootstrap/runtime.php").write_text(
                RUNTIME.read_text(encoding="utf-8"),
                encoding="utf-8",
            )
            (instance / "backend/src/Shared/Support/helpers.php").write_text(
                (ROOT / "backend/src/Shared/Support/helpers.php").read_text(encoding="utf-8"),
                encoding="utf-8",
            )
            (instance / "backend/src/Probe.php").write_text(
                "<?php\nnamespace App;\nfinal class Probe {}\n",
                encoding="utf-8",
            )
            (instance / "ops/.env").write_text(
                "APP_BASE_PATH=/eve\nAPP_TWIG_VENDOR_PATH=./vendor/twig/\n",
                encoding="utf-8",
            )

            process = subprocess.run(
                [
                    "php",
                    "-r",
                    (
                        f'require {str(instance / "backend/bootstrap/runtime.php")!r}; '
                        'echo class_exists("Twig\\\\Environment") ? "twig=yes\\n" : "twig=no\\n"; '
                        'echo class_exists("Stripe\\\\StripeClient") ? "stripe=yes\\n" : "stripe=no\\n"; '
                        'echo class_exists("App\\\\Probe") ? "app=yes\\n" : "app=no\\n"; '
                        'echo cms_named_shared_vendor_root(), "\\n";'
                    ),
                ],
                cwd=ROOT,
                text=True,
                capture_output=True,
            )

            self.assertEqual(process.returncode, 0, process.stderr)
            self.assertIn("twig=yes", process.stdout)
            self.assertIn("stripe=yes", process.stdout)
            self.assertIn("app=yes", process.stdout)
            self.assertIn(str(web_root / "cms/vendor"), process.stdout)

            # A vendor copied directly below backend is canonical: its App\\
            # mapping must remain active while it supplies Twig and Stripe.
            shutil.copytree(shared_vendor, instance / "backend/vendor")
            canonical_process = subprocess.run(
                [
                    "php",
                    "-r",
                    (
                        f'require {str(instance / "backend/bootstrap/runtime.php")!r}; '
                        'echo class_exists("Twig\\\\Environment") ? "twig=yes\\n" : "twig=no\\n"; '
                        'echo class_exists("Stripe\\\\StripeClient") ? "stripe=yes\\n" : "stripe=no\\n"; '
                        'echo class_exists("App\\\\Probe") ? "app=yes\\n" : "app=no\\n";'
                    ),
                ],
                cwd=ROOT,
                text=True,
                capture_output=True,
            )

            self.assertEqual(canonical_process.returncode, 0, canonical_process.stderr)
            self.assertIn("twig=yes", canonical_process.stdout)
            self.assertIn("stripe=yes", canonical_process.stdout)
            self.assertIn("app=yes", canonical_process.stdout)

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
