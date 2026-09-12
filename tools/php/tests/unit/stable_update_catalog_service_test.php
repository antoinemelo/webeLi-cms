<?php

declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Infrastructure\Maintenance\StableUpdateCatalogService;
use App\Infrastructure\Maintenance\StableUpdateException;

$h = new TestHarness();
$dir = sys_get_temp_dir() . '/dec-stable-catalog-' . bin2hex(random_bytes(6));
mkdir($dir, 0770, true);

$api = 'https://api.github.com/repos/antoinemelo/webeLi-cms/releases/latest';
$catalogUrl = 'https://github.com/antoinemelo/webeLi-cms/releases/download/dec_v09-e08a/dec-cms-stable.json';
$archive = static fn(string $name): string => 'https://github.com/antoinemelo/webeLi-cms/releases/download/dec_v09-e08a/' . $name;
$sha = str_repeat('a', 64);
$catalog = [
    'schema_version' => 1,
    'application' => 'dec-cms',
    'channel' => 'stable',
    'repository' => 'antoinemelo/webeLi-cms',
    'core' => [
        'type' => 'core',
        'key' => 'core',
        'name' => 'DEC CMS',
        'version' => 'dec_v09-e08a',
        'archive_url' => $archive('core-dec_v09-e08a.zip'),
        'sha256' => $sha,
    ],
    'modules' => [[
        'type' => 'module',
        'key' => 'sale',
        'name' => 'Ventes',
        'version' => '0.2.0',
        'requires_core' => 'dec_v09-e08a',
        'database_keys' => ['sale'],
        'archive_url' => $archive('module-sale-0.2.0.zip'),
        'sha256' => str_repeat('b', 64),
    ]],
];
$responses = [
    $api => json_encode([
        'draft' => false,
        'prerelease' => false,
        'html_url' => 'https://github.com/antoinemelo/webeLi-cms/releases/tag/dec_v09-e08a',
        'assets' => [[
            'name' => 'dec-cms-stable.json',
            'browser_download_url' => $catalogUrl,
        ]],
    ], JSON_THROW_ON_ERROR),
    $catalogUrl => json_encode($catalog, JSON_THROW_ON_ERROR),
];
$http = static function (string $url, int $maxBytes, int $timeout) use (&$responses): string {
    if (!isset($responses[$url])) {
        throw new RuntimeException('Unexpected URL ' . $url);
    }
    return $responses[$url];
};

try {
    $service = new StableUpdateCatalogService([
        'github_api_url' => $api,
        'catalog_asset_name' => 'dec-cms-stable.json',
        'cache_path' => $dir . '/cache.json',
    ], $http);

    $validated = $service->validateCatalog($catalog);
    $h->assertSame('dec_v09-e08a', $validated['core']['version'] ?? null, 'stable core is validated');
    $h->assertSame('sale', $validated['modules'][0]['key'] ?? null, 'module is validated');
    $h->assertTrue((bool) preg_match('/^[a-f0-9]{64}$/', $service->catalogFingerprint($catalog)), 'catalog fingerprint is deterministic');

    $status = $service->status(true);
    $h->assertSame('stable', $status['channel'] ?? null, 'web updater is stable only');
    $h->assertSame(false, $status['core']['update_available'] ?? null, 'installed core equal to stable has no update');
    $h->assertSame(true, $status['modules'][0]['update_allowed'] ?? null, 'module update is allowed when stable core is exact');

    $future = $catalog;
    $future['core']['version'] = 'dec_v09-e09a';
    $future['core']['archive_url'] = 'https://github.com/antoinemelo/webeLi-cms/releases/download/dec_v09-e09a/core-dec_v09-e09a.zip';
    $future['modules'][0]['requires_core'] = 'dec_v09-e09a';
    $responses[$catalogUrl] = json_encode($future, JSON_THROW_ON_ERROR);
    $futureStatus = $service->status(true);
    $h->assertSame(true, $futureStatus['core']['update_available'] ?? null, 'newer stable core is offered');
    $h->assertSame(false, $futureStatus['modules'][0]['update_allowed'] ?? null, 'module is blocked until core update');
    $h->assertSame('Mettez d’abord DEC CMS à jour.', $futureStatus['modules'][0]['blocked_reason'] ?? null, 'blocking reason is explicit');

    $invalidChannel = $catalog;
    $invalidChannel['channel'] = 'dev';
    $h->expectException(
        fn() => $service->validateCatalog($invalidChannel),
        StableUpdateException::class,
        'dev catalog cannot enter stable updater'
    );

    $invalidDependency = $catalog;
    $invalidDependency['modules'][0]['requires_core'] = 'dec_v09-e07a';
    $h->expectException(
        fn() => $service->validateCatalog($invalidDependency),
        StableUpdateException::class,
        'module must target exact stable core'
    );

    $invalidUrl = $catalog;
    $invalidUrl['core']['archive_url'] = 'https://example.test/update.zip';
    $h->expectException(
        fn() => $service->validateCatalog($invalidUrl),
        StableUpdateException::class,
        'component URL must stay in authorized GitHub repository'
    );
} finally {
    test_remove_tree($dir);
}

exit($h->finish('UNIT stable update catalog service'));
