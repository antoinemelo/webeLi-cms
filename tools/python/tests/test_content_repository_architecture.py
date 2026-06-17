from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
SRC = ROOT / "backend" / "src"

class ContentRepositoryArchitectureTest(unittest.TestCase):
    def test_legacy_facade_is_absent(self):
        self.assertFalse((SRC / "Repository/ContentRepository.php").exists())
        runtime = "\n".join(p.read_text(encoding="utf-8") for p in SRC.rglob("*.php"))
        self.assertNotIn("App\\Repository\\ContentRepository", runtime)

    def test_public_read_is_projection_only(self):
        source = (SRC / "Infrastructure/Persistence/Sql/SqlPublicContentReadRepository.php").read_text(encoding="utf-8")
        for marker in ("public_content_snapshots", "content_entry_publications", "source_published_revision_id", "workflow_status = 'published'"):
            self.assertIn(marker, source)

    def test_ports_do_not_contain_sql(self):
        for relative in (
            "Application/Content/Read/EditorialContentReadRepository.php",
            "Application/Content/Read/PublicContentReadRepository.php",
            "Application/Routing/PublicRouteReadRepository.php",
            "Application/Search/PublicSearchReadRepository.php",
        ):
            source = (SRC / relative).read_text(encoding="utf-8")
            self.assertNotIn("SELECT ", source)
            self.assertNotIn("App\\Core\\Database", source)

if __name__ == "__main__":
    unittest.main()
