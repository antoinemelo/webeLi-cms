---
title: Catalogue public, facettes, recherche et tris — point 40
audience:
  - evaluator
status: stable
last_verified: 2026-07-17
source_of_truth: procedure
source_paths:
  - backend/src/Application/Business/StorefrontProjectionService.php
  - backend/src/Application/Business/StorefrontProjectionRepository.php
  - backend/src/Application/PublicApi/PublicCatalogApiHandler.php
  - backend/src/Application/Frontend/ResolvePublicRoute.php
  - database/schema/core.sql
  - tools/php/tests/unit/storefront_projection_test.php
owners:
  - business
  - core
document_type: evaluation
generated: false
---
# Catalogue public, facettes, recherche et tris — point 40

## Conclusion contrôlée

Le socle de requête catalogue M8.2 est **démontré** au niveau unitaire, structurel et dans Chromium : reconstruction des index, recherche publique, facettes multi-valeurs, compteurs contextuels, combinaison OR/AND, isolement des attributs par groupe, sept tris stables, pagination, parité SSR/API, URL partageable et retour au contexte. Confiance : **élevée**.

L’utilisabilité dans Chromium est **démontrée** sur desktop et viewport mobile pour le parcours couvert. L’accessibilité complète reste **partiellement démontrée** : les noms accessibles, le focus clavier et l’absence de débordement horizontal sont testés, mais Firefox, WebKit, zoom 200 % et lecteur d’écran ne l’ont pas été.

## Faits et preuves

| Conclusion | Statut | Preuve | Limite | Confiance |
|---|---|---|---|---|
| aucun filtre/recherche sur le JSON DTO | démontré | index scalaires et tables normalisées ; test de reconstruction | recherche textuelle SQLite sans moteur linguistique spécialisé | élevée |
| SSR et API utilisent le même service de requête | démontré | `ResolvePublicRoute` et `PublicCatalogApiHandler` appellent `StorefrontProjectionRepository::products()` ; égalité API/service testée | rendu HTML non comparé octet à octet | élevée |
| OR intra-facette, AND inter-facettes | démontré | assertions unitaires dédiées | jeu de données de démonstration limité | élevée |
| attributs filtrables seulement avec un groupe unique | démontré | projection PIM + assertions avec/sans groupe | une sélection de plusieurs groupes masque volontairement ces facettes | élevée |
| tris et pagination stables | démontré | prix numérique, tri inconnu 422, absence de doublon sur deux pages | charge volumique non encore qualifiée | élevée |
| filtres partageables et retour produit | démontré | scénario Playwright : filtre SSR, ouverture produit, lien retour et état restauré | Chromium uniquement | élevée |
| accessibilité et mobile | partiellement démontré | contrôles nommés, focus clavier et absence de débordement vérifiés à 390 × 820 | zoom, lecteur d’écran, Firefox et WebKit non exécutés | moyenne |

## Commandes exécutées le 2026-07-17

```text
php tools/php/tests/unit/storefront_projection_test.php
[OK] UNIT storefront sellables and projections (37 assertions)

php tools/php/tests/unit/business_pim_api_controller_test.php
[OK] UNIT business PIM admin API (142 assertions)

npm run build  (depuis frontend/admin-vue)
succès : vue-tsc --noEmit puis build Vite

python3 tools/cms.py validate --category database --category api --category documentation --category content
succès : DB_SCHEMA, DB_INVENTORY, PROJECTION_DEFINITIONS, PRODUCT_CONTENT_LINKS,
PRICING_OFFERS_BUNDLES, PIM_QUALITY_IMPORT_CHANNELS, SALE_STATE_INTEGRITY,
BLUEPRINT_SCHEMA, CONTENT_CONTRACTS, MULTISITE_LOCALE_MODEL, I18N_COVERAGE,
API_SPEC et DOCUMENTATION_CONTRACTS

php tools/php/tests/run.php
succès : suite fonctionnelle PHP ; quatre tests HTTP explicitement ignorés faute de port TCP dans le sandbox

python3 tools/cms.py e2e --use-built-assets --spec storefront-catalog-search-facets-40.spec.ts
succès final : 1 scénario Chromium en 40,6 s (desktop, mobile, SSR/API, URL et retour)

python3 tools/cms.py migrate --database core --plan
succès : migration 081 identifiée comme seule migration en attente

python3 tools/cms.py migrate --database core --apply --backup --yes
succès : archive SQLite horodatée créée puis migration 081 appliquée

reconstruction Storefront locale fr via StorefrontProjectionService
succès : 7 produits, 4 collections, canal 3 ; intégrité SQLite ok, 7 index produit et 32 valeurs de facette
```

## Contradictions et limites

- La documentation du point 39 qualifiait les facettes avancées d’incomplètes. Cette affirmation était exacte au 16 juillet ; elle est désormais remplacée pour la recherche, les compteurs et les tris, mais reste valide pour toute popularité anonyme ou recommandation comportementale, non implémentée.
- Le scénario Chromium ne prouve pas le comportement sous Firefox, WebKit, lecteur d’écran ou zoom 200 %.
- Aucun classement ne s’appuie sur une popularité individuelle ou un profil client ; aucune donnée personnelle n’est collectée par ce moteur.
