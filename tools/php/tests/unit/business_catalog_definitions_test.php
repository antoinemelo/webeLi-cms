<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Business\Catalog\BusinessCatalogDefinitions;
use App\Modules\Business\Catalog\BusinessCatalogDemoData;
use App\Modules\Business\Catalog\BusinessCatalogValidator;

$h = new TestHarness();
$validator = new BusinessCatalogValidator();

$definitions = BusinessCatalogDefinitions::all();
$h->assertTrue(in_array('physical', $definitions['product_types'], true), 'physical product type is declared');
$h->assertTrue(in_array('service', $definitions['product_types'], true), 'service product type is declared');
$h->assertTrue(in_array('gift_card', $definitions['product_types'], true), 'gift card product type is declared');
$h->assertTrue(in_array('bundle', $definitions['product_types'], true), 'bundle product type is declared');
$h->assertTrue(in_array('ecommerce', $definitions['channels'], true), 'ecommerce channel is declared');
$h->assertTrue(in_array('pos', $definitions['channels'], true), 'pos channel is declared');
$h->assertTrue(in_array('purchase', $definitions['price_kinds'], true), 'purchase price kind is declared');
$h->assertTrue(in_array('sale', $definitions['price_kinds'], true), 'sale price kind is declared');
$h->assertTrue(in_array('fixed_override', $definitions['price_adjustment_types'], true), 'fixed override price adjustment is declared');
$h->assertTrue(in_array('percent', $definitions['offer_types'], true), 'percent offer type is declared');
$h->assertTrue(!BusinessCatalogDefinitions::isPublicChannel('internal'), 'internal channel is not public');

$demo = BusinessCatalogDemoData::minimal();
$product = $validator->product($demo['product']);
$h->assertSame('service', $product['type'], 'demo product is a service');
$h->assertSame(['public', 'ecommerce', 'pos', 'internal'], $product['channels'], 'demo product exposes expected channels');

$option = $validator->option($demo['options'][0]);
$optionValue = $validator->optionValue($demo['options'][0]['values'][0]);
$h->assertSame('model', $option['option_key'], 'option key is normalized');
$h->assertSame('classic', $optionValue['value_key'], 'option value key is normalized');

$variant = $validator->variant($demo['variants'][1]);
$h->assertSame('DEMO-VOL-PREMIUM-40', $variant['sku'], 'variant sku keeps readable uppercase code');
$h->assertSame('percent_delta', $variant['purchase_adjustment_type'], 'variant purchase adjustment type is validated');
$h->assertSame('amount_delta', $variant['sale_adjustment_type'], 'variant sale adjustment type is validated');

$h->assertSame(96.0, $validator->effectivePrice(80.0, 'percent_delta', 20.0), 'purchase percent adjustment is calculated');
$h->assertSame(209.0, $validator->effectivePrice(149.0, 'amount_delta', 60.0), 'sale amount adjustment is calculated');
$h->assertSame(188.1, $validator->discountedSalePrice(209.0, 'percent', 10.0), 'sale discount is calculated on sale price');
$h->assertSame(0.0, $validator->discountedSalePrice(20.0, 'amount', 50.0), 'sale discount never produces negative price');

$publicPayload = $validator->publicProductPayload([
    'name' => 'Public',
    'base_purchase_price' => 10.0,
    'base_sale_price' => 20.0,
    'effective_purchase_price' => 12.0,
    'purchase_adjustment_type' => 'amount_delta',
    'purchase_adjustment_value' => 2.0,
]);
$h->assertTrue(!array_key_exists('base_purchase_price', $publicPayload), 'public payload hides base purchase price');
$h->assertTrue(!array_key_exists('effective_purchase_price', $publicPayload), 'public payload hides effective purchase price');
$h->assertSame(20.0, $publicPayload['base_sale_price'], 'public payload keeps sale price');

$h->expectException(
    fn() => $validator->product(['name' => 'Bad', 'type' => 'subscription']),
    InvalidArgumentException::class,
    'invalid product type is rejected'
);
$h->expectException(
    fn() => $validator->product(['name' => 'No sale price', 'status' => 'active', 'channels' => ['ecommerce']]),
    InvalidArgumentException::class,
    'active ecommerce product requires sale price'
);
$h->expectException(
    fn() => $validator->variant(['sku' => 'BAD', 'sale_adjustment_type' => 'percent_delta', 'sale_adjustment_value' => -120]),
    InvalidArgumentException::class,
    'invalid percent adjustment is rejected'
);
$h->expectException(
    fn() => $validator->offer(['name' => 'Bad offer', 'type' => 'percent', 'value' => 120, 'scope' => 'product']),
    InvalidArgumentException::class,
    'invalid percent offer is rejected'
);

exit($h->finish('UNIT business catalog definitions'));
