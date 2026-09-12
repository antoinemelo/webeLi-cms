---
title: Cartes, fiches, variantes et produits liés — point 42
audience:
  - evaluator
status: stable
last_verified: 2026-07-17
source_of_truth: procedure
source_paths:
  - backend/src/Application/Business/StorefrontProjectionService.php
  - backend/src/Modules/Business/Services/CatalogCommercialRelationService.php
  - frontend/theme-default/templates/partials/storefront-product-card.twig
  - frontend/theme-default/templates/storefront-product.twig
  - frontend/theme-default/assets/js/storefront-product.js
  - frontend/admin-vue/src/views/modules/BusinessCatalogView.vue
  - database/migrations/business/0015_storefront_product_relations.sql
owners:
  - commerce
  - business
  - core
document_type: evaluation
generated: false
---
# Cartes, fiches, variantes et produits liés — point 42

## Conclusion contrôlée

La carte produit et la fiche automatique `/shop/products/{slug}` reposent sur un DTO public `storefront.product.v3` commun au SSR et au headless. Les variantes, prix, promotions, quatre états de disponibilité, médias, attributs publics, contenus CMS liés, méthodes réelles de livraison et de paiement, SEO et relations typées sont projetés dans Core. Les trois thèmes utilisent le même composant canonique surchargeable. Statut : **démontré** au niveau serveur, migration, build Vue, tests unitaires et gate Chromium ciblé. Confiance globale : **élevée**.

## Faits et preuves

| Conclusion | Statut | Preuve | Limite | Confiance |
|---|---|---|---|---|
| DTO produit v3 partagé SSR/headless | démontré | `StorefrontProjectionService`, 57 assertions de projection | compatibilité de clients tiers v1/v2 à gérer comme rupture de DTO | élevée |
| carte canonique avec résumé de 70 caractères Unicode | démontré | projection, partial partagé et assertion `mb_strlen` | perception visuelle selon polices non mesurée | élevée |
| quatre états publics et CTA absent si non commandable | démontré | projection et assertions négatives | dépend de la fraîcheur de la projection Inventory | élevée |
| fiche automatique sans page CMS par produit | démontré | résolution `/shop/products/{slug}` et template canonique | la configuration Shop doit être active | élevée |
| variante sans rechargement mettant à jour prix, SKU, média, disponibilité, délai, CTA et URL | démontré | JavaScript contrôlé, markup ARIA live et gate Chromium | lecteur d'écran réel non exécuté | élevée |
| image et vidéo, miniatures, textes alternatifs, captions et absence de média explicite | démontré | 17 assertions de rendu sur les trois thèmes et en FR/EN, plus gate Chromium général | lecture vidéo réelle non pilotée dans Chromium | élevée |
| livraison et paiement réels du canal | démontré | lecture des tables Sale actives, sans texte fictif | aucune méthode n'est affichée si la configuration Sale est vide | élevée |
| contenus CMS publiés liés | démontré | jointure vers les snapshots publics Core | blocs non publiés volontairement absents | élevée |
| relations manuelles typées, ordonnées et supprimables | démontré | service, API, administration Vue, tests unitaires | réordonnancement par valeur numérique, pas par glisser-déposer | élevée |
| règles automatiques explicites catégorie/groupe | démontré | table dédiée, UI et tests public/non-public | limite maximale de 24 cibles par règle | élevée |
| isolation site, exclusion du produit source et des cibles non publiques | démontré | validations de service et tests négatifs | cohérence intersite dépend aussi des identifiants Business propres à chaque site | élevée |
| canonical, hreflang et JSON-LD Product/Service | démontré | DTO SEO et adaptation du payload public | validation externe Schema.org non exécutée | élevée |
| projection ancienne signalée au lieu d'être présentée comme fraîche | démontré | horodatage DTO, état dynamique `delayed` et assertion dédiée | seuil conservateur fixe de 24 heures | élevée |

## Commandes exécutées le 2026-07-17

```text
php -l backend/src/Core/Renderer.php
php -l backend/src/Application/Business/StorefrontProjectionService.php
php -l backend/src/Application/Frontend/ResolvePublicRoute.php
résultat : aucune erreur de syntaxe

node --check frontend/theme-default/assets/js/storefront-product.js
résultat : succès

php tools/php/tests/unit/storefront_projection_test.php
[OK] UNIT storefront sellables and projections (57 assertions, dont changement de prix/disponibilité, projection retardée, CTA, langues et méthodes Sale réelles)

php tools/php/tests/unit/storefront_product_rendering_test.php
[OK] UNIT storefront product rendering 42 (17 assertions sur vidéo, captions, média absent, partage, FR/EN et CTA négatif dans les trois thèmes)

php tools/php/tests/unit/business_pricing_offers_bundles_test.php
[OK] UNIT business pricing offers and bundles (27 assertions, dont rejet intersite)

php tools/php/tests/unit/business_pim_lite_schema_test.php
[OK] UNIT business PIM-lite schema (79 assertions)

php tools/php/tests/unit/business_pim_lite_blueprints_test.php
[OK] UNIT business PIM-lite blueprints (538 assertions)

php tools/php/tests/unit/business_pim_api_controller_test.php
[OK] UNIT business PIM admin API (159 assertions, dont permissions et CRUD des relations/règles)

php tools/php/tests/integration/public_sale_http_test.php
[OK] INTEGRATION public Sale HTTP contracts and CORS (25 assertions)

npm --prefix frontend/admin-vue run build
succès : vue-tsc --noEmit, 249 modules transformés, build Vite produit

python3 tools/cms.py migrate --database business --plan
succès : migration 0015 seule en attente

python3 tools/cms.py migrate --database business --apply --backup --yes
succès : sauvegarde SQLite créée et migration 0015 appliquée

python3 tools/cms.py docs generate
succès : OpenAPI JSON/YAML et types TypeScript régénérés depuis les contrats source

python3 tools/cms.py validate --category database --category configuration --category content --category api --category documentation
succès : tous les validateurs demandés sont verts, dont 650 contrôles API ; le contrôle documentaire final compte 1 493 contrôles

python3 tools/cms.py docs check
succès : références générées à jour

php tools/php/tests/run.php
succès : suite fonctionnelle PHP complète ; quatre scénarios HTTP ignorés explicitement faute de port TCP dans le sandbox

python3 tools/cms.py e2e --use-built-assets --spec storefront-product-cards-details-relations-42.spec.ts
premier passage : échec utile, fermeture Escape absente des cartes catalogue car le script n'était chargé que sur la fiche
correction : chargement du comportement produit dans les layouts Storefront des trois thèmes
réexécution finale après localisation FR/EN : 1 scénario Chromium réussi en 49,5 s sur Default, Aurora et Pulse, avec ajout au panier depuis une carte puis depuis la fiche

python3 tools/cms.py migrate --database business --plan
succès après application : aucune migration restante

PRAGMA integrity_check sur storage/database/business.sqlite
résultat : ok

git diff --check
résultat : succès
```

## Contradictions et limites

- La version antérieure du contrat annonçait `storefront.product.v1` avec `version: 2`. Le runtime produit maintenant explicitement `storefront.product.v3`; la documentation OpenAPI a été alignée.
- Une classe, un template ou une route ne constituent pas seuls une preuve d'utilisabilité. Le comportement navigateur n'est marqué démontré qu'après le gate Playwright ciblé.
- Les relations automatiques ne sont pas des recommandations personnalisées : elles appliquent une règle déclarative de catégorie ou groupe, sans profilage client.
- Les cibles brouillon, archivées, privées, non e-commerce ou appartenant à un autre site ne sont pas projetées, même si une relation manuelle reste visible aux opérateurs.
- Firefox, WebKit et un lecteur d'écran réel ne sont pas couverts par le gate actuel.
