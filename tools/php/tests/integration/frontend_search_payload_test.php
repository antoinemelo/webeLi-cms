<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "[SKIP] pdo_sqlite unavailable\n";
    exit(0);
}

use App\Application\Content\Read\EditorialContentReadRepository;
use App\Application\Content\Read\PublicContentReadRepository;
use App\Application\Frontend\ResolvePublicRoute;
use App\Application\Frontend\SiteReadRepository;
use App\Application\Frontend\TaxonomyReadRepository;
use App\Application\Frontend\FrontendPageController;
use App\Application\Routing\PublicRouteReadRepository;
use App\Application\Search\PublicSearchReadRepository;
use App\Application\PublicApi\PublicSearchApiHandler;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repository\SiteRepository;

final class FrontendSearchTestController extends FrontendPageController
{
    public function handle(): Response
    {
        return $this->handlePublicRequest();
    }
}

final class FrontendSearchTestPublicContent implements PublicContentReadRepository
{
    public function listPublishedByType(int $siteId, string $typeKey, string $languageCode): array { return []; }
    public function listPublishedArticlesIndex(int $siteId, string $languageCode, int $limit = 6, int $offset = 0, ?string $categorySlug = null, ?string $tagSlug = null): array { return ['items' => [], 'total' => 0]; }
    public function listPublishedHeadless(int $siteId, string $languageCode, array $filters = []): array { return ['items' => [], 'total' => 0]; }
    public function searchPublishedHeadless(int $siteId, string $languageCode, string $query, array $filters = []): array { return ['items' => [], 'total' => 0]; }
    public function listPublicMedia(int $siteId, string $languageCode, int $limit = 24, int $offset = 0, string $type = ''): array { return ['items' => [], 'total' => 0]; }
    public function getPublishedById(int $entryId, string $languageCode): ?array { return null; }
    public function getPublishedByPath(int $siteId, string $path, string $languageCode): ?array { return null; }
    public function getPublishedByTypeAndSlug(int $siteId, string $typeKey, string $slug, string $languageCode): ?array { return null; }
}

final class FrontendSearchTestSearch implements PublicSearchReadRepository
{
    /** @var list<array{query:string,language:string,site_id:int,filters:array<string,mixed>}> */
    public array $calls = [];

    public function searchDocuments(string $query, string $languageCode, int $siteId, array $filters = []): array
    {
        $this->calls[] = ['query' => $query, 'language' => $languageCode, 'site_id' => $siteId, 'filters' => $filters];
        return [[
            'type_key' => 'page',
            'title' => 'Accueil public',
            'summary' => 'Page d’accueil publiée.',
            'search_text' => 'Accueil public Page d’accueil publiée.',
            'path' => '/',
            'taxonomy_labels' => '',
            'search_rank' => 0.1,
        ]];
    }
}

final class FrontendSearchTestRoutes implements PublicRouteReadRepository
{
    public function listPublishedRoutes(int $siteId, string $languageCode): array { return []; }
    public function listPublishedRouteAlternates(int $siteId, string $resourceType, int $resourceId): array { return []; }
    public function findRedirect(int $siteId, string $path, string $languageCode): ?array { return null; }
    public function findTombstone(int $siteId, string $path, string $languageCode): ?array { return null; }
    public function buildPath(string $typeKey, string $slug): string { return '/' . trim($slug, '/'); }
}

final class FrontendSearchTestSites implements SiteReadRepository
{
    public function resolveCurrentSite(string $host = '', string $path = '', ?bool $isHttps = null): array
    {
        return ['id' => 1, 'site_key' => 'main', 'name' => 'Test CMS', 'default_language_code' => 'fr', 'base_url' => 'https://example.test'];
    }
    public function getLocalization(int $siteId, string $languageCode): ?array { return ['site_title' => 'Test CMS']; }
    public function getLanguages(?int $siteId = null): array { return [['code' => 'fr', 'language_code' => 'fr', 'native_name' => 'Français', 'hreflang_code' => 'fr']]; }
    public function defaultLanguageCode(): string { return 'fr'; }
    public function menuItems(int $siteId, string $menuKey, string $languageCode): array { return []; }
}

final class FrontendSearchTestTaxonomies implements TaxonomyReadRepository
{
    public function listTaxonomies(int $siteId): array { return []; }
    public function listTermsForSite(int $siteId, string $languageCode): array { return []; }
    public function listTermsForEntry(int $entryId, string $languageCode): array { return []; }
    public function articleFacets(int $siteId, string $languageCode, int $tagLimit = 12): array { return ['categories' => [], 'tags' => []]; }
    public function findArchiveByPath(int $siteId, string $path, string $languageCode): ?array { return null; }
}

final class FrontendSearchTestEditorialContent implements EditorialContentReadRepository
{
    public function listEntries(int $siteId, ?string $languageCode = null): array { return []; }
    public function listEntriesPage(array $filters): array { return ['items' => [], 'total' => 0]; }
    public function getWorkingEntryAggregate(int $entryId, string $languageCode): ?array { return null; }
    public function previewAggregate(int $entryId, string $languageCode, ?int $revisionId = null, ?int $siteId = null): ?array { return null; }
    public function listContentTypes(): array { return []; }
    public function outboxStats(): array { return []; }
}

$h = new TestHarness();
[$dir, $path] = test_temp_db(__DIR__ . '/../fixtures/frontend_search_http.sql');
$db = null;
try {
    $db = new Database($path, 1000);
    $config = [
        'app' => [
            'name' => 'DEC CMS Test',
            'default_locale' => 'fr',
            'preview_signing_key' => 'test-preview-key',
            'env' => 'testing',
            'debug' => false,
            'base_path' => '',
        ],
        'cms' => ['default_site_key' => 'main', 'preview_ttl_minutes' => 60],
        'themes' => ['default' => ['templates_path' => base_path('frontend/theme-default/templates'), 'assets_url' => '/frontend/theme-default/assets']],
    ];

    $publicContent = new FrontendSearchTestPublicContent();
    $search = new FrontendSearchTestSearch();
    $routes = new FrontendSearchTestRoutes();
    $siteReader = new FrontendSearchTestSites();
    $taxonomies = new FrontendSearchTestTaxonomies();
    $siteRepository = new SiteRepository($db, $config);
    $resolver = new ResolvePublicRoute($publicContent, $search, $routes, $siteReader, $taxonomies, $db);

    $site = $siteReader->resolveCurrentSite('example.test');
    $ssrPayload = $resolver->searchPayload($site, 'fr', 'Accueil', ['q' => 'Accueil']);

    $h->assertSame('Recherche', (string) ($ssrPayload['title'] ?? ''), 'SSR search payload exposes valid public metadata');
    $h->assertSame('Accueil public', (string) ($ssrPayload['search_results'][0]['title'] ?? ''), 'SSR search payload contains normalized search result');
    $h->assertSame('Accueil', (string) ($search->calls[0]['query'] ?? ''), 'SSR search delegates to PublicSearchReadRepository::searchDocuments');

    $request = new Request(
        'GET',
        '/search',
        ['q' => 'Accueil', '_format' => 'json'],
        [],
        ['HTTP_HOST' => 'example.test', 'HTTPS' => 'on', 'HTTP_ACCEPT' => 'application/json'],
        [],
        []
    );
    $controller = new FrontendSearchTestController($config, $request, new FrontendSearchTestEditorialContent(), $routes, $siteRepository, $resolver);
    $response = $controller->handle();
    $payload = json_decode($response->body(), true);

    $h->assertSame(200, $response->status(), 'anonymous GET /search?q=Accueil returns HTTP 200');
    $h->assertTrue(is_array($payload), 'anonymous search route can respond in JSON mode without runtime exception');

    $apiResponse = (new PublicSearchApiHandler($request, $search, $siteRepository))->index();
    $apiPayload = json_decode($apiResponse->body(), true);
    $h->assertSame(200, $apiResponse->status(), 'API /api/v1/search remains callable for the same query');
    $h->assertSame(
        (string) ($apiPayload['data'][0]['title'] ?? ''),
        (string) ($ssrPayload['search_results'][0]['title'] ?? ''),
        'SSR search and API search expose the same first result title'
    );
} finally {
    unset($controller, $resolver, $siteRepository, $taxonomies, $siteReader, $routes, $search, $publicContent);
    $db = null;
    gc_collect_cycles();
    test_remove_tree($dir);
}

exit($h->finish('INTEGRATION frontend anonymous search route'));
