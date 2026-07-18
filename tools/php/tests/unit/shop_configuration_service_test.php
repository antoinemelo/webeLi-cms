<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Application\Api\Admin\SaleEcommerceAdminApiController;
use App\Application\Business\StorefrontProjectionRepository;
use App\Application\Business\StorefrontProjectionService;
use App\Application\Commerce\ShopConfigurationService;
use App\Application\Commerce\StorefrontMerchandisingService;
use App\Core\ApiException;
use App\Core\Database;
use App\Core\Request;
use App\Modules\Business\Catalog\CatalogPricingService;
use App\Modules\Business\Repositories\BusinessCatalogPricingRepository;
use App\Modules\Business\Repositories\PosCatalogRepository;
use App\Modules\Business\Repositories\ProductContentSourceRepository;
use App\Modules\Business\Repositories\PublicCatalogRepository;
use App\Modules\Business\Services\BusinessCatalogSellableReadService;
use App\Modules\Sale\Repositories\SaleChannelRepository;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;

$h = new TestHarness();
[$coreDir,$corePath,$core] = test_temp_cms_db(__DIR__ . '/../../../../database/schema/core.sql');
[$businessDir,$businessPath,$business] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/business.sql');
[$saleDir,$salePath,$sale] = test_temp_cms_db(__DIR__ . '/../../../../database/modules/sale.sql');
[$iamDir,$iamPath] = test_temp_db(__DIR__ . '/../../../../database/iam.sql');

try {
    $migrationDatabase = new PDO('sqlite::memory:');
    $migrationDatabase->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $migrationDatabase->exec('CREATE TABLE site_languages(site_id INTEGER NOT NULL, language_code TEXT NOT NULL, PRIMARY KEY(site_id,language_code))');
    $migrationSql = file_get_contents(__DIR__ . '/../../../../database/migrations/core/080_shop_system_configurations.sql');
    if ($migrationSql === false) throw new RuntimeException('Shop migration fixture missing');
    $migrationDatabase->exec($migrationSql);
    $h->assertSame('cms_shop_configurations',(string)$migrationDatabase->query("SELECT name FROM sqlite_master WHERE type='table' AND name='cms_shop_configurations'")->fetchColumn(),'incremental migration creates the Shop table on an existing Core database');
    $h->assertSame('idx_cms_shop_configurations_status',(string)$migrationDatabase->query("SELECT name FROM sqlite_master WHERE type='index' AND name='idx_cms_shop_configurations_status'")->fetchColumn(),'incremental migration creates the Shop status index');
    $h->assertSame(0,(int)$migrationDatabase->query('SELECT COUNT(*) FROM cms_shop_configurations')->fetchColumn(),'incremental migration never initializes or activates a Shop implicitly');

    $core->run("INSERT INTO languages(code,name,native_name,locale,is_default) VALUES('fr','Français','Français','fr-CH',1),('en','English','English','en-GB',0)");
    $core->run("INSERT INTO sites(id,site_key,name,default_language_code) VALUES(1,'main','Main','fr'),(2,'second','Second','en')");
    $core->run("INSERT INTO site_languages(site_id,language_code,locale,url_prefix,is_default,is_active,sort_order) VALUES(1,'fr','fr-CH','',1,1,1),(1,'en','en-GB','/en',0,1,2),(2,'en','en-GB','',1,1,1)");
    $core->run("INSERT INTO site_domains(id,site_id,host,is_primary) VALUES(1,1,'main.test',1),(2,2,'second.test',1)");
    $core->run("INSERT INTO themes(theme_key,name,version,is_default,is_active,config_json) VALUES('default','Default','1.0.0',1,1,'{}')");
    $core->run("INSERT INTO menus(site_id,menu_key,name,menu_location) VALUES(1,'main','Main','primary'),(2,'main','Main','primary')");

    $saleConnection = new SaleDatabaseConnection($salePath);
    $channels = new SaleChannelRepository($saleConnection);
    $secondChannel = $channels->create(2,['code'=>'web-second','name'=>'Second shop','type'=>'storefront','status'=>'active','is_default'=>true,'is_public'=>true,'currency'=>'EUR','default_language'=>'en']);
    $pricing = new BusinessCatalogPricingRepository($business);
    $sellables = new BusinessCatalogSellableReadService($pricing,new CatalogPricingService($pricing),new PosCatalogRepository($business));
    $builder = new StorefrontProjectionService($core,$business,new ProductContentSourceRepository($business),new PublicCatalogRepository($business),$sellables);
    $service = new ShopConfigurationService($core,$saleConnection,$builder,new StorefrontMerchandisingService($core,'shop-test'));
    $public = new StorefrontProjectionRepository($core);

    $initial = $service->configuration(1,'fr');
    $h->assertSame('not_initialized',$initial['status'],'opening settings does not initialize or publish a Shop');
    $h->assertSame(8,count($initial['draft']['sections']??[]),'Studio exposes the eight canonical merchandising sections by default');
    $h->assertSame(6,(int)($initial['draft']['sections'][2]['limit']??0),'best-offer section defaults to six products');
    $h->assertSame('percent',$initial['draft']['sections'][2]['rule']??null,'best-offer default explicitly ranks by percentage');
    $h->assertSame(30,(int)($initial['draft']['analytics']['dedupe_minutes']??0),'analytics refresh deduplication has a bounded default');
    $englishDefaults=$service->configuration(1,'en');
    $h->assertSame('Best offers',$englishDefaults['draft']['sections'][2]['title']??null,'merchandising titles are localized independently per Shop language');
    $h->assertSame(0,(int)($core->one('SELECT COUNT(*) c FROM cms_shop_configurations')['c']??0),'read-only matrix performs no write');

    $draft = $service->saveDraft(1,'fr',['channel_id'=>3,'title'=>'Boutique système','introduction'=>'Bienvenue','menu_label'=>'Produits','cart_visible'=>true],7);
    $h->assertSame('inactive',$draft['status'],'saving Studio draft keeps public Shop inactive');
    $h->assertSame(0,$public->defaultChannelId(1,'fr'),'headless projection is hidden before explicit activation');
    $published = $service->publish(1,'fr',7);
    $h->assertSame(1,$published['published_version'],'publishing records a deterministic configuration version');
    $h->assertSame(false,$published['public_visible'],'publishing Studio content does not activate the public Shop');

    $active = $service->activate(1,'fr',7);
    $h->assertSame('active',$active['status'],'explicit activation makes the Shop active');
    $h->assertSame(3,$public->defaultChannelId(1,'fr'),'active locale resolves its configured storefront channel');
    $h->assertSame('Boutique système',$public->activeShop(1,'fr')['published']['title']??null,'public page reads the published system-page snapshot');
    $service->activate(1,'fr',7);
    $h->assertSame(1,(int)($core->one('SELECT COUNT(*) c FROM cms_shop_configurations WHERE site_id=1 AND language_code=\'fr\'')['c']??0),'replayed activation does not duplicate configuration');
    $h->assertSame(1,(int)($core->one('SELECT COUNT(*) c FROM cms_sales_channel_storefronts WHERE site_id=1 AND channel_id=3')['c']??0),'replayed activation does not duplicate channel mapping');

    $service->saveDraft(1,'en',['channel_id'=>3,'title'=>'Shop','currency'=>'CHF'],7);
    $service->publish(1,'en',7);
    $service->activate(1,'en',7);
    $service->deactivate(1,'fr',7);
    $h->assertSame(0,$public->defaultChannelId(1,'fr'),'deactivation hides only the selected locale');
    $h->assertSame(3,$public->defaultChannelId(1,'en'),'another active locale on the same channel remains public');
    $h->assertSame('Boutique système',$service->configuration(1,'fr')['draft']['title']??null,'deactivation preserves editorial configuration');

    $service->saveDraft(2,'en',['channel_id'=>(int)$secondChannel['channel_id'],'title'=>'Second Shop','currency'=>'EUR'],8);
    $service->publish(2,'en',8);
    $service->activate(2,'en',8);
    $h->assertSame((int)$secondChannel['channel_id'],$public->defaultChannelId(2,'en'),'second site remains isolated on its own channel');

    $service->deactivate(1,'en',7);
    $core->run('ALTER TABLE storefront_product_projections RENAME TO storefront_product_projections_unavailable');
    try { $service->activate(1,'en',7,true); } catch (RuntimeException) {}
    $h->assertSame('error',$service->configuration(1,'en')['status'],'partial activation failure remains non-public and repairable');
    $h->assertSame(0,$public->defaultChannelId(1,'en'),'failed activation never exposes the route');
    $core->run('ALTER TABLE storefront_product_projections_unavailable RENAME TO storefront_product_projections');
    $repaired = $service->activate(1,'en',7,true);
    $h->assertSame('active',$repaired['status'],'repair resumes the same configuration after failure');
    $h->assertSame(null,$repaired['last_error_code'],'repair clears the safe diagnostic');

    $h->assertSame(0,(int)($core->one("SELECT COUNT(*) c FROM content_entries WHERE entry_key LIKE 'product-%'")['c']??0),'Shop activation creates no CMS product page');

    $iam = new Database($iamPath,1000);
    $iam->run("INSERT INTO iam_users(id,email,email_normalized,password_hash,is_active,login_mode) VALUES
        (1,'settings@example.test','settings@example.test','x',1,'password'),
        (2,'editor@example.test','editor@example.test','x',1,'password'),
        (3,'empty@example.test','empty@example.test','x',1,'password')");
    $iam->run("INSERT INTO iam_roles(id,role_key,name) VALUES(1,'shop_settings','Shop settings'),(2,'shop_editor','Shop editor'),(3,'empty','Empty')");
    foreach (['sale.settings.manage','content.read','content.revisions.save','content.publish'] as $index=>$permission) {
        $iam->run('INSERT INTO iam_permissions(id,permission_key,name) VALUES(?,?,?)',[$index+1,$permission,$permission]);
    }
    $iam->run("INSERT INTO iam_role_permissions(role_id,permission_id) VALUES(1,1),(2,2),(2,3),(2,4)");
    $iam->run("INSERT INTO iam_user_site_roles(user_id,site_id,role_id) VALUES(1,1,1),(2,1,2),(3,1,3)");
    $sites = new SiteRepository($core,['cms'=>['default_site_key'=>'main'],'app'=>['default_locale'=>'fr']]);
    $controllerFor = static function(int $userId,string $method) use($iam,$sites,$core,$service): SaleEcommerceAdminApiController {
        $token='shop-permission-'.$userId;
        $iam->run('DELETE FROM iam_sessions WHERE user_id=?',[$userId]);
        $iam->run('INSERT INTO iam_sessions(user_id,session_token_hash,ip_address,user_agent,last_seen_at,expires_at,created_at) VALUES(?,?,?,?,?,?,?)',[
            $userId,hash('sha256',$token),'127.0.0.1','shop-test',gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s',time()+3600),gmdate('Y-m-d H:i:s'),
        ]);
        $_SESSION['admin_user']=['id'=>$userId,'email'=>'shop-'.$userId.'@example.test','session_secret'=>$token];
        $request = new Request($method,'/admin/api/sale/ecommerce/shops/1/fr',[],[],['HTTP_HOST'=>'main.test'],[],[]);
        $auth = new AuthRepository($iam);
        return new SaleEcommerceAdminApiController($request,$sites,$auth,new Authorization($auth),$core,$service);
    };
    $h->assertSame(200,$controllerFor(1,'GET')->show(1,'fr')->status(),'Sale settings role can read Shop state');
    $originalEnvBase=$_ENV['APP_BASE_PATH']??null;
    $originalServerBase=$_SERVER['APP_BASE_PATH']??null;
    $originalProcessBase=getenv('APP_BASE_PATH');
    try {
        foreach (['/edu','/eve'] as $configuredBasePath) {
            $_ENV['APP_BASE_PATH']=$configuredBasePath;
            $_SERVER['APP_BASE_PATH']=$configuredBasePath;
            putenv('APP_BASE_PATH='.$configuredBasePath);
            $settingsPayload=json_decode($controllerFor(1,'GET')->shops()->body(),true);
            $englishSettings=array_values(array_filter((array)($settingsPayload['data']['shops']??[]),static fn(array $shop):bool=>(int)($shop['site_id']??0)===1&&($shop['language_code']??'')==='en'));
            $h->assertSame($configuredBasePath.'/en/shop',$englishSettings[0]['preview_path']??null,'Shop preview path follows the configured installation subdirectory '.$configuredBasePath);
        }
    } finally {
        if ($originalEnvBase===null) unset($_ENV['APP_BASE_PATH']); else $_ENV['APP_BASE_PATH']=$originalEnvBase;
        if ($originalServerBase===null) unset($_SERVER['APP_BASE_PATH']); else $_SERVER['APP_BASE_PATH']=$originalServerBase;
        $originalProcessBase===false ? putenv('APP_BASE_PATH') : putenv('APP_BASE_PATH='.$originalProcessBase);
    }
    $editorPayload=json_decode($controllerFor(2,'GET')->show(1,'fr')->body(),true);
    $h->assertSame(false,$editorPayload['data']['permissions']['activate']??true,'Studio editor can read and edit but cannot activate');
    $h->expectException(fn()=> $controllerFor(2,'POST')->activate(1,'fr'),ApiException::class,'Studio editor cannot activate a Shop');
    $h->expectException(fn()=> $controllerFor(1,'PUT')->save(1,'fr'),ApiException::class,'Sale settings role cannot modify Studio content without editorial permission');
    $h->expectException(fn()=> $controllerFor(3,'GET')->show(1,'fr'),ApiException::class,'role without Shop permissions cannot read the configuration');
} finally {
    $core=$business=$sale=$iam=null;
    test_remove_tree($coreDir); test_remove_tree($businessDir); test_remove_tree($saleDir); test_remove_tree($iamDir);
}

exit($h->finish('Shop system configuration and activation 39'));
