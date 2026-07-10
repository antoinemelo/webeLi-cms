<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Application\Api\Admin\DocsApiController;
use App\Core\MarkdownRenderer;

$h = new TestHarness();
$controller = (new ReflectionClass(DocsApiController::class))->newInstanceWithoutConstructor();
$sectionsMethod = new ReflectionMethod($controller, 'sectionPolicy');
$sections = $sectionsMethod->invoke($controller);
(new ReflectionProperty($controller, 'sections'))->setValue($controller, $sections);

$documentationFiles = (new ReflectionMethod($controller, 'documentationFiles'))->invoke($controller);
$splitFrontMatter = new ReflectionMethod($controller, 'splitFrontMatter');
$h->assertTrue(in_array('README.md', $documentationFiles, true), 'root documentation index is exposed');
$h->assertTrue(in_array('installation/README.md', $documentationFiles, true), 'installation documentation is exposed without a superadmin rule');
$h->assertTrue(in_array('evaluation/machine-readable/features.json', $documentationFiles, true), 'machine-readable JSON documentation is exposed');
$h->assertTrue(in_array('public-api/openapi.v1.yaml', $documentationFiles, true), 'OpenAPI YAML documentation is exposed');
$h->assertTrue(in_array('public-api/index.html', $documentationFiles, true), 'HTML documentation source is exposed');
$h->assertTrue(!in_array('public-api/.htaccess', $documentationFiles, true), 'server configuration is not treated as documentation');

$catalog = (new ReflectionMethod($controller, 'documents'))->invoke($controller);
$h->assertSame(count($documentationFiles), count($catalog), 'every supported documentation file is present in the catalogue');
$catalogIds = array_column($catalog, 'id');
$h->assertSame(count($catalogIds), count(array_unique($catalogIds)), 'every documentation file has a unique stable identifier');

$renderSource = new ReflectionMethod($controller, 'renderSourceDocument');
$safeHtmlSource = $renderSource->invoke($controller, '<script>alert(1)</script>', 'html-source');
$h->assertTrue(
    !str_contains($safeHtmlSource, '<script>') && str_contains($safeHtmlSource, '&lt;script&gt;'),
    'non-Markdown documentation is displayed as escaped source'
);

$controllerSource = (string) file_get_contents(base_path('backend/src/Application/Api/Admin/DocsApiController.php'));
foreach (['canAccessDocument', 'documentPermissions', 'superadmin_only', 'superadmin_documents', 'any_permission', 'siteContext'] as $legacyRule) {
    $h->assertTrue(!str_contains($controllerSource, $legacyRule), 'legacy documentation access rule removed: ' . $legacyRule);
}

$documentContract = new ReflectionMethod($controller, 'documentContract');
$withResolvedLinks = new ReflectionMethod($controller, 'withResolvedMarkdownLinks');
$allDocuments = [];
$userGuideIndex = null;
$userGuideBody = '';
foreach ($documentationFiles as $relativePath) {
    $sectionKey = str_contains($relativePath, '/') ? explode('/', $relativePath, 2)[0] : 'overview';
    if (!isset($sections[$sectionKey])) {
        continue;
    }
    $absolute = base_path('docs/' . $relativePath);
    $source = (string) file_get_contents($absolute);
    [$frontMatter, $body] = str_ends_with(strtolower($relativePath), '.md')
        ? $splitFrontMatter->invoke($controller, $source)
        : [[], $source];
    $document = $documentContract->invoke($controller, $relativePath, $sectionKey, $frontMatter, $body, $absolute);
    $allDocuments[] = $document;
    if ($relativePath === 'user-guide/README.md') {
        $userGuideIndex = $document;
        $userGuideBody = $body;
    }
}
$renderedUserGuide = $withResolvedLinks->invoke(
    $controller,
    MarkdownRenderer::toHtml($userGuideBody),
    $userGuideIndex,
    $allDocuments
);
preg_match_all('/\[[^\]]+\]\((?!https?:|mailto:|#)[^)]+\.md(?:#[^)]*)?\)/i', $userGuideBody, $markdownLinks);
$h->assertSame(
    count($markdownLinks[0]),
    substr_count($renderedUserGuide, 'data-doc-id='),
    'every internal Markdown link in the user guide resolves to an indexed document'
);

$documents = [
    [
        'id' => 'user-guide~index',
        'relative_path' => 'user-guide/README.md',
        'source_path' => 'docs/user-guide/README.md',
    ],
    [
        'id' => 'user-guide~content~create-edit',
        'relative_path' => 'user-guide/content/create-edit.md',
        'source_path' => 'docs/user-guide/content/create-edit.md',
    ],
];
$index = $documents[0];
$expectedId = 'user-guide~content~create-edit';

$resolve = new ReflectionMethod($controller, 'resolveRequestedDocument');
foreach ([
    $expectedId,
    '#docs/' . $expectedId,
    'docs/user-guide/content/create-edit.md',
    'content/create-edit.md',
] as $requestToken) {
    $resolved = $resolve->invoke($controller, $requestToken, $documents, 'user-guide~index');
    $h->assertSame($expectedId, $resolved['id'] ?? null, 'viewer token resolves: ' . $requestToken);
}

$html = $withResolvedLinks->invoke(
    $controller,
    MarkdownRenderer::toHtml('[Créer, modifier et prévisualiser](content/create-edit.md)'),
    $index,
    $documents
);
$h->assertTrue(
    str_contains($html, 'data-doc-id="' . $expectedId . '"')
        && str_contains($html, 'data-doc-path="docs/user-guide/content/create-edit.md"'),
    'rendered relative link carries its authorized canonical target'
);

$installationHtml = $withResolvedLinks->invoke(
    $controller,
    MarkdownRenderer::toHtml('[Installation](../installation/README.md)'),
    $index,
    $allDocuments
);
$h->assertTrue(
    str_contains($installationHtml, 'data-doc-id="installation~index"'),
    'cross-section links resolve for every authenticated user'
);

exit($h->finish('UNIT unrestricted docs exposure and link resolution'));
