<?php
declare(strict_types=1);
require_once __DIR__.'/../TestHarness.php';
require_once __DIR__.'/../../../../backend/bootstrap/runtime.php';

use App\Application\Business\ProductContentLinkService;
use App\Application\Business\StorefrontProjectionRepository;
use App\Application\Business\StorefrontProjectionService;
use App\Infrastructure\Persistence\Sql\SqlCmsContentSource;
use App\Modules\Business\Catalog\CatalogPricingService;
use App\Modules\Business\Repositories\BusinessCatalogPricingRepository;
use App\Modules\Business\Repositories\PosCatalogRepository;
use App\Modules\Business\Repositories\ProductContentSourceRepository;
use App\Modules\Business\Repositories\PublicCatalogRepository;
use App\Modules\Business\Services\BusinessCatalogSellableReadService;

$h=new TestHarness();
[$coreDir,$corePath,$core]=test_temp_cms_db(__DIR__.'/../../../../database/schema/core.sql');
[$businessDir,$businessPath,$business]=test_temp_cms_db(__DIR__.'/../../../../database/modules/business.sql');
try {
    $core->run("INSERT INTO languages(code,name,locale,is_default) VALUES('fr','Français','fr-CH',1)");
    $core->run("INSERT INTO sites(id,site_key,name,default_language_code) VALUES(1,'main','Main','fr')");
    $core->run("INSERT INTO site_domains(id,site_id,host,is_primary) VALUES(1,1,'shop.test',1)");
    $core->run("INSERT INTO cms_sales_channel_storefronts(channel_id,site_id,domain_id,is_default,status) VALUES(3,1,1,1,'active')");
    $pricingRepo=new BusinessCatalogPricingRepository($business);
    $sellables=new BusinessCatalogSellableReadService($pricingRepo,new CatalogPricingService($pricingRepo),new PosCatalogRepository($business));
    $builder=new StorefrontProjectionService($core,$business,new ProductContentSourceRepository($business),new PublicCatalogRepository($business),$sellables);
    $result=$builder->rebuild(1,3,'fr');
    $h->assertTrue($result['products']>0,'rebuild publishes at least one eligible storefront product');
    $h->assertTrue($result['collections']>0,'rebuild publishes storefront collections');
    $repo=new StorefrontProjectionRepository($core);
    $page=$repo->products(1,3,'fr',['limit'=>100]);
    $h->assertSame($result['products'],count($page['items']),'headless repository reads the exact core projection set');
    $product=$page['items'][0];
    $h->assertSame('storefront.product.v1',$product['contract'],'product DTO is explicitly versioned');
    $h->assertTrue((int)$product['default_sellable_id']>0,'product DTO exposes a stable default sellable');
    $h->assertSame((int)$product['sellables'][0]['variant_id'],(int)$product['sellables'][0]['sellable_id'],'legacy variant id remains the stable sellable id');
    $h->assertTrue(isset($product['price'],$product['availability'],$product['media'],$product['cta'],$product['seo']),'DTO contains display price, availability, media, CTA and SEO');
    $h->assertSame($product['product_id'],$repo->product(1,3,'fr',$product['slug'])['product_id'],'product page reads by projected slug');

    $kinds=$business->all('SELECT DISTINCT kind FROM business_sellables ORDER BY kind');
    $kindValues=array_column($kinds,'kind');
    foreach (['simple','variant','service','gift_card','bundle'] as $kind) $h->assertTrue(in_array($kind,$kindValues,true),$kind.' has a formal sellable');
    $missing=(int)($business->one('SELECT COUNT(*) c FROM business_products p WHERE p.archived_at IS NULL AND NOT EXISTS(SELECT 1 FROM business_sellables s WHERE s.product_id=p.id)')['c']??0);
    $h->assertSame(0,$missing,'every product, including simple products, owns a sellable');

    $contentLinks=new ProductContentLinkService($core,new ProductContentSourceRepository($business),new SqlCmsContentSource($core));
    $hydrated=$contentLinks->hydrateStorefrontBlocks([
        ['type'=>'product_card','data'=>['product_id'=>$product['product_id'],'show_price'=>true]],
        ['type'=>'add_to_cart','data'=>['sellable_id'=>$product['default_sellable_id'],'quantity'=>1]],
        ['type'=>'product_grid','data'=>['product_ids'=>[$product['product_id']],'limit'=>4]],
    ],1,'fr');
    $h->assertSame($product['product_id'],$hydrated[0]['data']['product']['product_id'],'CMS product card hydrates from core only');
    $h->assertSame($product['default_sellable_id'],$hydrated[1]['data']['resolved']['sellable']['sellable_id'],'add-to-cart block resolves a sellable reference');
    $h->assertSame(1,count($hydrated[2]['data']['items']),'product grid stores references and receives runtime DTOs');

    $variant=(int)$product['default_sellable_id'];
    $business->run('UPDATE business_product_variants SET stock_quantity=stock_quantity+1 WHERE id=?',[$variant]);
    $h->assertTrue((int)($business->one('SELECT COUNT(*) c FROM business_storefront_projection_invalidations WHERE processed_at IS NULL')['c']??0)>0,'availability changes enqueue invalidation');
    $builder->rebuild(1,3,'fr');
    $h->assertSame(0,(int)($business->one('SELECT COUNT(*) c FROM business_storefront_projection_invalidations WHERE processed_at IS NULL')['c']??0),'rebuild consumes pending invalidations without CMS republication');
} finally { $core=$business=null; test_remove_tree($coreDir); test_remove_tree($businessDir); }
exit($h->finish('UNIT storefront sellables and projections'));
