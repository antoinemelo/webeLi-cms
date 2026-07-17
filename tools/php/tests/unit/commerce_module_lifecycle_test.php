<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Core\Database;
use App\Module\ModuleRegistry;
use App\Modules\Business\BusinessModuleProvider;
use App\Modules\Sale\SaleModuleProvider;

$h = new TestHarness();
[$coreDir, $corePath, $core] = test_temp_cms_db(__DIR__ . '/../../../../database/schema/core.sql');
[$iamDir, $iamPath, $iam] = test_temp_cms_db(__DIR__ . '/../../../../database/iam.sql');

try {
    $config = [
        'modules' => [
            'enabled' => ['business', 'sale'],
            'providers' => [
                BusinessModuleProvider::class,
                SaleModuleProvider::class,
            ],
            'system_manifest_paths' => [],
            'local_modules_config' => $coreDir . '/missing-local-modules.json',
        ],
        'databases' => ['modules_dir' => $coreDir . '/modules'],
    ];
    $registry = new ModuleRegistry($core, $config);
    $registry->syncManifest();
    $h->assertSame(null, $registry->get('commerce'), 'commerce is no longer exposed as an autonomous module');
    $sale = $registry->get('sale');
    $h->assertTrue($sale instanceof SaleModuleProvider, 'sale remains the owner of commercial administration');

    $routes = $sale?->adminRoutes() ?? [];
    $routePaths = array_column($routes, 1);
    $h->assertTrue(in_array('/admin/api/sale/ecommerce/shops', $routePaths, true), 'sale exposes the canonical ecommerce site configuration endpoint');
    $h->assertTrue(in_array('/admin/api/commerce/shops', $routePaths, true), 'the former commerce endpoint remains as a compatibility alias');

    $navigation = $sale?->adminNavigation() ?? [];
    $h->assertTrue(!in_array('/commerce', array_column($navigation, 'route'), true), 'sale does not publish a standalone Commerce menu');

    $permissionKeys = array_column($sale?->permissions() ?? [], 'key');
    $h->assertTrue(in_array('sale.settings.manage', $permissionKeys, true), 'ecommerce configuration uses the existing Sale settings permission');
    $h->assertTrue(!in_array('commerce.manage', $permissionKeys, true), 'no separate Commerce management permission is introduced');
} finally {
    unset($core, $iam);
    test_remove_tree($coreDir);
    test_remove_tree($iamDir);
}

exit($h->finish('sale ecommerce settings ownership'));
