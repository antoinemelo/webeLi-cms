<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Core\Renderer;
use App\Core\NativeHtmlRenderer;
use App\Application\Frontend\ResolvePublicRoute;
use App\Application\Frontend\SiteReadRepository;
use App\Application\Frontend\PublicStorefrontCartController;

$h = new TestHarness();
$product = [
    'contract'=>'storefront.product.v3','version'=>3,'product_id'=>42,'site_id'=>1,'channel_id'=>3,'locale'=>'fr',
    'slug'=>'produit-video','type'=>'physical','name'=>'Produit vidéo','summary'=>'Résumé public','card_summary'=>'Résumé public','description'=>'Description.',
    'brand'=>['name'=>'Marque'],'collection'=>['name'=>'Collection'],'groups'=>[],
    'attributes'=>[['name'=>'Couleur','unit'=>null,'values'=>[['label'=>'Bleu']]]],
    'media'=>[
        ['media_id'=>10,'type'=>'video','url'=>'/media/demo.mp4','alt'=>'Démonstration du produit','caption'=>'Vidéo de démonstration'],
        ['media_id'=>11,'type'=>'image','url'=>'/media/demo.jpg','alt'=>'Produit de face','caption'=>'Vue de face'],
    ],
    'documents'=>[
        ['media_id'=>12,'type'=>'document','url'=>'/media/notice.pdf','title'=>'Notice de montage','caption'=>'Version PDF'],
    ],
    'sellables'=>[
        ['sellable_id'=>101,'variant_id'=>101,'sku'=>'VIDEO-BLUE','name'=>'Bleu','options'=>[['option_name'=>'Couleur','label'=>'Bleu','color_hex'=>'#2563eb'],['option_name'=>'Taille','label'=>'M']],'price'=>['regular_minor'=>12900,'final_minor'=>9900,'currency'=>'CHF','discount_percent_bps'=>2326],'availability'=>['display_status'=>'last_available','label'=>'Dernier disponible','tone'=>'warning','available_quantity'=>2,'lead_time_days'=>0],'media'=>[['type'=>'video','url'=>'/media/demo.mp4','alt'=>'Démonstration','caption'=>'Variante bleue']],'orderable'=>true],
        ['sellable_id'=>102,'variant_id'=>102,'sku'=>'VIDEO-RED','name'=>'Rouge','options'=>[['option_name'=>'Couleur','label'=>'Rouge','color_hex'=>'#dc2626'],['option_name'=>'Taille','label'=>'L']],'price'=>['regular_minor'=>12900,'final_minor'=>12900,'currency'=>'CHF','discount_percent_bps'=>0],'availability'=>['display_status'=>'unavailable','label'=>'Indisponible','tone'=>'danger','available_quantity'=>0,'lead_time_days'=>0],'media'=>[],'orderable'=>false],
    ],
    'default_sellable_id'=>101,'sku'=>'VIDEO-BLUE','price'=>['regular_minor'=>12900,'final_minor'=>9900,'currency'=>'CHF','discount_percent_bps'=>2326],
    'availability'=>['display_status'=>'available','label'=>'Disponible','tone'=>'success','is_orderable'=>true],
    'cta'=>['sellable_id'=>101,'label'=>'Ajouter au panier'],'commerce'=>['delivery_methods'=>[['label'=>'Livraison standard','price_minor'=>900]],'payment_methods'=>[['label'=>'Carte','description'=>'Paiement sécurisé']],'checkout_available'=>true],
    'content'=>[],'relations'=>[],'projection'=>['state'=>'current'],'url'=>'/shop/products/produit-video',
];

foreach (['default','aurora','pulse'] as $theme) {
    $renderer = new Renderer(dirname(__DIR__, 4) . '/frontend/theme-' . $theme . '/templates');
    $html = $renderer->render('storefront-product', ['storefront_product'=>$product,'storefront_return_url'=>'/shop','canonical'=>'https://example.test/shop/products/produit-video']);
    $h->assertTrue(str_contains($html, '<video'), $theme . ' renders the canonical video gallery');
    $h->assertTrue(str_contains($html, 'Vidéo de démonstration'), $theme . ' renders the public media caption');
    $h->assertTrue(str_contains($html, 'Documents à télécharger') && str_contains($html, 'href="/media/notice.pdf"') && str_contains($html, 'Notice de montage'), $theme . ' renders public documents outside the visual gallery');
    $h->assertTrue(str_contains($html, 'data-product-variant'), $theme . ' renders accessible variant selection');
    $h->assertTrue(str_contains($html, 'LinkedIn') && str_contains($html, 'Facebook') && str_contains($html, 'mailto:'), $theme . ' renders social and email sharing');
    $h->assertTrue(str_contains($html,'class="social-share__action social-share__action--linkedin"')&&str_contains($html,'data-copy-url=')&&!str_contains($html,'data-copy-product-url'),$theme.' reuses the page and article sharing component');
    $grid=$renderer->render('partials/storefront-block',['block_type'=>'commerce_product_list','data'=>['commerce_available'=>true,'items'=>[$product],'columns'=>2,'show_price'=>false,'show_promotion'=>false,'show_availability'=>false,'show_cta'=>false,'selection'=>['missing_product_ids'=>[999],'pages'=>1]]]);
    $h->assertTrue(str_contains($grid,'data-product-id="42"')&&str_contains($grid,'--storefront-columns:2'),$theme.' renders projected Commerce blocks with their configured layout');
    $h->assertTrue(!str_contains($grid,'99.00')&&!str_contains($grid,'data-storefront-add-to-cart')&&!str_contains($grid,'Disponible'),$theme.' respects card visibility options');
    $pagedGrid=$renderer->render('partials/storefront-block',['block_type'=>'commerce_product_list','data'=>['commerce_available'=>true,'items'=>[$product],'columns'=>2,'view_label'=>'Découvrir','cart_label'=>'Commander','pagination'=>true,'block_anchor'=>'commerce-products-home','selection'=>['missing_product_ids'=>[],'page'=>1,'pages'=>2,'links'=>[['page'=>1,'current'=>true,'url'=>'#commerce-products-home'],['page'=>2,'current'=>false,'url'=>'?commerce_products_home=2#commerce-products-home']],'previous_url'=>null,'next_url'=>'?commerce_products_home=2#commerce-products-home']]]);
    $h->assertTrue(str_contains($pagedGrid,'id="commerce-products-home"')&&str_contains($pagedGrid,'href="?commerce_products_home=2#commerce-products-home"')&&str_contains($pagedGrid,'aria-current="page"'),$theme.' renders active numbered pagination links anchored to the product block');
    $h->assertTrue(str_contains($pagedGrid,'>Découvrir</a>')&&str_contains($pagedGrid,'>Commander</button>'),$theme.' renders custom compact product action labels');
    $inactive=$renderer->render('partials/storefront-block',['block_type'=>'commerce_product','data'=>['commerce_available'=>false,'items'=>[$product]]]);
    $h->assertSame('',trim($inactive),$theme.' does not render a Commerce block when Shop is inactive for the context');
    $story=$renderer->render('partials/storefront-block',['block_type'=>'storytelling','data'=>['commerce_available'=>true,'storytelling'=>['title'=>'Notre histoire','eyebrow'=>'Découvrir','body_markdown'=>'Un **récit** utile.','cta_label'=>'Voir','cta_url'=>'/shop']]]);
    $h->assertTrue(str_contains($story,'Notre histoire')&&str_contains($story,'<strong>récit</strong>')&&str_contains($story,'href="/shop"'),$theme.' renders a published Storytelling through the shared block contract');
    $empty=$renderer->render('partials/storefront-block',['block_type'=>'commerce_product_list','data'=>['commerce_available'=>true,'items'=>[],'empty_state'=>'message','empty_message'=>'Sélection indisponible','selection'=>['missing_product_ids'=>[],'pages'=>0]]]);
    $h->assertTrue(str_contains($empty,'Sélection indisponible')&&!str_contains($empty,'storefront_product_projections'),$theme.' renders a configured empty state without technical leakage');
    $layout=$renderer->render('layout',['languageCode'=>'fr','content'=>'<p>Contenu</p>','storefront_cart_visible'=>true,'storefront_channel_code'=>'web-main','menu_items'=>[],'footer_menu_items'=>[],'footer_top_menu_items'=>[],'footer_bottom_menu_items'=>[],'site'=>['name'=>'Test'],'site_localization'=>['site_title'=>'Test'],'ui'=>['skip_to_content'=>'Contenu','main_navigation'=>'Navigation','home'=>'Accueil','menu'=>'Menu','footer_navigation'=>'Pied de page','cart'=>'Panier','open_cart'=>'Ouvrir le panier']]);
    $headerStart=strpos($layout,'<header');$cartToggle=strpos($layout,'data-cart-toggle');$navigation=strpos($layout,'id="site-navigation"');$headerEnd=strpos($layout,'</header>');
    $h->assertTrue($headerStart!==false&&$cartToggle!==false&&$headerEnd!==false&&$headerStart<$cartToggle&&$cartToggle<$headerEnd,$theme.' renders the cart control inside the navigation header');
    $h->assertTrue($navigation!==false&&$cartToggle<$navigation&&str_contains($layout,'cart-header.css'),$theme.' keeps the cart control outside the collapsible mobile navigation');
    $h->assertTrue(substr_count($layout,'data-cart-toggle')===1&&str_contains($layout,'class="bi bi-bag"'),$theme.' renders one compact Bootstrap-style bag icon instead of a floating text button');
    $h->assertTrue(str_contains($layout,'data-storefront-api-base="'.public_api_url_path('sale/channels/web-main').'"')&&str_contains($layout,'data-storefront-cart-url="'.localized_path('/cart','fr').'"'),$theme.' gives the cart client explicit installation-aware API and page URLs');
    $h->assertTrue(str_contains($layout,'cart-drawer__header')&&str_contains($layout,'cart-drawer__footer')&&str_contains($layout,'cart-drawer__close'),$theme.' renders the shared modern cart drawer structure');
    $languageLayout=$renderer->render('layout',['languageCode'=>'de','content'=>'<p>Inhalt</p>','menu_items'=>[],'footer_menu_items'=>[],'footer_top_menu_items'=>[],'footer_bottom_menu_items'=>[],'language_menu_items'=>[['code'=>'de','label'=>'Deutsch','url'=>'/de','is_active'=>true],['code'=>'fr','label'=>'Français','url'=>'/fr','is_active'=>false]],'site'=>['name'=>'Test'],'site_localization'=>['site_title'=>'Test'],'ui'=>['skip_to_content'=>'Inhalt','main_navigation'=>'Navigation','home'=>'Start','menu'=>'Menü','footer_navigation'=>'Fußzeile','language_menu'=>'Sprache']]);
    $h->assertTrue(str_contains($languageLayout,'class="bi bi-translate language-switch__icon"')&&str_contains($languageLayout,'aria-label="Sprache"')&&!str_contains($languageLayout,'>Sprache</button>'),$theme.' displays the translation icon while keeping an accessible localized label');
}

$nativeShop=NativeHtmlRenderer::render('storefront-shop',[
    'languageCode'=>'fr','entry_title'=>'Boutique','storefront_introduction'=>'Catalogue public',
    'storefront_selection'=>['q'=>'','sort'=>'name'],'storefront_sorts'=>[['key'=>'name','label'=>'Nom']],
    'storefront_facets'=>[],'storefront_catalog'=>['reset_url'=>'/shop'],'pagination'=>['total'=>1,'page'=>1,'pages'=>1],
    'storefront_merchandising_sections'=>[
        ['key'=>'search','display'=>'list','items'=>[],'count'=>0,'empty_state'=>'hidden'],
        ['key'=>'catalog','title'=>'Produits','display'=>'grid','items'=>[$product],'count'=>1,'empty_state'=>'message'],
    ],
]);
$h->assertTrue(str_contains($nativeShop,'data-shop-section="catalog"')&&str_contains($nativeShop,'data-product-id="42"')&&str_contains($nativeShop,'1 résultat(s)'), 'native no-vendor renderer displays the Shop catalog instead of falling back to a generic CMS page');
$nativeProduct=NativeHtmlRenderer::render('storefront-product',['languageCode'=>'fr','storefront_product'=>$product,'storefront_return_url'=>'/shop']);
$h->assertTrue(str_contains($nativeProduct,'data-product-detail')&&str_contains($nativeProduct,'Produit vidéo')&&str_contains($nativeProduct,'data-storefront-add-to-cart'), 'native no-vendor renderer keeps product details and cart actions usable');
$nativeLayout=NativeHtmlRenderer::render('layout',['languageCode'=>'fr','content'=>$nativeShop,'storefront_cart_visible'=>true,'storefront_channel_code'=>'web-main','menu_items'=>[],'footer_menu_items'=>[],'footer_top_menu_items'=>[],'footer_bottom_menu_items'=>[],'site'=>['name'=>'Test'],'site_localization'=>['site_title'=>'Test'],'ui'=>['skip_to_content'=>'Contenu','main_navigation'=>'Navigation','home'=>'Accueil','menu'=>'Menu','footer_navigation'=>'Pied de page','cart'=>'Panier','open_cart'=>'Ouvrir le panier']]);
$h->assertTrue(str_contains($nativeLayout,'data-cart-toggle')&&str_contains($nativeLayout,'data-storefront-api-base=')&&str_contains($nativeLayout,'cart-header.css')&&str_contains($nativeLayout,'storefront-cart-drawer'), 'native no-vendor layout renders the same compact Shop button and cart runtime as Twig');
$nativeLanguageLayout=NativeHtmlRenderer::render('layout',['languageCode'=>'de','content'=>'<p>Inhalt</p>','menu_items'=>[],'footer_menu_items'=>[],'footer_top_menu_items'=>[],'footer_bottom_menu_items'=>[],'language_menu_items'=>[['code'=>'de','label'=>'Deutsch','url'=>'/de','is_active'=>true],['code'=>'fr','label'=>'Français','url'=>'/fr','is_active'=>false]],'site'=>['name'=>'Test'],'site_localization'=>['site_title'=>'Test'],'ui'=>['skip_to_content'=>'Inhalt','main_navigation'=>'Navigation','home'=>'Start','menu'=>'Menü','footer_navigation'=>'Fußzeile','language_menu'=>'Sprache']]);
$h->assertTrue(str_contains($nativeLanguageLayout,'class="bi bi-translate language-switch__icon"')&&str_contains($nativeLanguageLayout,'aria-label="Sprache"')&&!str_contains($nativeLanguageLayout,'>Sprache</button>'),'native no-vendor layout displays the translation icon with an accessible localized label');

$cartCss=(string)file_get_contents(dirname(__DIR__,4).'/frontend/theme-default/assets/css/cart.css');
$h->assertTrue(!str_contains($cartCss,'.storefront-cart-toggle{position:fixed')&&str_contains($cartCss,'.storefront-cart-toggle .bi-bag'),'cart styles keep the bag icon in navigation flow rather than fixing it at page bottom');
$cartJs=(string)file_get_contents(dirname(__DIR__,4).'/frontend/theme-default/assets/js/storefront-cart.js');
$h->assertTrue(str_contains($cartJs,'dataset.storefrontApiBase')&&str_contains($cartJs,'const endpoint = (path) => `${apiBase.replace'),'cart requests use the server-provided API base instead of relying on script-path detection');
$h->assertTrue(str_contains($cartJs,'data-cart-decrease')&&str_contains($cartJs,'data-cart-increase'),'cart client exposes accessible stepper controls while keeping direct quantity input');
$h->assertTrue(str_contains($cartJs,'add.dataset.sellableIds || add.dataset.sellableId')&&str_contains($cartJs,'for (const sellableId of sellableIds)'),'cart client adds every explicitly selected card variant while retaining single-sellable actions');
$cartPage=(new PublicStorefrontCartController())->show()->body();
$h->assertTrue(!str_contains($cartJs,'data-cart-status')&&!str_contains($cartJs,'Chargement du panier')&&!str_contains($cartJs,'Panier mis à jour')&&!str_contains($cartPage,'data-cart-status')&&!str_contains($cartPage,'Chargement'),'cart updates rely on direct visual changes without redundant loading or updated status copy');

$hadEnv=array_key_exists('APP_BASE_PATH',$_ENV);$oldEnv=$_ENV['APP_BASE_PATH']??null;
$hadServer=array_key_exists('APP_BASE_PATH',$_SERVER);$oldServer=$_SERVER['APP_BASE_PATH']??null;
$hadSiteBase=array_key_exists('CMS_SITE_BASE_PATH',$_SERVER);$oldSiteBase=$_SERVER['CMS_SITE_BASE_PATH']??null;
$oldProcess=getenv('APP_BASE_PATH');
$_ENV['APP_BASE_PATH']='/cms';$_SERVER['APP_BASE_PATH']='/cms';$_SERVER['CMS_SITE_BASE_PATH']='/cms';putenv('APP_BASE_PATH=/cms');
$h->assertSame('/cms/api/v1/sale/channels/web-main',public_api_url_path('sale/channels/web-main'),'public Sale API URLs never duplicate an installation directory already exposed as the site base');
$_SERVER['CMS_SITE_BASE_PATH']='';
$h->assertSame('/cms',localized_home_path('fr'),'home navigation avoids the cached trailing-slash installation URL');
$h->assertSame('/cms/storage/media/product.jpg',public_asset_url_path('/storage/media/product.jpg'),'public product media includes the installation directory');
$h->assertSame('/cms/storage/media/product.jpg',public_asset_url_path('/cms/storage/media/product.jpg'),'already localized public media is not prefixed twice');
$_SERVER['CMS_SITE_BASE_PATH']='/site-a';
$h->assertSame('/cms/site-a/api/v1/sale/channels/web-main',public_api_url_path('sale/channels/web-main'),'public Sale API URLs combine distinct installation and multisite paths');
$h->assertSame('/cms/site-a',localized_home_path('fr'),'subsite home navigation also uses one stable slashless URL');
if($hadEnv)$_ENV['APP_BASE_PATH']=$oldEnv;else unset($_ENV['APP_BASE_PATH']);
if($hadServer)$_SERVER['APP_BASE_PATH']=$oldServer;else unset($_SERVER['APP_BASE_PATH']);
if($hadSiteBase)$_SERVER['CMS_SITE_BASE_PATH']=$oldSiteBase;else unset($_SERVER['CMS_SITE_BASE_PATH']);
$oldProcess===false?putenv('APP_BASE_PATH'):putenv('APP_BASE_PATH='.$oldProcess);

$routeReflection=new ReflectionClass(ResolvePublicRoute::class);
$routeWithoutDependencies=$routeReflection->newInstanceWithoutConstructor();
$sitesProperty=$routeReflection->getProperty('sites');$sitesProperty->setAccessible(true);
$sitesProperty->setValue($routeWithoutDependencies,new class implements SiteReadRepository {
    public function resolveCurrentSite(string $host='',string $path='',?bool $isHttps=null):array{return ['id'=>1];}
    public function getLocalization(int $siteId,string $languageCode):?array{return null;}
    public function getLanguages(?int $siteId=null):array{return [
        ['language_code'=>'fr','native_name'=>'Français','hreflang_code'=>'fr','url_prefix'=>''],
        ['language_code'=>'en','native_name'=>'English','hreflang_code'=>'en','url_prefix'=>'en'],
    ];}
    public function defaultLanguageCode():string{return 'fr';}
    public function menuItems(int $siteId,string $menuKey,string $languageCode):array{return [];}
});
$savedPrefixes=localized_path_prefixes();register_localized_path_prefixes($sitesProperty->getValue($routeWithoutDependencies)->getLanguages(1));
$hadSwitchEnv=array_key_exists('APP_BASE_PATH',$_ENV);$oldSwitchEnv=$_ENV['APP_BASE_PATH']??null;
$hadSwitchServer=array_key_exists('APP_BASE_PATH',$_SERVER);$oldSwitchServer=$_SERVER['APP_BASE_PATH']??null;
$oldSwitchProcess=getenv('APP_BASE_PATH');$_ENV['APP_BASE_PATH']='/cms';$_SERVER['APP_BASE_PATH']='/cms';putenv('APP_BASE_PATH=/cms');
$languageSwitch=$routeReflection->getMethod('languageSwitchItems');$languageSwitch->setAccessible(true);
$rootLanguages=$languageSwitch->invoke($routeWithoutDependencies,1,'en','/','https://example.test/cms');
$h->assertSame('/cms',$rootLanguages[0]['url']??null,'switching from a prefixed language to the default-language home avoids the installation trailing slash');
$h->assertSame('/cms/en',$rootLanguages[1]['url']??null,'the language switch keeps the configured prefix without a trailing home slash');
if($hadSwitchEnv)$_ENV['APP_BASE_PATH']=$oldSwitchEnv;else unset($_ENV['APP_BASE_PATH']);
if($hadSwitchServer)$_SERVER['APP_BASE_PATH']=$oldSwitchServer;else unset($_SERVER['APP_BASE_PATH']);
$oldSwitchProcess===false?putenv('APP_BASE_PATH'):putenv('APP_BASE_PATH='.$oldSwitchProcess);$GLOBALS['CMS_LOCALIZED_PATH_PREFIXES']=$savedPrefixes;
$placeShop=$routeReflection->getMethod('placeShopMenuItem');
$placeShop->setAccessible(true);
$existingItem=[['id'=>1,'label'=>'Existant','url'=>'/existing','sort_order'=>10]];
$shopMenu=['id'=>9,'menu_key'=>'footer_bottom','menu_label'=>'Boutique','menu_position'=>20];
$placed=$placeShop->invoke($routeWithoutDependencies,$existingItem,[],$existingItem,$existingItem,$shopMenu,'fr','/');
$h->assertSame(localized_path('/shop','fr'),$placed['footer_bottom'][1]['url']??null,'concrete footer-bottom placement injects the Shop into the rendered footer-bottom collection');
$h->assertSame(1,count($placed['primary']),'footer placement leaves primary navigation unchanged');
$shopMenu['menu_key']='footer';
$fallbackPlaced=$placeShop->invoke($routeWithoutDependencies,$existingItem,[],$existingItem,$existingItem,$shopMenu,'fr','/');
$h->assertSame(localized_path('/shop','fr'),$fallbackPlaced['footer_bottom'][1]['url']??null,'legacy generic footer placement selects an actually available footer-bottom collection');

$localizeItem=$routeReflection->getMethod('localizeStorefrontItem');
$localizeItem->setAccessible(true);
$localizedItem=$localizeItem->invoke($routeWithoutDependencies,['url'=>'/shop/products/demo','media'=>[['url'=>'/storage/media/demo.jpg']],'documents'=>[['url'=>'/storage/media/notice.pdf']],'sellables'=>[['media'=>[['url'=>'/storage/media/variant.jpg']]]],'relations'=>[['items'=>[['url'=>'/shop/products/related']]]]],'fr');
$h->assertTrue(($localizedItem['url']??null)===localized_path('/shop/products/demo','fr')&&($localizedItem['relations'][0]['items'][0]['url']??null)===localized_path('/shop/products/related','fr'),'product and related-product links include the installation and locale base path');
$h->assertTrue(($localizedItem['media'][0]['url']??null)===public_asset_url_path('/storage/media/demo.jpg')&&($localizedItem['sellables'][0]['media'][0]['url']??null)===public_asset_url_path('/storage/media/variant.jpg'),'product and variant media are localized at the public rendering boundary');
$h->assertSame(public_asset_url_path('/storage/media/notice.pdf'),$localizedItem['documents'][0]['url']??null,'public product documents include the installation directory');
$decorateCatalog=$routeReflection->getMethod('decorateCatalogPage');
$decorateCatalog->setAccessible(true);
$decorated=$decorateCatalog->invoke($routeWithoutDependencies,['selection'=>[],'facets'=>[],'pagination'=>[],'items'=>[['url'=>'/shop/products/demo']]],'/shop','fr');
$h->assertTrue(($decorated['current_url']??null)===localized_path('/shop','fr')&&str_starts_with((string)($decorated['items'][0]['url_with_return']??''),localized_path('/shop/products/demo','fr').'?return='),'catalog and product return links include the installation and locale base path');
$returnUrl=$routeReflection->getMethod('storefrontReturnUrl');
$returnUrl->setAccessible(true);
$publicReturn=localized_path('/shop?q=demo','fr');
$h->assertTrue($returnUrl->invoke($routeWithoutDependencies,'/shop?q=demo','fr')===$publicReturn&&$returnUrl->invoke($routeWithoutDependencies,$publicReturn,'fr')===$publicReturn,'product return navigation accepts both canonical and already-prefixed safe Shop URLs');

$renderer = new Renderer(dirname(__DIR__, 4) . '/frontend/theme-default/templates');
$missing = $product;
$missing['media']=[]; $missing['cta']=null; $missing['availability']=['display_status'=>'unavailable','label'=>'Indisponible','tone'=>'danger','is_orderable'=>false];
$card = $renderer->render('partials/storefront-product-card', ['item'=>$missing]);
$h->assertTrue(str_contains($card, 'Image indisponible'), 'canonical card exposes an explicit missing-media state');
$h->assertTrue(!str_contains($card, 'data-storefront-add-to-cart'), 'canonical card never renders an active CTA for an unorderable product');
$h->assertTrue(str_contains($card, 'Couleur') && str_contains($card, 'Bleu'), 'enriched card preview includes determining public attributes');
$english=$product; $english['locale']='en';
$frenchCard=$renderer->render('partials/storefront-product-card',['item'=>$product]);
$englishCard=$renderer->render('partials/storefront-product-card',['item'=>$english]);
$englishDetail=$renderer->render('storefront-product',['storefront_product'=>$english,'storefront_return_url'=>'/shop','canonical'=>'https://example.test/en/shop/products/produit-video']);
$h->assertTrue(str_contains($englishCard,'data-product-preview-trigger')&&str_contains($englishCard,'Close preview')&&!str_contains($englishCard,'Enriched preview'),'canonical card localizes its image/title preview interaction without a visible preview label');
$h->assertTrue(str_contains($englishCard,'data-stock-state="low"')&&str_contains($englishCard,'data-stock-state="unavailable"'),'canonical card exposes low and unavailable variant states without client-side stock inference');
$h->assertTrue(str_contains($englishCard,'data-variant-required="1"')&&str_contains($englishCard,'data-product-variant-choice')&&str_contains($englishCard,'aria-disabled="true" disabled'),'multi-variant cards require an explicit available option before cart addition');
$h->assertTrue(str_contains($frenchCard,"Sélectionnez une option disponible pour l'ajouter au panier.")&&str_contains($frenchCard,'data-variant-selection-status data-product-preview-trigger'),'multi-variant cards expose the required-choice explanation as an enriched-preview trigger');
$h->assertTrue(strpos($frenchCard,'data-variant-selection-status')<strpos($frenchCard,'class="btn btn--ghost"'),'the variant-selection prompt is rendered above the card action buttons');
$single=$english;$single['sellables']=[$english['sellables'][0]];$singleCard=$renderer->render('partials/storefront-product-card',['item'=>$single]);
$h->assertTrue(!str_contains($singleCard,'data-variant-required="1"')&&str_contains($singleCard,'data-sellable-id="101"'),'single-variant cards keep direct cart addition');
$service=$product;$service['type']='service';$service['commerce']['delivery_methods']=[['label'=>'Confirmation courriel','price_minor'=>0]];
$serviceCard=$renderer->render('partials/storefront-product-card',['item'=>$service]);
$serviceDetail=$renderer->render('storefront-product',['storefront_product'=>$service,'storefront_return_url'=>'/shop','canonical'=>'https://example.test/shop/products/service']);
$h->assertTrue(!str_contains($serviceCard,'Stock faible')&&!str_contains($serviceCard,'data-stock-state="low"'),'service cards expose only available or unavailable states without inventory language');
$h->assertTrue(str_contains($serviceDetail,'<h3>Remise</h3>')&&str_contains($serviceDetail,'Confirmation courriel'),'non-physical product details present fulfilment rather than physical delivery');
$h->assertTrue(str_contains($englishDetail,'Delivery and payment')&&str_contains($englishDetail,'Add to cart'),'canonical detail localizes its commercial controls from DTO locale');

exit($h->finish('UNIT storefront product rendering'));
