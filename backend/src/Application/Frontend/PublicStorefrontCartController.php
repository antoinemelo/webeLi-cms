<?php
declare(strict_types=1);
namespace App\Application\Frontend;
use App\Application\Business\StorefrontProjectionRepository;
use App\Core\Request;
use App\Core\Response;
use App\Repository\SiteRepository;

final class PublicStorefrontCartController
{
    public function __construct(
        private readonly ?Request $request = null,
        private readonly ?SiteRepository $sites = null,
        private readonly ?StorefrontProjectionRepository $storefront = null,
    ) {}

    public function show(): Response
    {
        $lang = strtolower((string) ($this->request?->query['lang'] ?? 'fr')) === 'en' ? 'en' : 'fr';
        $channel = 'web-main';
        if ($this->sites !== null && $this->storefront !== null && $this->request !== null) {
            $site = $this->sites->resolveCurrentSite((string) ($this->request->server['HTTP_HOST'] ?? ''));
            $lang = strtolower((string) ($this->request->query['lang'] ?? $site['default_language_code'] ?? 'fr'));
            $shop = $this->storefront->activeShop((int) $site['id'], $lang);
            if ($shop === null || empty($shop['cart_visible'])) {
                return Response::html('<!doctype html><html lang="'.$lang.'"><meta name="robots" content="noindex,nofollow"><title>Panier indisponible</title><h1>Panier indisponible</h1>', 404, ['Cache-Control'=>'no-store, private']);
            }
            $channel = (string) $shop['channel_code'];
        }
        $base=rtrim((string)app_base_path(),'/');
        $apiBase=public_api_url_path('sale/channels/'.$channel);
        $cartUrl=localized_path('/cart',$lang);
        $checkoutUrl=localized_path('/checkout',$lang);
        $shopUrl=localized_path('/shop',$lang);
        $en=str_starts_with($lang,'en');
        $labels=$en?[
            'title'=>'Your cart','eyebrow'=>'Your selection','back'=>'Continue shopping','items'=>'Items',
            'summary'=>'Order summary','checkout'=>'Checkout','secure'=>'Secure checkout · cart saved on this device',
        ]:[
            'title'=>'Votre panier','eyebrow'=>'Votre sélection','back'=>'Continuer mes achats','items'=>'Articles',
            'summary'=>'Récapitulatif','checkout'=>'Commander','secure'=>'Paiement sécurisé · panier conservé sur cet appareil',
        ];
        $e=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $html='<!doctype html><html lang="'.$e($lang).'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,follow"><title>'.$e($labels['title']).'</title>'
            .'<link rel="stylesheet" href="'.$e($base.'/frontend/theme-default/assets/css/cart.css').'"></head><body class="cart-standalone" data-storefront-channel="'.$e($channel).'" data-storefront-api-base="'.$e($apiBase).'" data-storefront-cart-url="'.$e($cartUrl).'" data-storefront-checkout-url="'.$e($checkoutUrl).'" data-app-base-path="'.$e(url_path('/')).'">'
            .'<main class="cart-page" data-cart-page aria-labelledby="cart-title">'
            .'<header class="cart-page__header"><div><p>'.$e($labels['eyebrow']).'</p><h1 id="cart-title">'.$e($labels['title']).'</h1></div><a class="cart-page__back" href="'.$e($shopUrl).'"><span aria-hidden="true">←</span>'.$e($labels['back']).'</a></header>'
            .'<div data-cart-error role="alert" aria-live="assertive"></div>'
            .'<div class="cart-page__layout"><section class="cart-page__items" aria-labelledby="cart-items-title"><div class="cart-page__section-heading"><h2 id="cart-items-title">'.$e($labels['items']).'</h2><span data-cart-item-count></span></div><div data-cart-lines aria-live="polite"></div></section>'
            .'<aside class="cart-page__summary" aria-labelledby="cart-summary-title"><h2 id="cart-summary-title">'.$e($labels['summary']).'</h2><div data-cart-summary></div><div class="cart-total" data-cart-total></div><a class="cart-checkout" data-cart-checkout href="'.$e($checkoutUrl.'?channel='.rawurlencode($channel).'&lang='.rawurlencode($lang)).'">'.$e($labels['checkout']).'</a><p class="cart-reassurance"><span aria-hidden="true">⌁</span>'.$e($labels['secure']).'</p></aside></div></main>'
            .'<script src="'.$e($base.'/frontend/theme-default/assets/js/storefront-cart.js').'" defer></script></body></html>';
        return Response::html($html,200,['Cache-Control'=>'no-store, private','X-Robots-Tag'=>'noindex,follow']);
    }
}
