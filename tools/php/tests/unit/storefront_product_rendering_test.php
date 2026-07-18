<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestHarness.php';
require_once __DIR__ . '/../../../../backend/bootstrap/runtime.php';

use App\Core\Renderer;

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
    $grid=$renderer->render('partials/storefront-block',['block_type'=>'product_grid','data'=>['items'=>[$product],'columns'=>2,'show_price'=>false,'show_promotion'=>false,'show_availability'=>false,'show_cta'=>false,'selection'=>['missing_product_ids'=>[999],'pages'=>1]]]);
    $h->assertTrue(str_contains($grid,'data-product-id="42"')&&str_contains($grid,'--storefront-columns:2'),$theme.' renders projected Commerce blocks with their configured layout');
    $h->assertTrue(!str_contains($grid,'99.00')&&!str_contains($grid,'data-storefront-add-to-cart')&&!str_contains($grid,'Disponible'),$theme.' respects card visibility options');
    $redirect=$renderer->render('partials/storefront-block',['block_type'=>'add_to_cart','data'=>['product'=>$product,'requires_variant_choice'=>true,'label'=>'Choisir']]);
    $h->assertTrue(str_contains($redirect,'href="/shop/products/produit-video"')&&!str_contains($redirect,'data-storefront-add-to-cart'),$theme.' redirects ambiguous variant selection to the product detail');
    $direct=$renderer->render('partials/storefront-block',['block_type'=>'add_to_cart','data'=>['resolved'=>['product'=>$product,'sellable'=>$product['sellables'][0]],'quantity'=>2,'label'=>'Ajouter']]);
    $h->assertTrue(str_contains($direct,'data-sellable-id="101"')&&str_contains($direct,'data-quantity="2"'),$theme.' uses the shared Sale cart contract for an explicit sellable');
    $empty=$renderer->render('partials/storefront-block',['block_type'=>'product_grid','data'=>['items'=>[],'empty_state'=>'message','empty_message'=>'Sélection indisponible','selection'=>['missing_product_ids'=>[],'pages'=>0]]]);
    $h->assertTrue(str_contains($empty,'Sélection indisponible')&&!str_contains($empty,'storefront_product_projections'),$theme.' renders a configured empty state without technical leakage');
}

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

exit($h->finish('UNIT storefront product rendering 42'));
