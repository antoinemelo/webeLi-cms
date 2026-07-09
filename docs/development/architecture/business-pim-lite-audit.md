---
title: Audit PIM-lite Opérations / Catalogue / Vente
audience:
  - developer
  - administrator
  - evaluator
status: draft
last_verified: 2026-07-08
source_of_truth: code
source_paths:
  - database/modules/business.sql
  - database/modules/sale.sql
  - backend/src/Modules/Business/BusinessModuleProvider.php
  - backend/src/Modules/Sale/SaleModuleProvider.php
  - backend/src/Modules/Business/Services/BusinessCatalogSellableReadService.php
  - backend/src/Modules/Business/Repositories/PosCatalogRepository.php
  - backend/src/Modules/Sale/Services/SaleCatalogSnapshotService.php
  - frontend/admin-vue/src/views/modules/BusinessCatalogView.vue
  - frontend/admin-vue/src/api/businessCatalog.ts
  - frontend/admin-vue/src/views/modules/SaleView.vue
  - frontend/admin-vue/src/views/modules/SalePosView.vue
  - tools/php/tests/unit/sale_snapshot_services_test.php
owners:
  - business
  - sale
document_type: audit
generated: false
---
# Audit PIM-lite Opérations / Catalogue / Vente

Cet audit fixe l'etat actuel du catalogue **Opérations** et de son interaction avec **Vente** avant les evolutions PIM-lite. Il ne decrit aucun changement fonctionnel a appliquer dans cette etape.

## Synthese

Le catalogue Opérations contient deja les fondations produit : marques, categories, produits, options, variantes, prix achat/vente, remises, stock simple, medias et tags. Vente consomme deja ces donnees via un service de snapshot vendable et les copie dans `sale.sqlite` sous forme de references et snapshots JSON.

Le socle est donc utilisable, mais il reste incomplet comme PIM : pas de score de completude, pas de liste explicite des exigences manquantes, pas de champ `main_asset` stabilise dans le snapshot vendable, pas de role media par canal, et pas encore de blueprints catalogue/PIM declares au niveau module avec le meme niveau de detail que les blueprints CRM.

## Etat actuel

### Tables catalogue Opérations

| Famille | Tables existantes |
|---|---|
| Ancien catalogue prix | `business_catalog_products`, `business_catalog_variants`, `business_catalog_variant_price_adjustments`, `business_catalog_offers` |
| Referentiels | `business_product_brands`, `business_product_categories`, `business_tax_classes`, `business_product_tags`, `business_product_tag_links` |
| Produits et variantes | `business_products`, `business_product_variants`, `business_product_options`, `business_product_option_values`, `business_product_option_links`, `business_product_variant_option_values` |
| Prix et remises | `business_product_base_prices`, `business_product_variant_price_adjustments`, `business_catalog_discounts` |
| Stock catalogue | `business_stock_movements` et les compteurs `stock_quantity` / `stock_reserved` sur variantes |
| Medias | `business_product_assets`, avec `media_id` vers le gestionnaire Médias/Assets du CMS |

La variante reste l'objet vendable. Le produit porte le type, le statut, la visibilite, les canaux `is_ecommerce_enabled` et `is_pos_enabled`, la taxe, le suivi de stock et les descriptions.

### Tables Vente consommatrices de snapshots

| Table Vente | Role |
|---|---|
| `sale_catalog_variant_refs` | Cache de reference d'une variante catalogue Business, avec `last_snapshot_json`. |
| `sale_cart_lines` | Copie les identifiants produit/variante, SKU, barcode, prix, taxe, cout achat optionnel et metadata de ligne. |
| `sale_order_lines` | Conserve le snapshot transactionnel immuable dans `snapshot_json`. |
| `sale_customer_refs` et `sale_orders.customer_snapshot_json` | Equivalent client CRM, separe du snapshot produit. |

`sale.sqlite` ne declare pas de cle etrangere vers `business.sqlite`, ce qui preserve la reproductibilite des commandes et evite un couplage inter-base fragile.

### Service vendable

`BusinessCatalogSellableReadService` existe et fournit :

- `variantSnapshot()` pour produire un snapshot variant par canal `admin`, `pos` ou `ecommerce` ;
- `searchSellableVariants()` pour rechercher par SKU, barcode, type produit ou texte ;
- `assertVariantSellable()` pour bloquer une variante non vendable ;
- `publicPayload()` pour retirer le prix d'achat d'un payload public.

`SaleCatalogSnapshotService` l'utilise pour alimenter `sale_catalog_variant_refs` et pour fournir les snapshots Vente.

### Medias produit

Les medias produit sont stockes dans `business_product_assets`. Les fichiers eux-memes restent geres par la logique Médias/Assets du CMS et sont references par `media_id`.

- `product_id` obligatoire ;
- `variant_id` optionnel ;
- `media_id` vers le gestionnaire Médias/Assets ;
- `role` couvrant `main`, `gallery`, `thumbnail`, `variant`, `document`, `technical_sheet`, `brand_logo`, `packaging`, `seo`, `internal` ;
- `channel_scope` pour `all`, `public`, `ecommerce`, `pos`, `admin`, `pdf` ;
- `title`, `alt_text`, `caption`, `sort_order`, `is_public`, `archived_at`.

`PosCatalogRepository::thumbnail()` lit maintenant les assets `main` ou `thumbnail` par variante puis par produit. En revanche, le snapshot vendable ne contient pas encore un `main_asset` normalise.

### Vendabilite

Une notion `is_sellable` existe deja dans le snapshot. Elle depend de :

- produit actif ;
- variante active ;
- canal active (`is_pos_enabled` ou `is_ecommerce_enabled`) ;
- stock disponible, sauf si le stock n'est pas suivi ou si le backorder est autorise.

Cette logique est utile pour Vente, mais elle ne fournit pas encore les raisons detaillees du refus (`missing_requirements`) ni un score de completude.

### Prix achat et vente

Les prix achat et vente sont separes :

- `business_product_base_prices.price_kind` vaut `purchase` ou `sale` ;
- `business_product_variant_price_adjustments.price_kind` vaut `purchase` ou `sale` ;
- les lignes Vente peuvent conserver `unit_purchase_price_minor`, separe de `unit_price_minor`.

Les prix d'achat sont proteges cote API publique :

- `BusinessCatalogSellableReadService::publicPayload()` retire `unit_purchase_price_minor` ;
- `CatalogVisibilityService` applique aussi ce nettoyage ;
- les tests `sale_snapshot_services_test.php` verifient que le payload public ne contient pas le prix d'achat.

### POS

Le POS dispose deja d'une recherche et de snapshots de variantes vendables. Le snapshot contient `product_name`, `variant_name`, `sku`, `barcode`, prix, taxe, stock et metadata. Il ne contient pas encore un label court dedie au POS ni une image principale normalisee.

### Dependances de modules

`SaleModuleProvider::dependencies()` declare explicitement `business`. Vente depend donc proprement d'Opérations pour le catalogue et les references CRM, sans dupliquer les tables catalogue.

### Tests existants

Les tests couvrent deja une partie de la vendabilite :

- existence d'une variante demo vendable ;
- conversion des montants en unites mineures ;
- application d'une remise catalogue active ;
- presence du prix d'achat en contexte admin ;
- suppression du prix d'achat dans le payload public ;
- ecriture du cache `sale_catalog_variant_refs` ;
- recherche par SKU, barcode et type produit ;
- refus d'une variante non active quand la vendabilite est requise.

## Lacunes PIM-lite

| Sujet | Lacune |
|---|---|
| Completude | Aucun score de completude produit/variante. |
| Diagnostic vendabilite | Aucun `missing_requirements` detaille pour expliquer pourquoi une variante n'est pas vendable. |
| Asset principal | Aucun `main_asset` stable dans `BusinessCatalogSellableReadService`. |
| Medias dans snapshot vendable | Les assets portent un canal, mais `BusinessCatalogSellableReadService` n'inclut pas encore `main_asset`. |
| Label POS | Aucun champ ou calcul dedie `short_label` / `pos_label`. |
| Blueprints PIM | Le module Business declare des blueprints CRM ; les ressources catalogue ne sont pas encore documentees comme blueprints PIM admin. |
| Qualite des donnees | Pas de controle centralise pour produit actif sans prix de vente, sans variante active, sans media ou sans canal. |
| UI catalogue | `BusinessCatalogView.vue` gere deja produits, variantes, prix, stock et remises, mais ne montre pas encore une vue PIM de completude/vendabilite. |

## Decisions proposees

1. Conserver Opérations comme source canonique du catalogue et Vente comme domaine transactionnel.
2. Ne pas dupliquer produits, variantes, prix ou medias dans `sale.sqlite`, sauf snapshots immuables.
3. Etendre `BusinessCatalogSellableReadService` plutot que lire les tables catalogue directement depuis Vente.
4. Ajouter un diagnostic PIM-lite explicite : `is_sellable`, `completeness_score`, `missing_requirements`.
5. Normaliser un asset principal dans le snapshot : `main_asset` avec fallback `thumbnail`, variante puis produit.
6. Garder le prix d'achat disponible seulement en contexte admin autorise et absent des payloads publics.
7. Declarer des blueprints admin catalogue/PIM si le prochain prompt demande une source machine-readable officielle.
8. Ne pas exposer de nouvelles donnees PIM sensibles dans l'API publique sans contrat explicite.

## Fichiers a modifier ensuite

| Objectif | Fichiers probables |
|---|---|
| Diagnostic vendabilite et completude | `backend/src/Modules/Business/Services/BusinessCatalogSellableReadService.php`, tests `business_catalog_*` et `sale_snapshot_services_test.php` |
| Asset principal POS/e-commerce | `backend/src/Modules/Business/Repositories/PosCatalogRepository.php`, `backend/src/Modules/Business/Services/BusinessCatalogSellableReadService.php` |
| Blueprints admin PIM | `backend/src/Modules/Business/BusinessModuleProvider.php`, contrats admin generes si versionnes |
| UI PIM-lite catalogue | `frontend/admin-vue/src/views/modules/BusinessCatalogView.vue`, `frontend/admin-vue/src/api/businessCatalog.ts` |
| Documentation | `docs/development/architecture/business-catalog-functional-spec.md`, `docs/business/catalogue.md`, ce document |
| Tests API/POS | `tools/php/tests/unit/business_pos_catalog_api_test.php`, `tools/php/tests/unit/sale_snapshot_services_test.php` |

## Conclusion

Le socle actuel est suffisamment sain pour evoluer vers un PIM-lite pragmatique. La priorite n'est pas le schema transactionnel Vente, deja separe, mais la qualite du contrat catalogue : expliquer la vendabilite, normaliser les assets et declarer les ressources PIM admin sans exposer de donnees sensibles publiquement.
