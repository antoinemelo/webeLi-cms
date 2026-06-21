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

$canAccess = new ReflectionMethod($controller, 'canAccessDocument');
$userGuidePolicy = [
    'any_permission' => ['content.read', 'seo.read'],
    'superadmin_documents' => ['user-guide/README.md'],
];
$installationPolicy = ['superadmin_only' => true];

$h->assertTrue(
    $canAccess->invoke($controller, 'user-guide/README.md', $userGuidePolicy, [], ['content.read'], true),
    'superadmin can read the restricted user guide index'
);
$h->assertTrue(
    !$canAccess->invoke($controller, 'user-guide/README.md', $userGuidePolicy, [], ['content.read'], false),
    'editor cannot read the superadmin-only user guide index'
);
$h->assertTrue(
    $canAccess->invoke($controller, 'installation/README.md', $installationPolicy, [], [], true),
    'superadmin can read a superadmin-only section without a listed permission'
);
$h->assertTrue(
    !$canAccess->invoke($controller, 'installation/README.md', $installationPolicy, [], ['settings.read'], false),
    'ordinary permissions do not bypass a superadmin-only section'
);

$createEditFrontMatter = ['permissions' => ['content.read', 'content.create', 'content.update']];
$seoFrontMatter = ['permissions' => ['seo.read', 'seo.manage']];
$h->assertTrue(
    $canAccess->invoke($controller, 'user-guide/content/create-edit.md', $userGuidePolicy, $createEditFrontMatter, ['content.read'], false),
    'editor can read a content procedure matching one granted permission'
);
$h->assertTrue(
    !$canAccess->invoke($controller, 'user-guide/content/create-edit.md', $userGuidePolicy, $createEditFrontMatter, ['seo.read'], false),
    'SEO-only profile cannot read a content procedure without a matching permission'
);
$h->assertTrue(
    $canAccess->invoke($controller, 'user-guide/seo/seo-workflow.md', $userGuidePolicy, $seoFrontMatter, ['seo.read'], false),
    'SEO profile can read an SEO procedure'
);
$h->assertTrue(
    !$canAccess->invoke($controller, 'user-guide/seo/seo-workflow.md', $userGuidePolicy, $seoFrontMatter, ['content.read'], false),
    'content-only profile cannot read an SEO procedure'
);
$h->assertTrue(
    $canAccess->invoke(
        $controller,
        'user-guide/forms-cookies/cookie-consent.md',
        $userGuidePolicy,
        ['permissions' => ['cookies.read', 'cookies.manage']],
        ['cookies.read'],
        false
    ),
    'a document permission grants access without requiring a duplicated section permission'
);

$documentPermissions = new ReflectionMethod($controller, 'documentPermissions');
$h->assertSame(
    ['forms.read', 'forms.manage'],
    $documentPermissions->invoke($controller, ['permissions' => ['forms.read/manage']]),
    'document permission shorthand is expanded deterministically'
);

$allSuperadminDocumentsAllowed = true;
$markdownFiles = (new ReflectionMethod($controller, 'markdownFiles'))->invoke($controller);
$splitFrontMatter = new ReflectionMethod($controller, 'splitFrontMatter');
foreach ($markdownFiles as $relativePath) {
    $sectionKey = explode('/', $relativePath, 2)[0];
    if (!isset($sections[$sectionKey])) {
        continue;
    }
    [$frontMatter] = $splitFrontMatter->invoke($controller, (string) file_get_contents(base_path('docs/' . $relativePath)));
    if (!$canAccess->invoke($controller, $relativePath, $sections[$sectionKey], $frontMatter, [], true)) {
        $allSuperadminDocumentsAllowed = false;
        break;
    }
}
$h->assertTrue($allSuperadminDocumentsAllowed, 'superadmin can access every indexed Markdown document');

$documentContract = new ReflectionMethod($controller, 'documentContract');
$withResolvedLinks = new ReflectionMethod($controller, 'withResolvedMarkdownLinks');
$allDocuments = [];
$userGuideIndex = null;
$userGuideBody = '';
foreach ($markdownFiles as $relativePath) {
    $sectionKey = explode('/', $relativePath, 2)[0];
    if (!isset($sections[$sectionKey])) {
        continue;
    }
    $absolute = base_path('docs/' . $relativePath);
    [$frontMatter, $body] = $splitFrontMatter->invoke($controller, (string) file_get_contents($absolute));
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
    'every internal Markdown link in the superadmin user guide resolves to an indexed document'
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

$restrictedHtml = $withResolvedLinks->invoke(
    $controller,
    MarkdownRenderer::toHtml('[Document restreint](installation/README.md)'),
    $index,
    $documents
);
$h->assertTrue(
    !str_contains($restrictedHtml, 'data-doc-id='),
    'unavailable target is not exposed by the rendered link'
);

exit($h->finish('UNIT docs access and link resolution'));
