<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Core\Renderer;
use App\Application\Frontend\ResolvePublicRoute;

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
    'sellables'=>[
        ['sellable_id'=>101,'variant_id'=>101,'sku'=>'VIDEO-BLUE','name'=>'Bleu','options'=>[],'price'=>['regular_minor'=>12900,'final_minor'=>9900,'currency'=>'CHF','discount_percent_bps'=>2326],'availability'=>['display_status'=>'available','label'=>'Disponible','tone'=>'success','lead_time_days'=>0],'media'=>[['type'=>'video','url'=>'/media/demo.mp4','alt'=>'Démonstration','caption'=>'Variante bleue']],'orderable'=>true],
        ['sellable_id'=>102,'variant_id'=>102,'sku'=>'VIDEO-RED','name'=>'Rouge','options'=>[],'price'=>['regular_minor'=>12900,'final_minor'=>12900,'currency'=>'CHF','discount_percent_bps'=>0],'availability'=>['display_status'=>'unavailable','label'=>'Indisponible','tone'=>'danger','lead_time_days'=>0],'media'=>[],'orderable'=>false],
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
    $h->assertTrue(str_contains($html, 'data-product-variant'), $theme . ' renders accessible variant selection');
    $h->assertTrue(str_contains($html, 'LinkedIn') && str_contains($html, 'Facebook') && str_contains($html, 'mailto:'), $theme . ' renders social and email sharing');
    $h->assertTrue(str_contains($html,'class="social-share__action social-share__action--linkedin"')&&str_contains($html,'data-copy-url=')&&!str_contains($html,'data-copy-product-url'),$theme.' reuses the page and article sharing component');
    $grid=$renderer->render('partials/storefront-block',['block_type'=>'commerce_product_list','data'=>['commerce_available'=>true,'items'=>[$product],'columns'=>2,'show_price'=>false,'show_promotion'=>false,'show_availability'=>false,'show_cta'=>false,'selection'=>['missing_product_ids'=>[999],'pages'=>1]]]);
    $h->assertTrue(str_contains($grid,'data-product-id="42"')&&str_contains($grid,'--storefront-columns:2'),$theme.' renders projected Commerce blocks with their configured layout');
    $h->assertTrue(!str_contains($grid,'99.00')&&!str_contains($grid,'data-storefront-add-to-cart')&&!str_contains($grid,'Disponible'),$theme.' respects card visibility options');
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
}

$cartCss=(string)file_get_contents(dirname(__DIR__,4).'/frontend/theme-default/assets/css/cart.css');
$h->assertTrue(!str_contains($cartCss,'.storefront-cart-toggle{position:fixed')&&str_contains($cartCss,'.storefront-cart-toggle .bi-bag'),'cart styles keep the bag icon in navigation flow rather than fixing it at page bottom');
$cartJs=(string)file_get_contents(dirname(__DIR__,4).'/frontend/theme-default/assets/js/storefront-cart.js');
$h->assertTrue(str_contains($cartJs,'dataset.storefrontApiBase')&&str_contains($cartJs,'const endpoint = (path) => `${apiBase.replace'),'cart requests use the server-provided API base instead of relying on script-path detection');

$routeReflection=new ReflectionClass(ResolvePublicRoute::class);
$routeWithoutDependencies=$routeReflection->newInstanceWithoutConstructor();
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
$localizedItem=$localizeItem->invoke($routeWithoutDependencies,['url'=>'/shop/products/demo','relations'=>[['items'=>[['url'=>'/shop/products/related']]]]],'fr');
$h->assertTrue(($localizedItem['url']??null)===localized_path('/shop/products/demo','fr')&&($localizedItem['relations'][0]['items'][0]['url']??null)===localized_path('/shop/products/related','fr'),'product and related-product links include the installation and locale base path');
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
$englishCard=$renderer->render('partials/storefront-product-card',['item'=>$english]);
$englishDetail=$renderer->render('storefront-product',['storefront_product'=>$english,'storefront_return_url'=>'/shop','canonical'=>'https://example.test/en/shop/products/produit-video']);
$h->assertTrue(str_contains($englishCard,'Enriched preview')&&str_contains($englishCard,'View full details'),'canonical card localizes its interaction labels from DTO locale');
$h->assertTrue(str_contains($englishDetail,'Delivery and payment')&&str_contains($englishDetail,'Add to cart'),'canonical detail localizes its commercial controls from DTO locale');

exit($h->finish('UNIT storefront product rendering'));
