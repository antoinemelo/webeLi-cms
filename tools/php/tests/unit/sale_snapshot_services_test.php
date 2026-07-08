<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Business\Catalog\CatalogPricingService;
use App\Modules\Business\Repositories\BusinessCatalogPricingRepository;
use App\Modules\Business\Repositories\BusinessCompanyRepository;
use App\Modules\Business\Repositories\BusinessContactRepository;
use App\Modules\Business\Repositories\PosCatalogRepository;
use App\Modules\Business\Services\BusinessCatalogSellableReadService;
use App\Modules\Business\Services\BusinessCrmRelationSnapshotService;
use App\Modules\Business\Services\CatalogVisibilityService;
use App\Modules\Sale\Services\SaleCatalogSnapshotService;
use App\Modules\Sale\Services\SaleCustomerSnapshotService;
use App\Modules\Sale\Services\SaleDatabaseConnection;

$h = new TestHarness();
[$businessDir, $businessPath, $businessDb] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/business.sql');
[$saleDir, $salePath, $saleDb] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/sale.sql');

try {
    $pricingRepository = new BusinessCatalogPricingRepository($businessDb);
    $pricing = new CatalogPricingService($pricingRepository);
    $posCatalog = new PosCatalogRepository($businessDb);
    $sellables = new BusinessCatalogSellableReadService($pricingRepository, $pricing, $posCatalog);
    $saleCatalog = new SaleCatalogSnapshotService(new SaleDatabaseConnection($salePath), $sellables);

    $variantRow = $businessDb->one("SELECT id FROM business_product_variants WHERE sku = 'DEMO-GOURDE-BLEU' LIMIT 1");
    $variantId = (int) ($variantRow['id'] ?? 0);
    $h->assertTrue($variantId > 0, 'demo sellable variant exists');
    $businessDb->run('UPDATE business_product_variants SET barcode = ? WHERE id = ?', ['7610000000100', $variantId]);
    $pricingRepository->createOffer(1, [
        'name' => 'Snapshot unit discount',
        'type' => 'amount',
        'value' => 5,
        'currency' => 'CHF',
        'scope' => 'variant',
        'scope_id' => $variantId,
        'channel' => 'pos',
        'priority' => 1,
    ]);

    $snapshot = $saleCatalog->snapshotForVariant(1, $variantId, 'pos');
    $h->assertSame(1, $snapshot['site_id'], 'sellable snapshot keeps site id');
    $h->assertSame($variantId, $snapshot['business_variant_id'], 'sellable snapshot keeps variant id');
    $h->assertSame('DEMO-GOURDE-BLEU', $snapshot['sku'], 'sellable snapshot keeps SKU');
    $h->assertSame(true, $snapshot['is_sellable'], 'active POS variant is sellable');
    $h->assertSame(2900, $snapshot['regular_unit_price_minor'], 'regular sale price is converted to minor units');
    $h->assertSame(2400, $snapshot['unit_price_minor'], 'active catalog discount is included in sellable price');
    $h->assertSame(1200, $snapshot['unit_purchase_price_minor'], 'admin snapshot can include purchase price');
    $h->assertSame(810, $snapshot['tax_rate_basis_points'], 'tax rate is converted to basis points');
    $h->assertTrue(is_array($snapshot['active_discount_snapshot']), 'active discount snapshot is present');

    $public = $saleCatalog->publicPayload($snapshot);
    $h->assertTrue(!array_key_exists('unit_purchase_price_minor', $public), 'public sellable payload hides purchase price');
    $h->assertSame(2400, $public['unit_price_minor'], 'public sellable payload keeps final sale price');
    $visibility = new CatalogVisibilityService();
    $visiblePayload = $visibility->publicPayload($snapshot);
    $h->assertTrue(!array_key_exists('unit_purchase_price_minor', $visiblePayload), 'catalog visibility hides purchase minor price');

    $cache = $saleDb->one('SELECT * FROM sale_catalog_variant_refs WHERE business_variant_id = ?', [$variantId]);
    $h->assertSame('DEMO-GOURDE-BLEU', $cache['sku'] ?? null, 'sale variant ref cache is written');
    $cachedSnapshot = json_decode((string) ($cache['last_snapshot_json'] ?? '{}'), true);
    $h->assertSame(2400, $cachedSnapshot['unit_price_minor'] ?? null, 'sale variant ref stores snapshot JSON');
    $h->assertSame(1235, $sellables->moneyToMinor('12.35'), 'catalog sellable service converts decimals to minor units');

    $skuSearch = $sellables->searchSellableVariants(1, ['channel' => 'pos', 'sku' => 'DEMO-GOURDE-BLEU']);
    $h->assertSame(1, count($skuSearch['items']), 'sellable search finds by SKU');
    $h->assertSame('physical', $skuSearch['items'][0]['product_type'], 'sellable search keeps physical product type');
    $barcodeSearch = $sellables->searchSellableVariants(1, ['channel' => 'pos', 'barcode' => '7610000000100']);
    $h->assertSame('DEMO-GOURDE-BLEU', $barcodeSearch['items'][0]['sku'] ?? null, 'sellable search finds by barcode');
    $serviceSearch = $sellables->searchSellableVariants(1, ['channel' => 'pos', 'sku' => 'DEMO-VOL-CLASSIC-20']);
    $h->assertSame('service', $serviceSearch['items'][0]['product_type'] ?? null, 'sellable search supports service product type');
    $giftSearch = $sellables->searchSellableVariants(1, ['channel' => 'pos', 'sku' => 'DEMO-GIFT-100']);
    $h->assertSame('gift_card', $giftSearch['items'][0]['product_type'] ?? null, 'sellable search supports gift card product type');
    $sellables->assertVariantSellable(1, $variantId);

    $businessDb->run('UPDATE business_product_variants SET status = "draft" WHERE id = ?', [$variantId]);
    $h->expectException(
        fn() => $saleCatalog->snapshotForVariant(1, $variantId, 'pos'),
        InvalidArgumentException::class,
        'draft variant cannot be required as sellable'
    );

    $companies = new BusinessCompanyRepository($businessDb);
    $contacts = new BusinessContactRepository($businessDb);
    $crmSnapshots = new BusinessCrmRelationSnapshotService($companies, $contacts);
    $saleCustomers = new SaleCustomerSnapshotService(new SaleDatabaseConnection($salePath), $crmSnapshots);

    $noCustomer = $saleCustomers->snapshot(1, null, null);
    $h->assertSame(null, $noCustomer, 'customer snapshot is optional for POS');

    $company = $companies->create(1, [
        'name' => 'Snapshot Client SA',
        'email' => 'client@example.test',
        'phone' => '+41 22 000 00 00',
        'status' => 'client',
        'address' => ['line1' => 'Rue Test 1', 'city' => 'Lausanne', 'country' => 'CH'],
    ], 1);
    $contact = $contacts->create(1, [
        'company_id' => $company['id'],
        'first_name' => 'Alice',
        'last_name' => 'Acheteuse',
        'email' => 'alice@example.test',
        'mobile' => '+41 79 000 00 00',
        'preferred_language' => 'fr',
        'status' => 'client',
    ], 1);

    $customer = $saleCustomers->snapshot(1, (int) $company['id'], (int) $contact['id']);
    $h->assertSame('Alice Acheteuse', $customer['display_name'], 'customer snapshot prefers contact display name');
    $h->assertSame('alice@example.test', $customer['email'], 'customer snapshot prefers contact email');
    $h->assertSame('fr', $customer['language'], 'customer snapshot keeps language');
    $h->assertSame('client', $customer['crm_status'], 'customer snapshot keeps CRM status');
    $h->assertSame('Lausanne', $customer['billing_address']['city'] ?? null, 'customer snapshot includes company address');

    $customerCache = $saleDb->one('SELECT * FROM sale_customer_refs WHERE contact_id = ?', [(int) $contact['id']]);
    $h->assertSame('Alice Acheteuse', $customerCache['display_name'] ?? null, 'sale customer ref cache is written');
    $billing = json_decode((string) ($customerCache['billing_address_json'] ?? '{}'), true);
    $h->assertSame('Lausanne', $billing['city'] ?? null, 'sale customer ref stores billing address JSON');
} finally {
    $businessDb = null;
    $saleDb = null;
    gc_collect_cycles();
    test_remove_tree($businessDir);
    test_remove_tree($saleDir);
}

exit($h->finish('UNIT sale snapshot services'));
