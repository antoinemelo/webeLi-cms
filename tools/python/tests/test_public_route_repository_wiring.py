from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
SOURCE = ROOT / "backend/src/Application/Frontend/ResolvePublicRoute.php"


class PublicRouteRepositoryWiringTest(unittest.TestCase):
    def test_route_queries_use_public_route_repository(self) -> None:
        source = SOURCE.read_text(encoding="utf-8")

        self.assertNotIn("$this->content->listPublishedRoutes(", source)
        self.assertNotIn("$this->content->listPublishedRouteAlternates(", source)
        self.assertIn("$this->routes->listPublishedRoutes(", source)
        self.assertIn("$this->routes->listPublishedRouteAlternates(", source)


if __name__ == "__main__":
    unittest.main()
