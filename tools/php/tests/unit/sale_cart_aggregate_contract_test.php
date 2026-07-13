<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Business\Catalog\CatalogPricingService;
use App\Modules\Business\Repositories\BusinessCatalogPricingRepository;
use App\Modules\Business\Repositories\PosCatalogRepository;
use App\Modules\Business\Services\BusinessCatalogSellableReadService;
use App\Modules\Sale\Adapters\BusinessSellableCatalogAdapter;
use App\Modules\Sale\Exceptions\SaleValidationException;
use App\Modules\Sale\Pricing\SalePricingService;
use App\Modules\Sale\Repositories\SaleCartRepository;
use App\Modules\Sale\Repositories\SaleChannelRepository;
use App\Modules\Sale\Repositories\SaleEventRepository;
use App\Modules\Sale\Repositories\SaleIdempotencyRepository;
use App\Modules\Sale\Repositories\SaleInventoryRepository;
use App\Modules\Sale\Services\SaleCartService;
use App\Modules\Sale\Services\SaleCatalogSnapshotService;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Modules\Sale\Services\SaleEventService;
use App\Modules\Sale\Services\SaleIdempotencyService;
use App\Modules\Sale\Services\SaleInventoryService;

$h = new TestHarness();
[$businessDir, $businessPath, $businessDb] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/business.sql');
[$saleDir, $salePath, $saleDb] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/sale.sql');

try {
    $pricingRepository = new BusinessCatalogPricingRepository($businessDb);
    $sellables = new BusinessCatalogSellableReadService(
        $pricingRepository,
        new CatalogPricingService($pricingRepository),
        new PosCatalogRepository($businessDb)
    );
    $connection = new SaleDatabaseConnection($salePath);
    $channels = new SaleChannelRepository($connection);
    $carts = new SaleCartRepository($connection);
    $events = new SaleEventService(new SaleEventRepository($connection));
    $inventory = new SaleInventoryService(new SaleInventoryRepository($connection), null, null, $events);
    $service = new SaleCartService(
        $carts,
        $channels,
        new SaleCatalogSnapshotService($connection, new BusinessSellableCatalogAdapter($sellables)),
        new SalePricingService(),
        $inventory,
        $events,
        new SaleIdempotencyService(new SaleIdempotencyRepository($connection))
    );

    $webChannel = $saleDb->one("SELECT id FROM sale_channels WHERE code='web-main'");
    $posChannel = $saleDb->one("SELECT id FROM sale_channels WHERE code='pos-main'");
    $adminChannel = $saleDb->one("SELECT id FROM sale_channels WHERE code='admin-manual'");
    $variant = $businessDb->one("SELECT v.id,v.product_id FROM business_product_variants v WHERE v.sku='DEMO-GOURDE-BLEU'");

    $web = $service->createCart(1, (int) $webChannel['id'], ['locale' => 'de']);
    $admin = $service->createCart(1, (int) $adminChannel['id'], ['iam_user_id' => 1]);
    $pos = $service->createCart(1, (int) $posChannel['id'], ['iam_user_id' => 1]);
    $h->assertSame('web', $web['cart_kind'], 'storefront channel creates the shared web cart kind');
    $h->assertSame('de', $web['locale'], 'cart locale belongs to the aggregate context');
    $h->assertTrue($web['expires_at'] !== null, 'web cart receives an expiration date');
    $h->assertSame('admin', $admin['cart_kind'], 'admin channel creates an admin cart');
    $h->assertSame('pos', $pos['cart_kind'], 'POS channel creates a POS cart');
    $h->assertSame(null, $pos['public_token_hash'], 'POS cart never receives a public token');
    $h->expectException(
        fn() => $carts->attachPublicToken((int) $pos['id'], hash('sha256', 'forbidden'), gmdate('Y-m-d H:i:s', time() + 60)),
        SaleValidationException::class,
        'public token cannot be attached to a POS cart'
    );
    $h->expectException(
        fn() => $service->createCart(1, (int) $posChannel['id']),
        SaleValidationException::class,
        'POS cart requires an authenticated operator'
    );

    $version = (int) $web['version'];
    $added = $service->addLine((int) $web['id'], (int) $variant['id'], 1, [
        'expected_version' => $version,
        'options' => ['color' => 'blue'],
        'personalization' => ['engraving' => 'Ada'],
    ]);
    $line = $added['line'];
    $h->assertSame((int) $variant['id'], (int) $line['sellable_id'], 'cart line identifies the exact sellable');
    $h->assertSame('{"color":"blue"}', $line['options_json'], 'validated options are persisted');
    $h->assertSame('{"engraving":"Ada"}', $line['personalization_json'], 'validated personalization is persisted');
    $h->assertSame('shipping', $line['fulfillment_class'], 'line stores its fulfillment class');
    $h->assertSame('available', $line['availability_state'], 'line stores its availability state');
    $h->assertTrue((int) $line['calculation_version'] >= 1, 'line stores its calculation version');

    $afterAdd = $carts->requireCart((int) $web['id']);
    $h->expectException(
        fn() => $service->updateLineQuantity((int) $web['id'], (int) $line['id'], 2, $version),
        SaleValidationException::class,
        'stale cart version rejects concurrent mutation'
    );
    $service->updateLineQuantity((int) $web['id'], (int) $line['id'], 2, (int) $afterAdd['version']);
    $h->assertSame(2, (int) $carts->requireLine((int) $line['id'])['quantity'], 'current cart version accepts mutation');

    $oldPrice = (int) $line['unit_price_minor'];
    $businessDb->run(
        "UPDATE business_product_base_prices SET amount=37 WHERE product_id=? AND price_kind='sale' AND currency='CHF'",
        [(int) $variant['product_id']]
    );
    $current = $carts->requireCart((int) $web['id']);
    $recalculated = $service->recalculate((int) $web['id'], (int) $current['version']);
    $changedLine = $recalculated['lines'][0];
    $h->assertSame($oldPrice, (int) $changedLine['previous_unit_price_minor'], 'price change keeps the previous server price');
    $h->assertSame(3700, (int) $changedLine['unit_price_minor'], 'significant mutation applies the current server price');
    $h->assertTrue($changedLine['price_changed_at'] !== null, 'price change is explicit and timestamped');
    $h->assertSame(7400, (int) $recalculated['grand_total_minor'], 'client totals are ignored and server totals are recalculated');

    $guest = $service->createCart(1, (int) $webChannel['id']);
    $target = $service->createCart(1, (int) $webChannel['id']);
    $service->addLine((int) $guest['id'], (int) $variant['id'], 1);
    $merged = $service->mergeGuestIntoAccount((int) $guest['id'], (int) $target['id'], 42, (int) $target['version']);
    $h->assertSame(42, (int) $merged['customer_ref_id'], 'guest cart merges into the authenticated account cart');
    $h->assertSame('abandoned', $carts->requireCart((int) $guest['id'])['status'], 'merged guest cart cannot be reused');
    $h->assertSame(1, count($merged['lines']), 'merge preserves sellable lines');
    $h->expectException(
        fn() => $service->mergeGuestIntoAccount((int) $target['id'], (int) $admin['id'], 42),
        SaleValidationException::class,
        'web cart cannot merge into another channel policy'
    );
} finally {
    $businessDb = null;
    $saleDb = null;
    gc_collect_cycles();
    test_remove_tree($businessDir);
    test_remove_tree($saleDir);
}

exit($h->finish('UNIT sale cart aggregate contract'));
