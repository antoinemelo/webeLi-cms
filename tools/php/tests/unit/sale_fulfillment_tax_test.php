<?php
declare(strict_types=1);
require_once __DIR__.'/../TestHarness.php';
require_once __DIR__.'/../../../../backend/bootstrap/runtime.php';

use App\Modules\Sale\Exceptions\SaleValidationException;
use App\Modules\Sale\Pricing\SalePricingService;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Modules\Sale\Services\SaleFulfillmentService;

$h=new TestHarness();
[$dir,$path,$db]=test_temp_cms_db(__DIR__.'/../../../../database/modules/sale.sql');
try {
    $service=new SaleFulfillmentService(new SaleDatabaseConnection($path));
    $address=['line1'=>'Rue 1','postal_code'=>'1000','city'=>'Lausanne','country_code'=>'CH'];
    $physical=[['product_type'=>'physical','line_total_minor'=>5000]];
    $serviceLine=[['product_type'=>'service','line_total_minor'=>5000]];
    $standard=$service->quote(1,$physical,$address,'standard','fr');
    $h->assertSame(900,$standard['amount_minor'],'physical product uses configured fixed rate');
    $h->assertSame(true,$standard['requires_shipping_address'],'shipping method requires an address');
    $free=$service->quote(1,[['product_type'=>'physical','line_total_minor'=>10000]],$address,'standard');
    $h->assertSame(0,$free['amount_minor'],'configured threshold grants free shipping');
    $pickup=$service->quote(1,$physical,[],'pickup');
    $h->assertSame(0,$pickup['amount_minor'],'local pickup does not require shipping address');
    $none=$service->quote(1,$serviceLine,[],'none');
    $h->assertSame('none',$none['type'],'service supports no fulfillment without address');
    $h->expectException(fn()=> $service->quote(1,$physical,[],'none'),SaleValidationException::class,'physical product rejects no fulfillment');
    $h->expectException(fn()=> $service->quote(1,$serviceLine,$address,'standard'),SaleValidationException::class,'service rejects shipping unless explicitly configured');
    $h->expectException(fn()=> $service->quote(1,$physical,['line1'=>'X','postal_code'=>'75000','city'=>'Paris','country_code'=>'FR'],'standard'),SaleValidationException::class,'address outside configured zone is rejected');
    $zone=$service->saveZone(1,['code'=>'fr-test','name'=>'France test','country_codes'=>['FR'],'postal_prefixes'=>['75']]);
    $method=$service->saveMethod(1,['code'=>'fr-standard','label_fr'=>'France','label_en'=>'France','fulfillment_type'=>'shipping','zone_id'=>$zone['id'],'flat_rate_minor'=>1200]);
    $h->assertSame('fr-standard',$method['code'],'administrator can configure a site fulfillment method');
    $h->assertSame(1200,$service->quote(1,$physical,['line1'=>'X','postal_code'=>'75001','city'=>'Paris','country_code'=>'FR'],'fr-standard')['amount_minor'],'configured postal zone is applied');
    $db->run("UPDATE sale_fulfillment_methods SET status='disabled' WHERE code='pickup' AND site_id=1");
    $h->expectException(fn()=> $service->quote(1,$physical,[],'pickup'),SaleValidationException::class,'disabled method is unavailable');

    $pricing=new SalePricingService();
    $included=$pricing->lineTotals(['regular_unit_price_minor'=>1081,'unit_price_minor'=>1081,'tax_rate_basis_points'=>810,'tax_included'=>true,'tax_class_code'=>'standard'],1);
    $h->assertSame(81,$included['line_tax_minor'],'tax included uses deterministic half-up integer rounding');
    $h->assertSame(1000,$included['taxable_amount_minor'],'tax included extracts taxable base deterministically');
    $excluded=$pricing->lineTotals(['regular_unit_price_minor'=>1000,'unit_price_minor'=>1000,'tax_rate_basis_points'=>810,'tax_included'=>false,'tax_class_code'=>'standard'],1);
    $h->assertSame(81,$excluded['line_tax_minor'],'tax excluded adds deterministic rounded tax');
    $h->assertSame(1081,$excluded['line_total_minor'],'tax excluded total includes tax');
    $h->assertSame('standard',$excluded['tax_lines'][0]['tax_class_code'],'tax snapshot retains configured class code');
} finally { $db=null; test_remove_tree($dir); }
exit($h->finish('UNIT sale fulfillment and tax v1'));
