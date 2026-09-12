<?php

declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Infrastructure\Maintenance\StableUpdateCatalogService;
use App\Infrastructure\Maintenance\StableUpdateException;
use App\Infrastructure\Maintenance\StableUpdateService;

$h = new TestHarness();
$catalogs = new StableUpdateCatalogService(['enabled' => false]);
$service = new StableUpdateService($catalogs);
$hash = str_repeat('a', 64);
$meta = static fn(): array => ['sha256' => $hash, 'size' => 1];

$coreComponent = ['type' => 'core', 'key' => 'core', 'version' => 'dec_v09-e09a'];
$coreManifest = [
    'schema_version' => 1,
    'application' => 'dec-cms',
    'channel' => 'stable',
    'component' => $coreComponent,
    'files' => [
        'index.php' => $meta(),
        'backend/public/index.php' => $meta(),
        'backend/bootstrap/runtime.php' => $meta(),
        'backend/bin/console' => $meta(),
        'backend/src/Core/App.php' => $meta(),
        'config/release.json' => $meta(),
        'database/migrations/core/0099_test.sql' => $meta(),
        'database/migrations/iam/0099_test.sql' => $meta(),
    ],
];
$validatedCore = $service->validateComponentManifest($coreManifest, $coreComponent);
$h->assertSame('core', $validatedCore['component']['key'] ?? null, 'core component manifest is accepted');

$coreOwnsModule = $coreManifest;
$coreOwnsModule['files']['backend/src/Modules/Sale/module.json'] = $meta();
$h->expectException(
    fn() => $service->validateComponentManifest($coreOwnsModule, $coreComponent),
    StableUpdateException::class,
    'core package cannot overwrite module code'
);

$coreOwnsData = $coreManifest;
$coreOwnsData['files']['storage/database/core.sqlite'] = $meta();
$h->expectException(
    fn() => $service->validateComponentManifest($coreOwnsData, $coreComponent),
    StableUpdateException::class,
    'core package cannot ship instance databases'
);

$moduleComponent = [
    'type' => 'module',
    'key' => 'sale',
    'version' => '0.2.0',
    'requires_core' => 'dec_v09-e09a',
    'database_keys' => ['sale'],
];
$moduleManifest = [
    'schema_version' => 1,
    'application' => 'dec-cms',
    'channel' => 'stable',
    'component' => $moduleComponent,
    'owned_prefixes' => [
        'backend/src/Modules/Sale',
        'database/migrations/sale',
        'database/modules/sale.sql',
    ],
    'shared_prefixes' => ['admin-app'],
    'files' => [
        'backend/src/Modules/Sale/module.json' => $meta(),
        'database/migrations/sale/0099_test.sql' => $meta(),
        'database/modules/sale.sql' => $meta(),
        'admin-app/index.html' => $meta(),
    ],
];
$validatedModule = $service->validateComponentManifest($moduleManifest, $moduleComponent);
$h->assertSame('sale', $validatedModule['component']['key'] ?? null, 'module owns its code, migrations and shared admin build');

$moduleOwnsSecret = $moduleManifest;
$moduleOwnsSecret['owned_prefixes'][] = 'ops';
$moduleOwnsSecret['files']['ops/.env'] = $meta();
$h->expectException(
    fn() => $service->validateComponentManifest($moduleOwnsSecret, $moduleComponent),
    StableUpdateException::class,
    'module package cannot overwrite secrets even when claiming the prefix'
);

$moduleOwnsCore = $moduleManifest;
$moduleOwnsCore['owned_prefixes'][] = 'backend/src/Core';
$moduleOwnsCore['files']['backend/src/Core/App.php'] = $meta();
$h->expectException(
    fn() => $service->validateComponentManifest($moduleOwnsCore, $moduleComponent),
    StableUpdateException::class,
    'module package cannot claim a core path'
);

$wrongCore = $moduleComponent;
$wrongCore['requires_core'] = 'dec_v09-e08a';
$h->expectException(
    fn() => $service->validateComponentManifest($moduleManifest, $wrongCore),
    StableUpdateException::class,
    'archive core requirement must match catalog'
);

$dir = sys_get_temp_dir() . '/dec-stable-apply-' . bin2hex(random_bytes(6));
try {
    mkdir($dir . '/package/module-sale/backend/src/Modules/Sale', 0770, true);
    mkdir($dir . '/target/storage/deployments', 0770, true);
    mkdir($dir . '/target/backend/src/Modules/Sale', 0770, true);
    mkdir($dir . '/target/backend/src/Core', 0770, true);
    file_put_contents($dir . '/target/backend/src/Modules/Sale/obsolete.php', "<?php\n// obsolete\n");
    file_put_contents($dir . '/target/backend/src/Core/keep.php', "<?php\n// unrelated core\n");
    file_put_contents(
        $dir . '/target/storage/deployments/release-manifest.json',
        json_encode(['files' => [
            'backend/src/Modules/Sale/obsolete.php' => [],
            'backend/src/Core/keep.php' => [],
        ]], JSON_THROW_ON_ERROR)
    );
    $newFile = "<?php\nreturn 'stable-module';\n";
    file_put_contents($dir . '/package/module-sale/backend/src/Modules/Sale/new.php', $newFile);
    $applyComponent = [
        'type' => 'module',
        'key' => 'sale',
        'name' => 'Ventes',
        'version' => '0.2.0',
        'requires_core' => 'dec_v09-e08a',
        'database_keys' => ['sale'],
    ];
    $applyManifest = [
        'schema_version' => 1,
        'application' => 'dec-cms',
        'channel' => 'stable',
        'component' => $applyComponent,
        'owned_prefixes' => ['backend/src/Modules/Sale'],
        'shared_prefixes' => [],
        'files' => [
            'backend/src/Modules/Sale/new.php' => [
                'sha256' => hash('sha256', $newFile),
                'size' => strlen($newFile),
            ],
        ],
    ];
    file_put_contents(
        $dir . '/package/module-sale/component-manifest.json',
        json_encode($applyManifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
    );
    $archivePath = $dir . '/module-sale-0.2.0.zip';
    $phar = new PharData($archivePath, 0, null, Phar::ZIP);
    $phar->buildFromDirectory($dir . '/package');
    $phar = null;
    $archiveBytes = (string) file_get_contents($archivePath);
    $archiveUrl = 'https://github.com/antoinemelo/webeLi-cms/releases/download/dec_v09-e08a/module-sale-0.2.0.zip';
    $catalogUrl = 'https://github.com/antoinemelo/webeLi-cms/releases/download/dec_v09-e08a/dec-cms-stable.json';
    $apiUrl = 'https://api.github.com/repos/antoinemelo/webeLi-cms/releases/latest';
    $applyCatalog = [
        'schema_version' => 1,
        'application' => 'dec-cms',
        'channel' => 'stable',
        'repository' => 'antoinemelo/webeLi-cms',
        'core' => [
            'type' => 'core', 'key' => 'core', 'name' => 'DEC CMS', 'version' => 'dec_v09-e08a',
            'archive_url' => 'https://github.com/antoinemelo/webeLi-cms/releases/download/dec_v09-e08a/core-dec_v09-e08a.zip',
            'sha256' => str_repeat('c', 64),
        ],
        'modules' => [[
            ...$applyComponent,
            'archive_url' => $archiveUrl,
            'sha256' => hash('sha256', $archiveBytes),
        ]],
    ];
    $responses = [
        $apiUrl => json_encode([
            'draft' => false,
            'prerelease' => false,
            'html_url' => 'https://github.com/antoinemelo/webeLi-cms/releases/tag/dec_v09-e08a',
            'assets' => [['name' => 'dec-cms-stable.json', 'browser_download_url' => $catalogUrl]],
        ], JSON_THROW_ON_ERROR),
        $catalogUrl => json_encode($applyCatalog, JSON_THROW_ON_ERROR),
    ];
    $catalogHttp = static fn(string $url, int $max, int $timeout): string => $responses[$url] ?? throw new RuntimeException('Unexpected catalog URL');
    $applyCatalogs = new StableUpdateCatalogService([
        'github_api_url' => $apiUrl,
        'catalog_asset_name' => 'dec-cms-stable.json',
        'cache_path' => $dir . '/catalog-cache.json',
    ], $catalogHttp);
    $applyService = new StableUpdateService(
        $applyCatalogs,
        ['root_path' => $dir . '/target'],
        static fn(string $url, int $max, int $timeout): string => $url === $archiveUrl ? $archiveBytes : throw new RuntimeException('Unexpected archive URL'),
        static fn(array $command, string $cwd, string $logs): array => ['exit_code' => 0, 'stdout' => 'Module sync OK', 'stderr' => ''],
    );
    $applyStatus = $applyCatalogs->status(true);
    $result = $applyService->apply(
        'module',
        'sale',
        '0.2.0',
        (string) $applyStatus['catalog_fingerprint']
    );
    $h->assertSame('0.2.0', $result['version'] ?? null, 'verified stable module package is applied');
    $h->assertSame($newFile, file_get_contents($dir . '/target/backend/src/Modules/Sale/new.php'), 'managed module file is copied');
    $h->assertTrue(!is_file($dir . '/target/backend/src/Modules/Sale/obsolete.php'), 'legacy release inventory retires stale module files');
    $h->assertTrue(is_file($dir . '/target/backend/src/Core/keep.php'), 'legacy release inventory cannot retire another component files');
    $h->assertTrue(!is_file($dir . '/target/storage/maintenance.flag'), 'maintenance flag is removed after success');
    $h->assertTrue(is_file($dir . '/target/storage/updates/installed/module-sale.json'), 'installed component manifest is recorded');
} finally {
    test_remove_tree($dir);
}

exit($h->finish('UNIT stable update service'));
