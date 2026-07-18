<?php
declare(strict_types=1);

require_once __DIR__.'/../TestHarness.php';
require_once __DIR__.'/../../../../backend/bootstrap/runtime.php';

use App\Application\Business\StorefrontProjectionService;
use App\Application\Commerce\StorefrontMerchandisingService;
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
    $migrationDb=new PDO('sqlite::memory:'); $migrationDb->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $migrationDb->exec('CREATE TABLE sites(id INTEGER PRIMARY KEY)');
    $migrationDb->exec((string)file_get_contents(__DIR__.'/../../../../database/migrations/core/082_storefront_merchandising_analytics.sql'));
    $h->assertSame('storefront_product_publication_history',(string)$migrationDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name='storefront_product_publication_history'")->fetchColumn(),'migration 082 creates first-publication history incrementally');
    $h->assertSame('storefront_analytics_daily',(string)$migrationDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name='storefront_analytics_daily'")->fetchColumn(),'migration 082 creates privacy-preserving daily aggregates incrementally');
    $h->assertSame('storefront_analytics_dedup',(string)$migrationDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name='storefront_analytics_dedup'")->fetchColumn(),'migration 082 creates short-lived deduplication storage incrementally');
    $core->run("INSERT INTO languages(code,name,locale,is_default) VALUES('fr','Français','fr-CH',1),('en','English','en-GB',0)");
    $core->run("INSERT INTO sites(id,site_key,name,default_language_code) VALUES(1,'main','Main','fr')");
    $core->run("INSERT INTO site_languages(site_id,language_code,url_prefix,is_default,is_active) VALUES(1,'fr','',1,1),(1,'en','/en',0,1)");
    $pricing=new BusinessCatalogPricingRepository($business);
    $sellables=new BusinessCatalogSellableReadService($pricing,new CatalogPricingService($pricing),new PosCatalogRepository($business));
    $builder=new StorefrontProjectionService($core,$business,new ProductContentSourceRepository($business),new PublicCatalogRepository($business),$sellables);
    $builder->rebuild(1,3,'fr');
    $rows=$core->all("SELECT product_id FROM storefront_product_query_index WHERE site_id=1 AND channel_id=3 AND locale='fr' ORDER BY product_id");
    $h->assertTrue(count($rows)>=2,'fixture exposes enough public products for deterministic merchandising');
    $first=(int)$rows[0]['product_id']; $second=(int)$rows[1]['product_id'];
    $core->run('UPDATE storefront_product_query_index SET discount_amount_minor=900,discount_percent_bps=1000,availability_status=\'in_stock\' WHERE product_id=?',[$first]);
    $core->run('UPDATE storefront_product_query_index SET discount_amount_minor=500,discount_percent_bps=3000,availability_status=\'in_stock\' WHERE product_id=?',[$second]);

    $service=new StorefrontMerchandisingService($core,'unit-secret');
    $configuration=static fn(string $key,array $extra=[]):array=>array_merge(['key'=>$key,'enabled'=>true,'title'=>$key,'limit'=>6,'display'=>'grid','rule'=>'automatic','empty_state'=>'hide'], $extra);
    $amount=$service->sections(1,3,'fr',[$configuration('promotions',['rule'=>'amount'])],['items'=>[]]);
    $percent=$service->sections(1,3,'fr',[$configuration('promotions',['rule'=>'percent'])],['items'=>[]]);
    $h->assertSame($first,(int)($amount[0]['items'][0]['product_id']??0),'promotion amount ranks the greatest active absolute discount first');
    $h->assertSame($second,(int)($percent[0]['items'][0]['product_id']??0),'promotion percent ranks the greatest active percentage first');
    $manual=$service->sections(1,3,'fr',[$configuration('promotions',['rule'=>'amount','manual_product_ids'=>[$second]])],['items'=>[]]);
    $h->assertSame($second,(int)($manual[0]['items'][0]['product_id']??0),'optional manual override changes order without admitting a non-public product');
    $core->run("UPDATE storefront_product_query_index SET availability_status='unavailable' WHERE product_id=?",[$second]);
    $percent=$service->sections(1,3,'fr',[$configuration('promotions',['rule'=>'percent'])],['items'=>[]]);
    $h->assertTrue(!in_array($second,array_column($percent[0]['items'],'product_id'),true),'an unavailable offer is never presented as a best promotion');
    $new=$service->sections(1,3,'fr',[$configuration('new',['rule'=>'first_published'])],['items'=>[]]);
    $h->assertTrue(count($new[0]['items'])>0,'new products derive from recorded first e-commerce publication history');
    $groups=$service->sections(1,3,'fr',[$configuration('groups')],['items'=>[]]);
    $h->assertTrue(count($groups[0]['items'])>0 && (int)$groups[0]['items'][0]['product_count']>0,'a public group is emitted only with applicable public products');

    $server=['REQUEST_METHOD'=>'GET','REMOTE_ADDR'=>'192.0.2.42','HTTP_USER_AGENT'=>'Mozilla/5.0 Test Browser','HTTP_ACCEPT_LANGUAGE'=>'fr-CH'];
    $h->assertTrue($service->recordProductView(1,'fr',$first,$server),'first valid public product view increments an aggregate');
    $h->assertSame(false,$service->recordProductView(1,'fr',$first,$server),'refresh of the same public view is deduplicated');
    $h->assertSame(false,$service->recordProductView(1,'fr',$second,array_merge($server,['HTTP_USER_AGENT'=>'Googlebot'])),'known bot traffic is excluded');
    $h->assertSame(false,$service->recordProductView(1,'fr',$second,$server,['_theme'=>'pulse']),'theme and editorial preview traffic is excluded');
    $h->assertTrue($service->recordSearch(1,'fr',' Gourde   BLEUE ',4,$server),'a normalized search with public results is aggregated');
    $h->assertSame(false,$service->recordSearch(1,'fr','personne@example.test',4,$server),'email-like search text is never retained');
    $h->assertSame(false,$service->recordSearch(1,'fr','sans résultat',0,$server),'a zero-result search never feeds popularity');
    $keywords=$service->sections(1,3,'fr',[$configuration('keywords',['window_days'=>30])],['items'=>[]]);
    $h->assertSame('gourde bleue',$keywords[0]['items'][0]['keyword']??null,'popular keywords are normalized and site-language scoped');
    $popular=$service->sections(1,3,'fr',[$configuration('popular',['rule'=>'views','window_days'=>30])],['items'=>[]]);
    $h->assertSame($first,(int)($popular[0]['items'][0]['product_id']??0),'valid product detail views drive the popular selection');
    $h->assertSame(false,(bool)$popular[0]['fallback_used'],'real audience data disables the deterministic fallback');
    $en=$service->sections(1,3,'en',[$configuration('keywords')],['items'=>[]]);
    $h->assertSame(0,$en[0]['count'],'audience aggregates never cross language boundaries');
    $stored=json_encode($core->all('SELECT * FROM storefront_analytics_daily'),JSON_UNESCAPED_UNICODE).json_encode($core->all('SELECT * FROM storefront_analytics_dedup'));
    $h->assertSame(false,str_contains($stored,'192.0.2.42'),'raw IP address is absent from analytics persistence');
    $h->assertSame(false,str_contains($stored,'personne@example.test'),'rejected sensitive search text is absent from analytics persistence');
    $started=microtime(true);
    $complete=$service->sections(1,3,'fr',[
        $configuration('search'),$configuration('groups'),$configuration('promotions',['rule'=>'amount']),$configuration('collections'),
        $configuration('popular',['rule'=>'views']),$configuration('keywords'),$configuration('new',['rule'=>'first_published']),$configuration('catalog'),
    ],['items'=>[]]);
    $h->assertSame(8,count($complete),'all merchandising sections are resolved in one bounded service pass');
    $h->assertTrue((microtime(true)-$started)<0.5,'demo merchandising selection stays below the 500 ms unit query budget');
    $plan=$core->all("EXPLAIN QUERY PLAN SELECT entity_key,SUM(event_count) FROM storefront_analytics_daily WHERE site_id=1 AND locale='fr' AND event_type='search' AND event_date>='2026-01-01' GROUP BY entity_key");
    $h->assertTrue(str_contains(strtolower(json_encode($plan)?:''),'idx_storefront_analytics_window'),'windowed popularity query uses its composite index');
} finally { $core=$business=null; test_remove_tree($coreDir); test_remove_tree($businessDir); }
exit($h->finish('UNIT storefront merchandising and privacy 41'));
