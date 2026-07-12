<?php
declare(strict_types=1);
namespace App\Application\Frontend;
use App\Core\Response;

final class PublicStorefrontCartController
{
    public function show(): Response
    {
        $base=rtrim((string)app_base_path(),'/');
        $e=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $html='<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,follow"><title>Panier</title>'
            .'<link rel="stylesheet" href="'.$e($base.'/frontend/theme-default/assets/css/cart.css').'"></head><body data-storefront-channel="web-main"><main class="cart-page" data-cart-page aria-labelledby="cart-title"><h1 id="cart-title">Votre panier</h1><div data-cart-error role="alert" aria-live="assertive"></div><div data-cart-lines aria-live="polite">Chargement…</div><div data-cart-total></div><a class="cart-checkout" data-cart-checkout href="'.$e($base.'/checkout?channel=web-main').'">Passer la commande</a></main><script src="'.$e($base.'/frontend/theme-default/assets/js/storefront-cart.js').'" defer></script></body></html>';
        return Response::html($html,200,['Cache-Control'=>'no-store, private','X-Robots-Tag'=>'noindex,follow']);
    }
}
