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
        $e=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $html='<!doctype html><html lang="'.$e($lang).'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,follow"><title>Panier</title>'
            .'<link rel="stylesheet" href="'.$e($base.'/frontend/theme-default/assets/css/cart.css').'"></head><body data-storefront-channel="'.$e($channel).'"><main class="cart-page" data-cart-page aria-labelledby="cart-title"><h1 id="cart-title">Votre panier</h1><div data-cart-status role="status" aria-live="polite"></div><div data-cart-error role="alert" aria-live="assertive"></div><div data-cart-lines aria-live="polite">Chargement…</div><div data-cart-summary></div><div data-cart-total></div><a class="cart-checkout" data-cart-checkout href="'.$e($base.'/checkout?channel='.rawurlencode($channel).'&lang='.rawurlencode($lang)).'">Passer la commande</a></main><script src="'.$e($base.'/frontend/theme-default/assets/js/storefront-cart.js').'" defer></script></body></html>';
        return Response::html($html,200,['Cache-Control'=>'no-store, private','X-Robots-Tag'=>'noindex,follow']);
    }
}
