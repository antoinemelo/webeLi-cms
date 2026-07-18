<?php

declare(strict_types=1);

namespace App\Application\Frontend;

use App\Application\Business\StorefrontProjectionRepository;
use App\Core\Request;
use App\Core\Response;
use App\Repository\SiteRepository;

final class PublicSaleCheckoutController
{
    public function __construct(
        private readonly Request $request,
        private readonly ?SiteRepository $sites = null,
        private readonly ?StorefrontProjectionRepository $storefront = null,
    ) {}

    public function show(): Response
    {
        $channel = preg_match('/^[a-z0-9_-]+$/', (string) ($this->request->query['channel'] ?? 'web-main')) === 1
            ? (string) ($this->request->query['channel'] ?? 'web-main') : 'web-main';
        $token = preg_match('/^[A-Za-z0-9_-]{32,128}$/', (string) ($this->request->query['cart_token'] ?? '')) === 1
            ? (string) $this->request->query['cart_token'] : '';
        $lang = strtolower((string) ($this->request->query['lang'] ?? 'fr')) === 'en' ? 'en' : 'fr';
        if ($this->sites !== null && $this->storefront !== null) {
            $site = $this->sites->resolveCurrentSite((string) ($this->request->server['HTTP_HOST'] ?? ''));
            $lang = strtolower((string) ($this->request->query['lang'] ?? $site['default_language_code'] ?? 'fr'));
            $shop = $this->storefront->activeShop((int) $site['id'], $lang);
            if ($shop === null || empty($shop['cart_visible']) || (string) $shop['channel_code'] !== $channel) {
                return Response::html('<!doctype html><html lang="'.$lang.'"><meta name="robots" content="noindex,nofollow"><title>Checkout indisponible</title><h1>Checkout indisponible</h1>', 404, ['Cache-Control'=>'no-store, private']);
            }
        }
        $labels = $lang === 'en' ? [
            'title' => 'Guest checkout', 'identity' => 'Contact details', 'address' => 'Addresses', 'delivery' => 'Delivery and payment',
            'first' => 'First name', 'last' => 'Last name', 'email' => 'Email', 'phone' => 'Phone (optional)', 'line1' => 'Address',
            'postal' => 'Postal code', 'city' => 'City', 'country' => 'Country code', 'same' => 'Shipping address is the same',
            'terms' => 'I accept the terms and conditions', 'marketing' => 'I agree to receive marketing messages (optional)', 'submit' => 'Place order',
            'summary' => 'Order summary', 'loading' => 'Loading cart…', 'shipping' => 'Delivery', 'payment' => 'Payment', 'consents' => 'Review and consents',
            'shipping_line1' => 'Shipping address', 'shipping_postal' => 'Delivery ZIP', 'shipping_city' => 'Delivery locality', 'shipping_country' => 'Delivery country',
            'gift_card' => 'Gift card (optional)', 'gift_apply' => 'Apply',
        ] : [
            'title' => 'Commande invitée', 'identity' => 'Coordonnées', 'address' => 'Adresses', 'delivery' => 'Livraison et paiement',
            'first' => 'Prénom', 'last' => 'Nom', 'email' => 'E-mail', 'phone' => 'Téléphone (facultatif)', 'line1' => 'Adresse',
            'postal' => 'Code postal', 'city' => 'Ville', 'country' => 'Code pays', 'same' => 'L’adresse de livraison est identique',
            'terms' => 'J’accepte les conditions générales de vente', 'marketing' => 'J’accepte de recevoir des communications marketing (facultatif)', 'submit' => 'Commander',
            'summary' => 'Récapitulatif', 'loading' => 'Chargement du panier…', 'shipping' => 'Livraison', 'payment' => 'Paiement', 'consents' => 'Validation et consentements',
            'shipping_line1' => 'Adresse de livraison', 'shipping_postal' => 'NPA de livraison', 'shipping_city' => 'Localité de livraison', 'shipping_country' => 'Pays de livraison',
            'gift_card' => 'Bon cadeau (facultatif)', 'gift_apply' => 'Appliquer',
        ];
        $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $basePath = rtrim((string) \app_base_path(), '/');
        $html = '<!doctype html><html lang="' . $lang . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $e($labels['title']) . '</title><link rel="stylesheet" href="' . $e($basePath . '/frontend/theme-default/assets/css/checkout.css') . '"></head><body>'
            . '<main class="guest-checkout" data-checkout-root data-base-path="' . $e($basePath) . '" data-channel="' . $e($channel) . '" data-cart-token="' . $e($token) . '" data-lang="' . $lang . '">'
            . '<h1>' . $e($labels['title']) . '</h1><ol class="checkout-progress" aria-label="Progression"><li>1. '.$e($labels['identity']).'</li><li>2. '.$e($labels['address']).'</li><li>3. '.$e($labels['shipping']).'</li><li>4. '.$e($labels['payment']).'</li><li>5. '.$e($labels['summary']).'</li></ol><div class="checkout-grid"><form data-checkout-form novalidate>'
            . '<fieldset><legend>' . $e($labels['identity']) . '</legend>' . $this->input('first_name', $labels['first'], true) . $this->input('last_name', $labels['last'], true) . $this->input('email', $labels['email'], true, 'email') . $this->input('phone', $labels['phone']) . '</fieldset>'
            . '<fieldset><legend>' . $e($labels['address']) . '</legend>' . $this->input('line1', $labels['line1'], true) . $this->input('postal_code', $labels['postal'], true) . $this->input('city', $labels['city'], true) . $this->input('country_code', $labels['country'], true, 'text', 'CH')
            . '<label class="check"><input type="checkbox" name="shipping_same_as_billing" checked> ' . $e($labels['same']) . '</label><div class="shipping-address" data-shipping-address hidden>'.$this->input('shipping_line1', $labels['shipping_line1']).$this->input('shipping_postal_code', $labels['shipping_postal']).$this->input('shipping_city', $labels['shipping_city']).$this->input('shipping_country_code', $labels['shipping_country'], false, 'text', 'CH').'</div></fieldset>'
            . '<fieldset><legend>' . $e($labels['shipping']) . '</legend><label>' . $e($labels['shipping']) . '<select name="shipping_method"><option value="standard">Standard</option><option value="pickup">Pickup</option></select></label><div data-order-policy></div></fieldset>'
            . '<fieldset><legend>' . $e($labels['payment']) . '</legend><div class="checkout-gift-card"><label>' . $e($labels['gift_card']) . '<input type="text" name="gift_card_code" autocomplete="off" autocapitalize="characters" spellcheck="false" data-sensitive></label><button type="button" data-gift-card-apply>' . $e($labels['gift_apply']) . '</button><p data-gift-card-status role="status" aria-live="polite"></p></div><label>' . $e($labels['payment']) . '<select name="payment_method"><option value="bank_transfer">Virement bancaire</option><option value="manual">À confirmer</option></select></label></fieldset>'
            . '<fieldset><legend>' . $e($labels['consents']) . '</legend><label class="check"><input type="checkbox" name="terms_accepted" required> ' . $e($labels['terms']) . '</label><label class="check"><input type="checkbox" name="marketing_consent"> ' . $e($labels['marketing']) . '</label></fieldset>'
            . '<div class="checkout-error" data-checkout-error role="alert" aria-live="polite"></div><button type="submit">' . $e($labels['submit']) . '</button></form>'
            . '<aside aria-labelledby="summary-title"><h2 id="summary-title">' . $e($labels['summary']) . '</h2><div data-checkout-summary>' . $e($labels['loading']) . '</div></aside></div></main>'
            . '<script src="' . $e($basePath . '/frontend/theme-default/assets/js/guest-checkout.js') . '" defer></script></body></html>';
        return Response::html($html, 200, ['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex,nofollow']);
    }

    public function confirmation(): Response
    {
        $provider = preg_match('/^[a-z0-9_-]+$/', (string)($this->request->query['provider'] ?? '')) === 1 ? (string)$this->request->query['provider'] : '';
        $reference = preg_match('/^[A-Za-z0-9_-]{6,255}$/', (string)($this->request->query['reference'] ?? '')) === 1 ? (string)$this->request->query['reference'] : '';
        $lang = strtolower((string)($this->request->query['lang'] ?? 'fr')) === 'en' ? 'en' : 'fr';
        $basePath = rtrim((string)\app_base_path(), '/');
        $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title = $lang === 'en' ? 'Payment confirmation' : 'Confirmation du paiement';
        $waiting = $lang === 'en' ? 'Checking the server confirmation…' : 'Vérification de la confirmation serveur…';
        $html='<!doctype html><html lang="'.$lang.'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>'.$e($title).'</title><link rel="stylesheet" href="'.$e($basePath.'/frontend/theme-default/assets/css/checkout.css').'"></head><body><main class="guest-checkout" data-payment-return data-base-path="'.$e($basePath).'" data-provider="'.$e($provider).'" data-reference="'.$e($reference).'" data-lang="'.$lang.'"><h1>'.$e($title).'</h1><p aria-live="polite" role="status" data-payment-return-state>'.$e($waiting).'</p><a href="'.$e($basePath.'/shop').'">'.($lang==='en'?'Back to shop':'Retour à la boutique').'</a></main><script src="'.$e($basePath.'/frontend/theme-default/assets/js/payment-return.js').'" defer></script></body></html>';
        return Response::html($html,200,['Cache-Control'=>'no-store, private','X-Robots-Tag'=>'noindex,nofollow']);
    }

    public function giftCard(): Response
    {
        $lang=strtolower((string)($this->request->query['lang']??'fr'))==='en'?'en':'fr';
        $channel=preg_match('/^[a-z0-9_-]+$/',(string)($this->request->query['channel']??'web-main'))===1?(string)($this->request->query['channel']??'web-main'):'web-main';
        $basePath=rtrim((string)\app_base_path(),'/');$e=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $title=$lang==='en'?'Your gift card':'Votre bon cadeau';$waiting=$lang==='en'?'Use the secure link received by email to display the code once.':'Utilisez le lien sécurisé reçu par e-mail pour afficher le code une seule fois.';
        return Response::html('<!doctype html><html lang="'.$lang.'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>'.$e($title).'</title><link rel="stylesheet" href="'.$e($basePath.'/frontend/theme-default/assets/css/checkout.css').'"></head><body><main class="guest-checkout" data-gift-card-claim data-base-path="'.$e($basePath).'" data-channel="'.$e($channel).'" data-lang="'.$lang.'"><h1>'.$e($title).'</h1><p role="status" aria-live="polite" data-gift-card-claim-state>'.$e($waiting).'</p><output data-gift-card-code></output></main><script src="'.$e($basePath.'/frontend/theme-default/assets/js/gift-card-claim.js').'" defer></script></body></html>',200,['Cache-Control'=>'no-store, private','X-Robots-Tag'=>'noindex,nofollow','Referrer-Policy'=>'no-referrer']);
    }

    private function input(string $name, string $label, bool $required = false, string $type = 'text', string $value = ''): string
    {
        $e = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<label>' . $e($label) . '<input type="' . $type . '" name="' . $name . '" value="' . $e($value) . '"' . ($required ? ' required' : '') . '></label>';
    }
}
