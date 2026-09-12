<?php
declare(strict_types=1);

require_once __DIR__.'/../TestHarness.php';
require_once __DIR__.'/../../../../backend/bootstrap/runtime.php';

use App\Application\Business\StorytellingService;
use App\Application\Business\StorefrontProjectionRepository;
use App\Application\Api\Admin\BlockBlueprintApiController;
use App\Core\Request;
use App\Repository\AuthRepository;
use App\Repository\SiteRepository;
use App\Security\Authorization;

$h=new TestHarness();
[$dir,$path,$db]=test_temp_cms_db(__DIR__.'/../../../../database/schema/core.sql');
try{
    $db->run("INSERT INTO languages(code,name,locale,is_default) VALUES('fr','Français','fr-CH',1)");
    $db->run("INSERT INTO languages(code,name,locale,is_default) VALUES('en','English','en-GB',0)");
    $db->run("INSERT INTO sites(id,site_key,name,default_language_code) VALUES(1,'main','Main','fr')");
    $db->run("INSERT INTO site_languages(site_id,language_code,is_default,is_active) VALUES(1,'fr',1,1)");
    $db->run("INSERT INTO site_languages(site_id,language_code,is_default,is_active,sort_order,url_prefix) VALUES(1,'en',0,1,2,'/en')");
    $db->run("INSERT INTO cms_shop_configurations(site_id,language_code,channel_id,status,published_json) VALUES(1,'fr',3,'active','{}')");
    $service=new StorytellingService($db);
    $h->assertSame(true,$service->list(1,'fr')['shop_active'],'Shop activation is evaluated for the exact French context');
    $h->assertSame(false,$service->list(1,'en')['shop_active'],'an inactive English Shop never inherits French activation');
    $auth=new AuthRepository($db);$controller=new BlockBlueprintApiController(new Request('GET','/',[],[],[],[],[]),new SiteRepository($db,[]),$auth,new Authorization($auth),null,new StorefrontProjectionRepository($db));
    $method=(new ReflectionClass($controller))->getMethod('listBlockBlueprints');$method->setAccessible(true);
    $frTypes=array_column($method->invoke($controller,1,'fr'),'key');$enTypes=array_column($method->invoke($controller,1,'en'),'key');
    $h->assertTrue(in_array('commerce_product',$frTypes,true)&&in_array('storytelling',$frTypes,true),'Studio exposes Commerce and Storytelling blocks for the active Shop context');
    $h->assertTrue(!in_array('commerce_product',$enTypes,true)&&!in_array('storytelling',$enTypes,true),'Studio hides Commerce and Storytelling blocks for an inactive language');
    $story=$service->create(1,'fr',['title'=>'Le goût du vol','eyebrow'=>'Notre histoire','body_markdown'=>'Un **récit** produit.','cta_label'=>'Découvrir','cta_url'=>'/shop','status'=>'draft'],0);
    $h->assertSame('le-gout-du-vol',$story['storytelling_key'],'a stable key is generated from the title');
    $h->assertSame(null,$service->published(1,'fr',(int)$story['id']),'draft stories are not publicly selectable');
    $published=$service->update(1,(int)$story['id'],['status'=>'published'],0);
    $h->assertSame('published',$published['status'],'a draft can be published without losing its content');
    $h->assertSame((int)$story['id'],(int)($service->published(1,'fr',(int)$story['id'])['id']??0),'published story is available in its active Shop context');
    $h->assertSame(null,$service->published(1,'en',(int)$story['id']),'published story is isolated from another language');
    $h->assertSame(true,$service->delete(1,(int)$story['id']),'story deletion is explicit');
    $h->assertSame([],$service->list(1,'fr')['items'],'deleted story no longer appears in Marketing');
}finally{$db=null;test_remove_tree($dir);}
exit($h->finish('UNIT business storytelling'));
