<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Modules\Sale\Exceptions\SaleValidationException;
use App\Modules\Sale\Pricing\SalePricingService;

$h = new TestHarness();
$pricing = new SalePricingService();

$plain = $pricing->lineTotals($pricing->lineAmounts([
    'regular_unit_price_minor' => 1000,
    'unit_price_minor' => 1000,
    'tax_rate_basis_points' => 0,
    'tax_included' => true,
]), 2);
$h->assertSame(2000, $plain['line_subtotal_minor'], 'product without tax keeps subtotal');
$h->assertSame(0, $plain['line_tax_minor'], 'product without tax has no tax');
$h->assertSame(2000, $plain['line_total_minor'], 'product without tax keeps total');

$taxIncluded = $pricing->lineTotals($pricing->lineAmounts([
    'regular_unit_price_minor' => 1081,
    'unit_price_minor' => 1081,
    'tax_rate_basis_points' => 810,
    'tax_included' => true,
]), 1);
$h->assertSame(81, $taxIncluded['line_tax_minor'], 'tax included extracts tax from gross price');
$h->assertSame(1000, $taxIncluded['taxable_amount_minor'], 'tax included computes net taxable amount');
$h->assertSame(1081, $taxIncluded['line_total_minor'], 'tax included does not add tax twice');

$taxExcluded = $pricing->lineTotals($pricing->lineAmounts([
    'regular_unit_price_minor' => 1000,
    'unit_price_minor' => 1000,
    'tax_rate_basis_points' => 810,
    'tax_included' => false,
]), 1);
$h->assertSame(81, $taxExcluded['line_tax_minor'], 'tax excluded computes tax from net price');
$h->assertSame(1000, $taxExcluded['taxable_amount_minor'], 'tax excluded keeps net taxable amount');
$h->assertSame(1081, $taxExcluded['line_total_minor'], 'tax excluded adds tax to total');

$catalogDiscount = $pricing->lineTotals($pricing->lineAmounts([
    'regular_unit_price_minor' => 3500,
    'unit_price_minor' => 3000,
    'tax_rate_basis_points' => 0,
    'tax_included' => true,
]), 2);
$h->assertSame(7000, $catalogDiscount['line_subtotal_minor'], 'variant adjusted regular price is used as subtotal');
$h->assertSame(1000, $catalogDiscount['line_discount_minor'], 'catalog amount discount is reflected by final unit price');
$h->assertSame(6000, $catalogDiscount['line_total_minor'], 'catalog discount lowers total');

$amountDiscount = $pricing->lineTotals($pricing->lineAmounts([
    'regular_unit_price_minor' => 1000,
    'unit_price_minor' => 1000,
    'tax_rate_basis_points' => 0,
    'tax_included' => true,
]), 2, [[
    'type' => 'manual_discount',
    'value_minor' => 250,
    'label' => 'Geste commercial',
]]);
$h->assertSame(250, $amountDiscount['line_discount_minor'], 'manual amount discount applies before tax');
$h->assertSame(1750, $amountDiscount['line_total_minor'], 'manual amount discount lowers line total');
$h->assertSame(250, $amountDiscount['adjustments'][0]['amount_minor'] ?? null, 'manual amount discount is exposed as adjustment');

$percentDiscount = $pricing->lineTotals($pricing->lineAmounts([
    'regular_unit_price_minor' => 1000,
    'unit_price_minor' => 1000,
    'tax_rate_basis_points' => 0,
    'tax_included' => true,
]), 2, [[
    'type' => 'manual_discount',
    'value_basis_points' => 1000,
    'label' => '10 percent',
]]);
$h->assertSame(200, $percentDiscount['line_discount_minor'], 'manual percent discount applies before tax');
$h->assertSame(1800, $percentDiscount['line_total_minor'], 'manual percent discount lowers total');

$purchaseIgnored = $pricing->lineTotals($pricing->lineAmounts([
    'regular_unit_price_minor' => 1000,
    'unit_price_minor' => 1000,
    'unit_purchase_price_minor' => 999999,
    'tax_rate_basis_points' => 0,
    'tax_included' => true,
]), 1);
$h->assertSame(1000, $purchaseIgnored['line_total_minor'], 'purchase price is never used in customer totals');

$floored = $pricing->lineTotals($pricing->lineAmounts([
    'regular_unit_price_minor' => 1000,
    'unit_price_minor' => 1000,
    'tax_rate_basis_points' => 0,
    'tax_included' => true,
]), 1, [[
    'type' => 'manual_discount',
    'value_minor' => 5000,
]]);
$h->assertSame(1000, $floored['line_discount_minor'], 'discount cannot exceed line amount');
$h->assertSame(0, $floored['line_total_minor'], 'line total is never negative');

$cartTotals = $pricing->cartTotals([$taxIncluded, $amountDiscount]);
$h->assertSame(3081, $cartTotals['subtotal_minor'], 'cart subtotal sums line subtotals');
$h->assertSame(250, $cartTotals['discount_total_minor'], 'cart discount total sums line discounts');
$h->assertSame(81, $cartTotals['tax_total_minor'], 'cart tax total sums line taxes');
$h->assertSame(0, $cartTotals['shipping_total_minor'], 'shipping is zero in v1');
$h->assertSame(2831, $cartTotals['grand_total_minor'], 'cart grand total is deterministic');

$h->expectException(
    fn() => $pricing->lineAmounts(['regular_unit_price_minor' => 1000, 'unit_price_minor' => -1]),
    SaleValidationException::class,
    'negative unit price is refused'
);

exit($h->finish('UNIT sale pricing service'));
