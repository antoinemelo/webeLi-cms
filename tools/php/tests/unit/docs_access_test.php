<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Application\Api\Admin\DocsApiController;
use App\Core\MarkdownRenderer;

$h = new TestHarness();
$controller = (new ReflectionClass(DocsApiController::class))->newInstanceWithoutConstructor();

$canAccess = new ReflectionMethod($controller, 'canAccessDocument');
$userGuidePolicy = [
    'any_permission' => ['content.read', 'seo.read'],
    'superadmin_documents' => ['user-guide/README.md'],
];
$installationPolicy = ['superadmin_only' => true];

$h->assertTrue(
    $canAccess->invoke($controller, 'user-guide/README.md', $userGuidePolicy, ['content.read'], true),
    'superadmin can read the restricted user guide index'
);
$h->assertTrue(
    !$canAccess->invoke($controller, 'user-guide/README.md', $userGuidePolicy, ['content.read'], false),
    'editor cannot read the superadmin-only user guide index'
);
$h->assertTrue(
    $canAccess->invoke($controller, 'installation/README.md', $installationPolicy, [], true),
    'superadmin can read a superadmin-only section without a listed permission'
);
$h->assertTrue(
    !$canAccess->invoke($controller, 'installation/README.md', $installationPolicy, ['settings.read'], false),
    'ordinary permissions do not bypass a superadmin-only section'
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

$withResolvedLinks = new ReflectionMethod($controller, 'withResolvedMarkdownLinks');
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
