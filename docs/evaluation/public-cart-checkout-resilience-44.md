---
title: Panier, tiroir et checkout résilients — point 44
audience:
  - evaluator
status: stable
last_verified: 2026-07-17
source_of_truth: procedure
source_paths:
  - backend/src/Application/PublicApi/PublicSaleApiHandler.php
  - backend/src/Modules/Sale/Services/SaleCartService.php
  - frontend/theme-default/assets/js/storefront-cart.js
  - frontend/theme-default/assets/js/guest-checkout.js
  - frontend/admin-vue/tests/e2e/public-cart-checkout-resilience-44.spec.ts
owners:
  - commerce
  - sale
  - storefront
document_type: evaluation
generated: false
---
# Panier, tiroir et checkout résilients — point 44

## Conclusion contrôlée

Le panier public, le tiroir, la page `/cart` et le checkout invité utilisent le même agrégat `sale_carts` et les mêmes routes Sale. Aucun panier ni aucune commande parallèle n'a été ajouté. Leur exposition dépend désormais explicitement de la configuration Shop active pour le site, la langue et le canal ; un canal Sale public ne suffit plus à lui seul.

Statut global : **démontré** pour le jeton opaque haché et expirant, le verrouillage optimiste, le conflit multi-onglets, la reprise du brouillon après rafraîchissement, l'idempotence du placement et le refus hors langue Shop active. **Partiellement démontré** pour l'ensemble de la matrice paiement différé et reprise fournisseur : les contrats et tests métier préexistants la couvrent, mais le gate Chromium du point 44 ne rejoue pas tous les fournisseurs ni toutes les expirations. Confiance : **élevée** sur le panier et le checkout invité standard, **moyenne à élevée** sur les variantes « sur commande ».

## Comportements vérifiés

| Conclusion | Statut | Preuve | Limite | Confiance |
|---|---|---|---|---|
| exposition liée au Shop actif | démontré | contrôleurs SSR et `PublicSaleApiHandler`; scénario hors langue active à 404 | désactivation concurrente pendant une requête non simulée | élevée |
| jeton opaque, haché, expirant et révocable | démontré | `SaleCartRepository`, API d'abandon, 84 assertions API publique | rotation périodique d'un panier actif non implémentée | élevée |
| même panier depuis Shop, fiche et bloc Studio | démontré | attribut canonique `data-storefront-add-to-cart`; gates 42 et 43 | thème actif seul parcouru jusqu'au checkout | élevée |
| verrouillage optimiste multi-onglets | démontré | `expected_version`, réponse 409, gate Chromium avant/après | pas de BroadcastChannel, synchronisation par événement storage et relecture | élevée |
| prix et disponibilité revalidés à la lecture | démontré côté serveur | `SaleCartService::revalidateForDisplay`, changements structurés avant/après | modification de prix réelle non rejouée dans Chromium | élevée |
| tiroir accessible et mobile | démontré | dialogue modal, focus piégé, Échap, restauration du focus, contrôle sans débordement | lecteur d'écran réel non exécuté | élevée |
| checkout invité repris après rafraîchissement | démontré | brouillon `sessionStorage` borné au jeton et scénario Chromium | reprise inter-appareils absente par conception pour un invité | élevée |
| double soumission empêchée | démontré | bouton désactivé, même clé d'idempotence conservée, une réponse 201 | crash entre conversion et rendu couvert côté serveur, non forcé dans Chromium | élevée |
| choix « payer maintenant / lorsque disponible » | partiellement démontré | bootstrap, politique visible pour backorder, `SaleDeferredPaymentService` et tests dossier | gate 44 ne rejoue pas dépôt, autorisation expirée et prix recalculable | moyenne |
| refus fournisseur récupérable | démontré par gates préexistantes | `payment-provider-interchangeability.spec.ts`, retry avec nouvelle clé | non rejoué pendant le gate ciblé 44 | moyenne à élevée |

## Décisions de conception

- La clé locale est uniquement `amcms.cart.{channel}`. Le checkout n'utilise plus l'ancien alias divergent.
- Le jeton brut reste dans le navigateur ; seule son empreinte SHA-256 est persistée. Aucun identifiant interne ou contenu client n'est ajouté à la télémétrie.
- Une revalidation identique ne modifie pas la version. Si prix ou disponibilité changent, l'API incrémente la version et retourne la ligne, l'avant, l'après, le besoin éventuel de confirmation et les options de reprise.
- Un conflit 409 ne vide jamais le panier : le client recharge la version courante, nomme la ligne concernée et conserve les actions quantité/suppression.
- Le brouillon checkout reste en `sessionStorage`, isolé par canal et jeton. La même clé d'idempotence est réutilisée après rafraîchissement ; une nouvelle clé n'est créée que pour une nouvelle tentative de paiement explicite.
- L'achat « lorsque disponible » indique le délai, le prix figé, l'absence de facture finale avant paiement, la fenêtre de paiement et exige un consentement dédié.

## Commandes exécutées le 2026-07-17

```text
php -l backend/src/Application/PublicApi/PublicSaleApiHandler.php
php -l backend/src/Application/Frontend/PublicStorefrontCartController.php
php -l backend/src/Application/Frontend/PublicSaleCheckoutController.php
php -l backend/src/Core/App.php
node --check frontend/theme-default/assets/js/storefront-cart.js
node --check frontend/theme-default/assets/js/guest-checkout.js
résultat : aucune erreur de syntaxe

php tools/php/tests/unit/sale_cart_aggregate_contract_test.php
[OK] 24 assertions

php tools/php/tests/unit/sale_public_api_handler_test.php
[OK] 84 assertions

php tools/php/tests/unit/storefront_projection_test.php
[OK] 90 assertions

php tools/php/tests/unit/sale_module_contracts_test.php
[OK] 1 150 assertions

php tools/php/tests/integration/public_sale_http_test.php
[OK] 25 assertions HTTP et CORS

python3 tools/cms.py e2e --use-built-assets --spec tests/e2e/public-cart-checkout-resilience-44.spec.ts
premiers passages : échecs de fixture utiles (projections non reconstruites, libellés de livraison ambigus, produit cadeau incompatible avec livraison standard) ; gate corrigé pour reconstruire la projection et choisir un produit physique
passage final : 3 scénarios Chromium réussis en 44,3 s

python3 tools/cms.py e2e --use-built-assets --spec tests/e2e/payment-provider-interchangeability.spec.ts
premier passage de non-régression : échec anglais, la fixture isolée n'activait réellement que le Shop français
fixture corrigée sans assouplir le contrôle produit ; passage final : 2 scénarios réussis en 21,1 s, Stripe/Revolut, desktop/mobile, FR/EN et refus récupérable

python3 tools/cms.py e2e --use-built-assets --spec tests/e2e/public-guest-checkout.spec.ts --spec tests/e2e/payment-provider-interchangeability.spec.ts --spec tests/e2e/storefront-projections.spec.ts --spec tests/e2e/studio-commerce-blocks-43.spec.ts
résultat : 5 scénarios réussis sur 6 ; seul le checkout anglais a révélé la fixture monolingue ci-dessus, puis a été rejoué avec succès après correction

python3 -m unittest tools.python.tests.test_e2e_harness
résultat final : 6 tests réussis

php tools/php/tests/run.php
résultat : suite fonctionnelle PHP complète verte ; quatre intégrations HTTP ignorées faute de bind TCP dans ce passage, dont `public_sale_http_test.php` exécuté séparément avec 25 assertions réussies

python3 tools/cms.py validate --category configuration --category content --category api --category documentation
résultat : succès, dont 7 296 contrôles i18n, 652 contrôles API et 1 505 contrôles documentaires

git diff --check
résultat : succès
```

## Limites et preuves encore nécessaires

- rejouer Firefox, WebKit et un lecteur d'écran réel ;
- simuler dans un gate unique la hausse et la baisse de prix, la fin de promotion, le dernier article, l'expiration de réservation et du jeton ;
- parcourir en Chromium le paiement différé avec prix figé puis recalculable, dépôt, disponibilité ultérieure, expiration de l'autorisation et paiement final ;
- rejouer deux sites et deux langues actives dans la même exécution, et pas seulement une langue active plus une langue refusée ;
- tester la reprise de confirmation après fermeture complète du navigateur pour chaque fournisseur réel configuré.
