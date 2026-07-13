<?php

declare(strict_types=1);

namespace App\Application\Frontend;

use App\Core\Request;
use App\Core\Response;

final class PublicSaleCheckoutController
{
    public function __construct(private readonly Request $request) {}

    public function show(): Response
    {
        $channel = preg_match('/^[a-z0-9_-]+$/', (string) ($this->request->query['channel'] ?? 'web-main')) === 1
            ? (string) ($this->request->query['channel'] ?? 'web-main') : 'web-main';
        $token = preg_match('/^[A-Za-z0-9_-]{32,128}$/', (string) ($this->request->query['cart_token'] ?? '')) === 1
            ? (string) $this->request->query['cart_token'] : '';
        $lang = strtolower((string) ($this->request->query['lang'] ?? 'fr')) === 'en' ? 'en' : 'fr';
        $labels = $lang === 'en' ? [
            'title' => 'Guest checkout', 'identity' => 'Contact details', 'address' => 'Addresses', 'delivery' => 'Delivery and payment',
            'first' => 'First name', 'last' => 'Last name', 'email' => 'Email', 'phone' => 'Phone (optional)', 'line1' => 'Address',
            'postal' => 'Postal code', 'city' => 'City', 'country' => 'Country code', 'same' => 'Shipping address is the same',
            'terms' => 'I accept the terms and conditions', 'marketing' => 'I agree to receive marketing messages (optional)', 'submit' => 'Place order',
            'summary' => 'Order summary', 'loading' => 'Loading cart…', 'shipping' => 'Delivery', 'payment' => 'Payment',
        ] : [
            'title' => 'Commande invitée', 'identity' => 'Coordonnées', 'address' => 'Adresses', 'delivery' => 'Livraison et paiement',
            'first' => 'Prénom', 'last' => 'Nom', 'email' => 'E-mail', 'phone' => 'Téléphone (facultatif)', 'line1' => 'Adresse',
            'postal' => 'Code postal', 'city' => 'Ville', 'country' => 'Code pays', 'same' => 'L’adresse de livraison est identique',
            'terms' => 'J’accepte les conditions générales de vente', 'marketing' => 'J’accepte de recevoir des communications marketing (facultatif)', 'submit' => 'Commander',
            'summary' => 'Récapitulatif', 'loading' => 'Chargement du panier…', 'shipping' => 'Livraison', 'payment' => 'Paiement',
        ];
        $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $basePath = rtrim((string) \app_base_path(), '/');
        $html = '<!doctype html><html lang="' . $lang . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $e($labels['title']) . '</title><link rel="stylesheet" href="' . $e($basePath . '/frontend/theme-default/assets/css/checkout.css') . '"></head><body>'
            . '<main class="guest-checkout" data-checkout-root data-base-path="' . $e($basePath) . '" data-channel="' . $e($channel) . '" data-cart-token="' . $e($token) . '" data-lang="' . $lang . '">'
            . '<h1>' . $e($labels['title']) . '</h1><div class="checkout-grid"><form data-checkout-form novalidate>'
            . '<fieldset><legend>' . $e($labels['identity']) . '</legend>' . $this->input('first_name', $labels['first'], true) . $this->input('last_name', $labels['last'], true) . $this->input('email', $labels['email'], true, 'email') . $this->input('phone', $labels['phone']) . '</fieldset>'
            . '<fieldset><legend>' . $e($labels['address']) . '</legend>' . $this->input('line1', $labels['line1'], true) . $this->input('postal_code', $labels['postal'], true) . $this->input('city', $labels['city'], true) . $this->input('country_code', $labels['country'], true, 'text', 'CH')
            . '<label class="check"><input type="checkbox" name="shipping_same_as_billing" checked> ' . $e($labels['same']) . '</label></fieldset>'
            . '<fieldset><legend>' . $e($labels['delivery']) . '</legend><label>' . $e($labels['shipping']) . '<select name="shipping_method"><option value="standard">Standard</option><option value="pickup">Pickup</option></select></label>'
            . '<label>' . $e($labels['payment']) . '<select name="payment_method"><option value="bank_transfer">Virement bancaire</option><option value="manual">À confirmer</option></select></label>'
            . '<label class="check"><input type="checkbox" name="terms_accepted" required> ' . $e($labels['terms']) . '</label><label class="check"><input type="checkbox" name="marketing_consent"> ' . $e($labels['marketing']) . '</label></fieldset>'
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

    private function input(string $name, string $label, bool $required = false, string $type = 'text', string $value = ''): string
    {
        $e = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<label>' . $e($label) . '<input type="' . $type . '" name="' . $name . '" value="' . $e($value) . '"' . ($required ? ' required' : '') . '></label>';
    }
}
