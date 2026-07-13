<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Business\Services\BusinessDatabaseConnection;
use App\Modules\Sale\Exceptions\SaleValidationException;
use App\Modules\Sale\Repositories\SaleChannelRepository;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Modules\Sale\Services\SalesChannelIntegrityService;
use App\Modules\Sale\Services\SalesChannelResolverService;

$h = new TestHarness();
[$coreDir, $corePath, $core] = test_temp_cms_db(__DIR__ . '/../../../../database/schema/core.sql');
[$businessDir, $businessPath, $business] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/business.sql');
[$saleDir, $salePath, $sale] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/sale.sql');

try {
    $core->run("INSERT INTO languages(code,name,locale,is_default) VALUES('fr','Français','fr-CH',1)");
    $core->run("INSERT INTO sites(id,site_key,name,default_language_code) VALUES(1,'main','Main','fr'),(2,'second','Second','fr')");
    $core->run("INSERT INTO site_domains(id,site_id,host,is_primary) VALUES(1,1,'main.test',1),(2,2,'second.test',1)");
    $core->run("INSERT INTO cms_sales_channel_storefronts(channel_id,site_id,domain_id,is_default,status) VALUES(3,1,1,1,'active')");

    $saleConnection = new SaleDatabaseConnection($salePath);
    $channels = new SaleChannelRepository($saleConnection);
    $resolver = new SalesChannelResolverService($channels, $saleConnection, $core);
    $integrity = new SalesChannelIntegrityService($saleConnection, $core, new BusinessDatabaseConnection($businessPath));

    $web = $resolver->storefront(1);
    $h->assertSame(3, $web['channel_id'], 'storefront resolves the configured stable channel id');
    $h->assertSame('storefront', $web['type'], 'legacy ecommerce is exposed as canonical storefront');
    $h->assertSame('fr', $web['default_locale'], 'canonical locale alias is exposed');
    $h->assertSame('CHF', $web['default_currency'], 'canonical currency alias is exposed');
    $h->assertSame(1, $resolver->admin(1)['channel_id'], 'admin resolves its deterministic default');
    $h->expectException(fn() => $resolver->storefront(1, 'admin-manual'), SaleValidationException::class, 'explicit wrong channel kind never silently falls back');

    $sale->run("UPDATE sale_channels SET status='active' WHERE id=2");
    $location = $sale->one("SELECT id FROM sale_stock_locations WHERE site_id=1 AND code='channel-default'");
    $sale->run("INSERT INTO sale_pos_registers(site_id,channel_id,code,name,stock_location_id) VALUES(1,2,'main','Main register',?)", [(int) $location['id']]);
    $pos = $resolver->pos(1);
    $h->assertSame(2, $pos['channel']['channel_id'], 'POS resolves the register channel');
    $h->assertSame((int) $location['id'], $pos['stock_location']['id'], 'POS resolution includes its stock location');
    $sale->run("UPDATE sale_pos_registers SET stock_location_id=NULL WHERE code='main'");
    $h->expectException(fn() => $resolver->pos(1), SaleValidationException::class, 'POS without a stock location is rejected');
    $sale->run("UPDATE sale_pos_registers SET stock_location_id=? WHERE code='main'", [(int) $location['id']]);

    $second = $channels->create(2, ['code' => 'web-second', 'name' => 'Second web', 'type' => 'storefront', 'status' => 'active', 'is_default' => true]);
    $core->run("INSERT INTO cms_sales_channel_storefronts(channel_id,site_id,domain_id,is_default,status) VALUES(?,2,2,1,'active')", [(int) $second['channel_id']]);
    $h->assertSame((int) $second['channel_id'], $resolver->storefront(2)['channel_id'], 'multisite resolution remains isolated by site');
    $h->expectException(fn() => $resolver->storefront(1, 'web-second'), SaleValidationException::class, 'a channel from another site is unknown');

    $clean = $integrity->validate(1);
    $h->assertSame(true, $clean['valid'], 'coordinated local configurations pass integrity validation');
    $business->run("INSERT INTO business_sales_channel_configs(channel_id,site_id,catalog_channel) VALUES(999,1,'admin')");
    $broken = $integrity->validate(1);
    $h->assertSame(false, $broken['valid'], 'unknown cross-database references are detected');
    $h->assertSame('unknown_channel_reference', $broken['issues'][0]['code'], 'integrity issue is machine readable');
} finally {
    $core = $business = $sale = null;
    test_remove_tree($coreDir);
    test_remove_tree($businessDir);
    test_remove_tree($saleDir);
}

exit($h->finish('UNIT SalesChannel transversal contract'));
