<?php
declare(strict_types=1);
require_once __DIR__.'/../TestHarness.php';
require_once __DIR__.'/../../../../backend/bootstrap/runtime.php';

use App\Application\Business\ProductContentLinkService;
use App\Application\Business\StorefrontProjectionRepository;
use App\Application\Business\StorefrontProjectionService;
use App\Application\Schema\NativeFieldBlueprintRegistry;
use App\Application\Content\BlockDocumentNormalizer;
use App\Application\PublicApi\PublicCatalogApiHandler;
use App\Core\Request;
use App\Infrastructure\Persistence\Sql\SqlCmsContentSource;
use App\Modules\Business\Catalog\CatalogPricingService;
use App\Modules\Business\Repositories\BusinessCatalogPricingRepository;
use App\Modules\Business\Repositories\PosCatalogRepository;
use App\Modules\Business\Repositories\ProductContentSourceRepository;
use App\Modules\Business\Repositories\PublicCatalogRepository;
use App\Modules\Business\Services\BusinessCatalogSellableReadService;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Repository\SiteRepository;

$h=new TestHarness();
[$coreDir,$corePath,$core]=test_temp_cms_db(__DIR__.'/../../../../database/schema/core.sql');
[$businessDir,$businessPath,$business]=test_temp_cms_db(__DIR__.'/../../../../database/modules/business.sql');
[$saleDir,$salePath,$sale]=test_temp_cms_db(__DIR__.'/../../../../database/modules/sale.sql');
try {
    $core->run("INSERT INTO languages(code,name,locale,is_default) VALUES('fr','Français','fr-CH',1)");
    $core->run("INSERT INTO languages(code,name,locale,is_default) VALUES('en','English','en-GB',0)");
    $core->run("INSERT INTO sites(id,site_key,name,default_language_code) VALUES(1,'main','Main','fr')");
    $core->run("INSERT INTO site_languages(site_id,language_code,is_default,is_active) VALUES(1,'fr',1,1)");
    $core->run("INSERT INTO site_languages(site_id,language_code,is_default,is_active,sort_order,url_prefix,hreflang_code) VALUES(1,'en',0,1,2,'/en','en')");
    $core->run("INSERT INTO site_domains(id,site_id,host,is_primary) VALUES(1,1,'shop.test',1)");
    $core->run("INSERT INTO cms_sales_channel_storefronts(channel_id,site_id,domain_id,is_default,status) VALUES(3,1,1,1,'active')");
    $pricingRepo=new BusinessCatalogPricingRepository($business);
    $sellables=new BusinessCatalogSellableReadService($pricingRepo,new CatalogPricingService($pricingRepo),new PosCatalogRepository($business));
    $builder=new StorefrontProjectionService($core,$business,new ProductContentSourceRepository($business),new PublicCatalogRepository($business),$sellables,new SaleDatabaseConnection($salePath));
    $publicFixtureProducts=$business->all("SELECT id FROM business_products WHERE site_id=1 AND status='active' AND is_public=1 AND is_ecommerce_enabled=1 ORDER BY id LIMIT 2");
    if (count($publicFixtureProducts)===2) $business->run("INSERT INTO business_product_relations(site_id,product_id,related_product_id,relation_type,sort_order) VALUES(1,?,?, 'related',3)",[(int)$publicFixtureProducts[0]['id'],(int)$publicFixtureProducts[1]['id']]);
    $result=$builder->rebuild(1,3,'fr');
    $h->assertTrue($result['products']>0,'rebuild publishes at least one eligible storefront product');
    $h->assertTrue($result['collections']>0,'rebuild publishes storefront collections');
    $englishResult=$builder->rebuild(1,3,'en');
    $h->assertSame($result['products'],$englishResult['products'],'rebuild publishes the same site-scoped product set independently for another language');
    $repo=new StorefrontProjectionRepository($core);
    $page=$repo->products(1,3,'fr',['limit'=>100]);
    $h->assertSame($result['products'],count($page['items']),'headless repository reads the exact core projection set');
    $product=array_values(array_filter($page['items'],static fn(array $item):bool=>($item['type']??'')==='physical'&&!empty($item['default_sellable_id'])))[0]??$page['items'][0];
    $variantMediaId=9001;
    $documentMediaId=9002;
    $business->run("INSERT INTO business_product_assets(site_id,product_id,variant_id,media_id,role,title,alt_text,caption,sort_order,is_public,channel_scope) VALUES(1,?,?,?,'variant','Vue de variante','Variante en situation','Visuel propre à la variante',20,1,'ecommerce')",[(int)$product['product_id'],(int)$product['default_sellable_id'],$variantMediaId]);
    $business->run("INSERT INTO business_product_assets(site_id,product_id,media_id,role,title,caption,sort_order,is_public,channel_scope) VALUES(1,?,?, 'technical_sheet','Notice produit','Document de test',30,1,'ecommerce')",[(int)$product['product_id'],$documentMediaId]);
    $builder->rebuild(1,3,'fr');
    $product=$repo->product(1,3,'fr',(string)$product['slug']);
    $h->assertTrue(!in_array($variantMediaId,array_column((array)$product['media'],'media_id'),true),'a variant visual never leaks into the product-level gallery');
    $h->assertTrue(!in_array($documentMediaId,array_column((array)$product['media'],'media_id'),true),'a public document never leaks into the visual gallery');
    $h->assertTrue(in_array($documentMediaId,array_column((array)$product['documents'],'media_id'),true),'public product documents have a dedicated Storefront collection');
    $matchingSellable=array_values(array_filter((array)$product['sellables'],static fn(array $sellable):bool=>(int)($sellable['variant_id']??0)===(int)$product['default_sellable_id']))[0]??[];
    $h->assertTrue(in_array($variantMediaId,array_column((array)($matchingSellable['media']??[]),'media_id'),true),'a variant visual is exposed only on its matching sellable');
    $h->assertSame('storefront.product.v3',$product['contract'],'product DTO is explicitly versioned');
    $h->assertSame(3,(int)$product['version'],'product DTO version matches its v3 contract');
    $h->assertTrue((int)$product['default_sellable_id']>0,'product DTO exposes a stable default sellable');
    $h->assertSame((int)$product['sellables'][0]['variant_id'],(int)$product['sellables'][0]['sellable_id'],'legacy variant id remains the stable sellable id');
    $h->assertTrue(isset($product['price'],$product['availability'],$product['media'],$product['cta'],$product['seo']),'DTO contains display price, availability, media, CTA and SEO');
    $h->assertTrue(mb_strlen((string)$product['card_summary'])<=70,'canonical card summary is limited to seventy Unicode characters');
    $h->assertTrue(in_array((string)$product['availability']['display_status'],['available','last_available','on_order','unavailable'],true),'public availability uses one of the four canonical display states');
    $h->assertTrue(isset($product['commerce'],$product['content'],$product['relations']),'detail DTO exposes commerce, linked content and typed product relations');
    $h->assertSame('current',(string)($product['projection']['state']??''),'fresh projection exposes an explicit current state');
    $h->assertTrue(count($product['commerce']['delivery_methods']??[])>=2,'detail DTO reads active delivery methods from the real Sale channel');
    $h->assertTrue(count($product['commerce']['payment_methods']??[])>=2,'detail DTO reads public payment methods from the real Sale channel');
    $h->assertTrue(!empty($product['commerce']['checkout_available']),'detail DTO reflects the active Sale checkout configuration');
    $englishProduct=$repo->product(1,3,'en',(string)$product['slug']);
    $h->assertSame('en',(string)($englishProduct['locale']??''),'language-specific projection remains isolated from the French DTO');
    $h->assertTrue(count($product['seo']['hreflang']??[])>=2,'product SEO exposes all active site language alternates');
    $h->assertSame($product['product_id'],$repo->product(1,3,'fr',$product['slug'])['product_id'],'product page reads by projected slug');
    $rawProjection=$core->one('SELECT dto_json FROM storefront_product_projections WHERE site_id=1 AND channel_id=3 AND locale=\'fr\' AND product_id=?',[(int)$product['product_id']]);
    $delayedDto=json_decode((string)($rawProjection['dto_json']??'{}'),true); $delayedDto['projection']['generated_at']='2020-01-01T00:00:00+00:00';
    $core->run('UPDATE storefront_product_projections SET dto_json=? WHERE site_id=1 AND channel_id=3 AND locale=\'fr\' AND product_id=?',[json_encode($delayedDto,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),(int)$product['product_id']]);
    $h->assertSame('delayed',(string)($repo->product(1,3,'fr',(string)$product['slug'])['projection']['state']??''),'an old projection is exposed as delayed rather than silently presented as fresh');
    $builder->rebuild(1,3,'fr');
    if (count($publicFixtureProducts)===2) {
        $sourceDto=$repo->product(1,3,'fr',(string)($business->one('SELECT slug FROM business_products WHERE id=?',[(int)$publicFixtureProducts[0]['id']])['slug']??''));
        $h->assertSame('related',(string)($sourceDto['relations'][0]['type']??''),'typed product relations are hydrated into the public detail projection');
        $h->assertSame((int)$publicFixtureProducts[1]['id'],(int)($sourceDto['relations'][0]['items'][0]['product_id']??0),'projected relation card targets the public product without recursive DTO duplication');
    }

    $initialFinal=(int)($product['price']['final_minor']??0);
    $variantForState=(int)$product['default_sellable_id'];
    $business->run("DELETE FROM business_product_variant_price_adjustments WHERE variant_id=? AND price_kind='sale'",[$variantForState]);
    $business->run("INSERT INTO business_product_variant_price_adjustments(variant_id,price_kind,adjustment_type,adjustment_value,currency) VALUES(?,'sale','fixed_override',123.45,'CHF')",[$variantForState]);
    $builder->rebuild(1,3,'fr');
    $repriced=$repo->product(1,3,'fr',(string)$product['slug']);
    $h->assertTrue((int)($repriced['price']['final_minor']??0)!==$initialFinal,'rebuild propagates a changed public price to the card and detail DTO');

    if ($business->tableExists('business_inventory_projection')) $business->run('DELETE FROM business_inventory_projection WHERE variant_id=?',[$variantForState]);
    $business->run('UPDATE business_products SET track_stock=1,allow_backorder=0 WHERE id=?',[(int)$product['product_id']]);
    $business->run('UPDATE business_product_variants SET track_stock=1,allow_backorder=0,stock_quantity=0,stock_reserved=0 WHERE id=?',[$variantForState]);
    $builder->rebuild(1,3,'fr');
    $unavailable=$repo->product(1,3,'fr',(string)$product['slug']);
    $h->assertSame('unavailable',(string)($unavailable['availability']['display_status']??''),'rebuild exposes a newly unavailable product without removing its public detail');
    $h->assertSame(null,$unavailable['cta']??null,'no active CTA is projected when no variant is orderable');
    $business->run('UPDATE business_product_variants SET stock_quantity=10,allow_backorder=NULL WHERE id=?',[$variantForState]);
    $builder->rebuild(1,3,'fr');
    $availableAgain=$repo->product(1,3,'fr',(string)$product['slug']);
    $h->assertTrue(!empty($availableAgain['cta']),'rebuild restores the CTA when inventory makes the sellable orderable again');

    $h->assertSame($result['products'],(int)($core->one('SELECT COUNT(*) c FROM storefront_product_query_index WHERE site_id=1 AND channel_id=3 AND locale=\'fr\'')['c']??0),'every public DTO has one deterministic scalar query index');
    $h->assertTrue((int)($core->one('SELECT COUNT(*) c FROM storefront_product_facet_values WHERE site_id=1 AND channel_id=3 AND locale=\'fr\'')['c']??0)>0,'rebuild projects normalized facet values');
    $brandPage=$repo->products(1,3,'fr',['brand'=>['demo-outdoor'],'limit'=>100]);
    $h->assertTrue(count($brandPage['items'])>0,'brand facet returns matching projected products');
    $h->assertTrue(count(array_filter($brandPage['items'],static fn(array $item):bool=>($item['brand']['slug']??null)!=='demo-outdoor'))===0,'brand facet never leaks another brand');
    $groupPage=$repo->products(1,3,'fr',['group'=>['textile'],'limit'=>100]);
    $h->assertSame(1,$groupPage['pagination']['total'],'product group filter uses the canonical multi-group links');
    $attributeFacets=array_values(array_filter($groupPage['facets'],static fn(array $facet):bool=>($facet['type']??'')==='attribute'));
    $h->assertTrue(count($attributeFacets)>=3,'public filterable attributes appear only after one group is selected');
    $colourFacet=array_values(array_filter($attributeFacets,static fn(array $facet):bool=>($facet['key']??'')==='couleur'))[0]??[];
    $colourKeys=array_column((array)($colourFacet['options']??[]),'key');
    $h->assertTrue(in_array('bleu',$colourKeys,true)&&in_array('noir',$colourKeys,true),'variant multi-values feed the contextual public facet');
    $bluePage=$repo->products(1,3,'fr',['group'=>'textile','attributes'=>['couleur'=>['bleu']],'limit'=>100]);
    $h->assertSame(1,$bluePage['pagination']['total'],'attribute filtering combines with its selected group');
    $ignoredAttribute=$repo->products(1,3,'fr',['attributes'=>['couleur'=>['bleu']],'limit'=>100]);
    $h->assertSame($result['products'],$ignoredAttribute['pagination']['total'],'attribute filters are not exposed or applied without exactly one group');
    $searchPage=$repo->products(1,3,'fr',['q'=>'Noir','limit'=>100]);
    $h->assertSame('t-shirt-demo',$searchPage['items'][0]['slug']??null,'search uses public searchable variant values from the scalar index');
    $andPage=$repo->products(1,3,'fr',['brand'=>'demo-outdoor','category'=>'services','limit'=>100]);
    $h->assertSame(0,$andPage['pagination']['total'],'different facets combine with AND');
    $orPage=$repo->products(1,3,'fr',['brand'=>['demo-outdoor','nouvelle-marque'],'limit'=>100]);
    $h->assertSame($result['products'],$orPage['pagination']['total'],'multiple values inside one facet combine with OR');
    $pricePage=$repo->products(1,3,'fr',['sort'=>'price_asc','limit'=>100]);
    $prices=array_map(static fn(array $item):int=>(int)$item['price']['final_minor'],$pricePage['items']);
    $sorted=$prices; sort($sorted,SORT_NUMERIC);
    $h->assertSame($sorted,$prices,'price sorting is stable and numeric rather than JSON lexical sorting');
    $firstPage=$repo->products(1,3,'fr',['sort'=>'name','limit'=>2,'offset'=>0]);
    $secondPage=$repo->products(1,3,'fr',['sort'=>'name','limit'=>2,'offset'=>2]);
    $h->assertSame([],array_values(array_intersect(array_column($firstPage['items'],'product_id'),array_column($secondPage['items'],'product_id'))),'stable tie-breakers prevent duplicates across consecutive pages');
    $h->expectException(static fn()=>$repo->products(1,3,'fr',['sort'=>'sql_magic']),InvalidArgumentException::class,'an unknown public sort is rejected explicitly');

    $core->run("INSERT INTO cms_shop_configurations(site_id,language_code,channel_id,status,published_json) VALUES(1,'fr',3,'active','{}')");
    $activeRebuilds=$builder->rebuildActiveStorefronts(1);
    $h->assertSame(['fr'],array_column($activeRebuilds,'locale'),'media refresh rebuilds every active Shop language and excludes inactive languages');
    $apiQuery=['q'=>'Noir','group'=>['textile'],'attributes'=>['couleur'=>['noir']],'sort'=>'relevance','limit'=>10];
    $apiRequest=new Request('GET','/api/v1/storefront/products',$apiQuery,[],['HTTP_HOST'=>'shop.test'],[],[]);
    $apiHandler=new PublicCatalogApiHandler($apiRequest,new SiteRepository($core,['cms'=>['default_site_key'=>'main']]),new PublicCatalogRepository($business),new CatalogPricingService($pricingRepo),null,$repo);
    $apiResponse=$apiHandler->storefrontProducts(); $apiPayload=json_decode($apiResponse->body(),true);
    $directPage=$repo->products(1,3,'fr',$apiQuery+['offset'=>0]);
    $h->assertSame($directPage['items'],$apiPayload['data']['items']??null,'headless API returns the exact shared Storefront query result');
    $h->assertSame($directPage['facets'],$apiPayload['data']['facets']??null,'headless API and SSR query service share contextual facet counts');
    $invalidRequest=new Request('GET','/api/v1/storefront/products',['sort'=>'sql_magic'],[],['HTTP_HOST'=>'shop.test'],[],[]);
    $invalidHandler=new PublicCatalogApiHandler($invalidRequest,new SiteRepository($core,['cms'=>['default_site_key'=>'main']]),new PublicCatalogRepository($business),new CatalogPricingService($pricingRepo),null,$repo);
    $h->assertSame(422,$invalidHandler->storefrontProducts()->status(),'invalid public sorts produce an explicit validation response');

    $kinds=$business->all('SELECT DISTINCT kind FROM business_sellables ORDER BY kind');
    $kindValues=array_column($kinds,'kind');
    foreach (['simple','variant','service','gift_card','bundle'] as $kind) $h->assertTrue(in_array($kind,$kindValues,true),$kind.' has a formal sellable');
    $missing=(int)($business->one('SELECT COUNT(*) c FROM business_products p WHERE p.archived_at IS NULL AND NOT EXISTS(SELECT 1 FROM business_sellables s WHERE s.product_id=p.id)')['c']??0);
    $h->assertSame(0,$missing,'every product, including simple products, owns a sellable');

    $contentLinks=new ProductContentLinkService($core,new ProductContentSourceRepository($business),new SqlCmsContentSource($core));
    $hydrated=$contentLinks->hydrateStorefrontBlocks([
        ['type'=>'commerce_product','data'=>['product_id'=>$product['product_id'],'show_price'=>true]],
        ['type'=>'commerce_product','data'=>['product_id'=>$product['product_id'],'sellable_id'=>$product['default_sellable_id']]],
        ['type'=>'commerce_product_list','data'=>['product_ids'=>[$product['product_id']],'limit'=>4]],
    ],1,'fr');
    $h->assertSame($product['product_id'],$hydrated[0]['data']['items'][0]['product_id'],'single product block hydrates from core only');
    $h->assertSame($product['default_sellable_id'],$hydrated[1]['data']['items'][0]['selected_sellable_id'],'single product block resolves an explicit variant');
    $h->assertSame(1,count($hydrated[2]['data']['items']),'product list stores references and receives runtime DTOs');
    $pagedFirst=$contentLinks->hydrateStorefrontBlocks([['id'=>'products-home','type'=>'commerce_product_list','data'=>['selection_mode'=>'new','limit'=>1,'pagination'=>true,'page_param'=>'']]],1,'fr',['query'=>['campaign'=>'summer']]);
    $pageParam=(string)($pagedFirst[0]['data']['page_param']??'');
    $h->assertTrue($pageParam!==''&&($pagedFirst[0]['data']['selection']['pages']??0)>1,'Commerce pagination replaces an empty stored parameter with a stable block-specific parameter');
    $h->assertTrue(str_contains((string)($pagedFirst[0]['data']['selection']['next_url']??''),'campaign=summer')&&str_ends_with((string)($pagedFirst[0]['data']['selection']['next_url']??''),'#commerce-products-home'),'Commerce pagination preserves the page query and returns to its own block');
    $pagedSecond=$contentLinks->hydrateStorefrontBlocks([['id'=>'products-home','type'=>'commerce_product_list','data'=>['selection_mode'=>'new','limit'=>1,'pagination'=>true,'page_param'=>'']]],1,'fr',['query'=>['campaign'=>'summer',$pageParam=>2]]);
    $h->assertSame(2,$pagedSecond[0]['data']['selection']['page']??null,'a numbered Commerce pagination link selects the requested product page');
    $h->assertTrue(($pagedFirst[0]['data']['items'][0]['product_id']??0)!==($pagedSecond[0]['data']['items'][0]['product_id']??0),'the next Commerce page contains the next projected product rather than repeating page one');

    $selectionModes=[
        ['selection_mode'=>'explicit','product_ids'=>[$product['product_id']]],
        ['selection_mode'=>'brand','brand'=>(string)($product['brand']['slug']??$product['brand']['brand_id']??'')],
        ['selection_mode'=>'category','category'=>(string)($product['collection']['slug']??$product['collection']['collection_id']??'')],
        ['selection_mode'=>'new'],
        ['selection_mode'=>'popular'],
    ];
    $group=(array)($product['groups'][0]??[]); if($group!==[])$selectionModes[]=['selection_mode'=>'group','group'=>(string)($group['code']??$group['group_id']??'')];
    $attribute=(array)($product['attributes'][0]??[]); if($attribute!==[]&&($attribute['values']??[])!==[])$selectionModes[]=['selection_mode'=>'attribute','attribute_code'=>(string)($attribute['code']??''),'attribute_values'=>[(string)($attribute['values'][0]['key']??'')]];
    $selectionModes[]=['selection_mode'=>'promotion','promotion_rule'=>'percent'];
    if(count($publicFixtureProducts)===2)$selectionModes[]=['selection_mode'=>'relation','source_product_id'=>(int)$publicFixtureProducts[0]['id'],'relation_type'=>'related'];
    $selectionBlocks=array_map(static fn(array$data):array=>['type'=>'commerce_product_list','data'=>$data+['limit'=>20,'manual_product_ids'=>[$product['product_id']]]],$selectionModes);
    $selected=$contentLinks->hydrateStorefrontBlocks($selectionBlocks,1,'fr');
    foreach($selectionModes as$index=>$configuration){
        $h->assertSame($configuration['selection_mode'],$selected[$index]['data']['selection']['mode'],'commerce block hydrates the '.$configuration['selection_mode'].' selection mode');
        $h->assertSame($product['product_id'],$selected[$index]['data']['items'][0]['product_id']??null,'ordered manual override remains first for '.$configuration['selection_mode']);
    }
    $core->run('UPDATE storefront_product_projections SET is_indexable=0 WHERE site_id=1 AND channel_id=3 AND locale=\'fr\' AND product_id=?',[(int)$product['product_id']]);
    $missing=$contentLinks->hydrateStorefrontBlocks([['type'=>'commerce_product_list','data'=>['selection_mode'=>'explicit','product_ids'=>[$product['product_id']]]]],1,'fr');
    $h->assertSame([$product['product_id']],$missing[0]['data']['selection']['missing_product_ids'],'removed explicit product becomes an explained missing reference');
    $h->assertSame([],$missing[0]['data']['items'],'removed explicit product never leaks its old DTO');
    $core->run('UPDATE storefront_product_projections SET is_indexable=1 WHERE site_id=1 AND channel_id=3 AND locale=\'fr\' AND product_id=?',[(int)$product['product_id']]);
    $variantBlock=$contentLinks->hydrateStorefrontBlocks([['type'=>'commerce_product_variants','data'=>['product_id'=>$product['product_id'],'limit'=>20]]],1,'fr');
    $h->assertSame(count($product['sellables']),count($variantBlock[0]['data']['items']),'variant block exposes every public variant of one product');
    foreach(['commerce_product','commerce_product_variants','commerce_product_list','storytelling']as$blockType)$h->assertTrue(NativeFieldBlueprintRegistry::blockBlueprint($blockType)!==null,$blockType.' is available as a native Studio Blueprint');
    foreach(['featured_product','product_card','product_grid','collection_grid','product_detail','add_to_cart']as$blockType)$h->assertSame(null,NativeFieldBlueprintRegistry::blockBlueprint($blockType),$blockType.' is retired from the native Studio library');
    $normalizer=new BlockDocumentNormalizer();$normalizedCommerce=$normalizer->normalize([['id'=>'commerce-test','type'=>'commerce_product_list','editorial_status'=>'published','data'=>['selection_mode'=>'explicit','product_ids'=>[$product['product_id']],'view_label'=>'Découvrir','cart_label'=>'Commander','items'=>[$product],'selection'=>['count'=>1],'resolved'=>['forbidden'=>'runtime']]]]);
    $h->assertSame('commerce_product_list',$normalizedCommerce[0]['type'],'editorial normalizer preserves the new Commerce block identifier');
    $h->assertSame([$product['product_id']],$normalizedCommerce[0]['data']['product_ids'],'editorial normalizer persists stable product references');
    $h->assertSame('Découvrir',$normalizedCommerce[0]['data']['view_label'],'editorial normalizer persists a custom compact product view label');
    $h->assertSame('Commander',$normalizedCommerce[0]['data']['cart_label'],'editorial normalizer persists a custom compact product cart label');
    $h->assertTrue(!isset($normalizedCommerce[0]['data']['items'],$normalizedCommerce[0]['data']['selection'],$normalizedCommerce[0]['data']['resolved']),'editorial normalizer strips every runtime projection DTO before revision storage');
    $h->assertSame([],$normalizer->validateForPublication($normalizedCommerce),'a valid Commerce block passes the CMS publication contract');
    $englishBlock=$contentLinks->hydrateStorefrontBlocks([['type'=>'commerce_product_list','data'=>['selection_mode'=>'explicit','product_ids'=>[$product['product_id']]]]],1,'en');
    $h->assertSame(false,$englishBlock[0]['data']['commerce_available'],'Commerce blocks are disabled when Shop is inactive for the requested language');
    $h->assertSame([],$englishBlock[0]['data']['items'],'an inactive language never falls back to another Storefront projection');
    $foreignSiteBlock=$contentLinks->hydrateStorefrontBlocks([['type'=>'commerce_product_list','data'=>['selection_mode'=>'explicit','product_ids'=>[$product['product_id']]]]],2,'fr');
    $h->assertSame(false,$foreignSiteBlock[0]['data']['commerce_available'],'Commerce blocks never reuse a projection from another site');
    foreach(['default','aurora','pulse']as$theme){$template=(string)file_get_contents(__DIR__.'/../../../../frontend/theme-'.$theme.'/templates/partials/storefront-block.twig');$h->assertTrue(str_contains($template,'data-storefront-block'),$theme.' theme renders the shared Commerce block contract');}

    $variant=(int)$product['default_sellable_id'];
    $firstPublished=(string)($core->one('SELECT newest_at FROM storefront_product_query_index WHERE site_id=1 AND channel_id=3 AND locale=\'fr\' AND product_id=?',[(int)$product['product_id']])['newest_at']??'');
    $business->run('UPDATE business_product_variants SET stock_quantity=stock_quantity+1 WHERE id=?',[$variant]);
    $h->assertTrue((int)($business->one('SELECT COUNT(*) c FROM business_storefront_projection_invalidations WHERE processed_at IS NULL')['c']??0)>0,'availability changes enqueue invalidation');
    $builder->rebuild(1,3,'fr');
    $h->assertSame(0,(int)($business->one('SELECT COUNT(*) c FROM business_storefront_projection_invalidations WHERE processed_at IS NULL')['c']??0),'rebuild consumes pending invalidations without CMS republication');
    $h->assertSame($firstPublished,(string)($core->one('SELECT newest_at FROM storefront_product_query_index WHERE site_id=1 AND channel_id=3 AND locale=\'fr\' AND product_id=?',[(int)$product['product_id']])['newest_at']??''),'catalog update and reconstruction preserve the first e-commerce publication date');
    $h->assertSame(1,(int)($core->one('SELECT is_active FROM storefront_product_publication_history WHERE site_id=1 AND channel_id=3 AND locale=\'fr\' AND product_id=?',[(int)$product['product_id']])['is_active']??0),'publication history remains attached to the active public product');
} finally { $core=$business=$sale=null; test_remove_tree($coreDir); test_remove_tree($businessDir); test_remove_tree($saleDir); }
exit($h->finish('UNIT storefront sellables and projections'));
