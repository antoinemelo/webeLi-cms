<?php

declare(strict_types=1);

namespace App\Application\Frontend;

use App\Core\Response;

final class PublicCustomerAccountController
{
    public function show(): Response
    {
        $base = htmlspecialchars(rtrim((string) \app_base_path(), '/'), ENT_QUOTES, 'UTF-8');
        $html = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Mon compte</title><link rel="stylesheet" href="' . $base . '/frontend/theme-default/assets/css/customer-account.css"></head><body>'
            . '<main class="customer-account" data-customer-account data-base-path="' . $base . '"><header><h1>Mon compte</h1><button type="button" data-logout hidden>Déconnexion</button></header>'
            . '<p class="account-error" data-error role="alert" aria-live="polite"></p>'
            . '<form data-login><h2>Connexion</h2><label>E-mail<input name="email" type="email" required autocomplete="email"></label><label>Mot de passe<input name="password" type="password" required autocomplete="current-password"></label><button>Se connecter</button></form>'
            . '<section data-dashboard hidden><nav><button type="button" data-tab="orders">Commandes</button><button type="button" data-tab="addresses">Adresses</button><button type="button" data-tab="profile">Profil</button></nav>'
            . '<section data-panel="orders"><h2>Mes commandes</h2><div data-orders></div><article data-order-detail></article></section>'
            . '<section data-panel="addresses" hidden><h2>Mes adresses</h2><div data-addresses></div><form data-address-form><label>Libellé<input name="label" required></label><label>Adresse<input name="line1" required></label><label>Code postal<input name="postal_code" required></label><label>Ville<input name="city" required></label><label>Pays<input name="country_code" value="CH" required></label><button>Enregistrer</button></form></section>'
            . '<section data-panel="profile" hidden><h2>Mon profil</h2><form data-profile-form><label>Prénom<input name="first_name"></label><label>Nom<input name="last_name"></label><label>Langue<input name="locale" value="fr-CH"></label><button>Mettre à jour</button></form></section></section></main>'
            . '<script src="' . $base . '/frontend/theme-default/assets/js/customer-account.js" defer></script></body></html>';
        return Response::html($html, 200, ['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex,nofollow']);
    }
}
