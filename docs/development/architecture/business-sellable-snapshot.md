---
title: Snapshot vendable Business vers Vente
audience:
  - developer
  - administrator
  - evaluator
status: draft
last_verified: 2026-07-09
source_of_truth: code
source_paths:
  - backend/src/Modules/Business/Services/BusinessCatalogSellableReadService.php
  - backend/src/Modules/Business/Services/BusinessProductCompletenessService.php
  - backend/src/Modules/Business/Services/BusinessProductAssetService.php
  - backend/src/Modules/Business/Services/BusinessProductBundleService.php
  - backend/src/Modules/Sale/Services/SaleCatalogSnapshotService.php
  - tools/php/tests/unit/business_sellable_snapshot_service_test.php
  - tools/php/tests/integration/sale_uses_business_sellable_snapshot_test.php
owners:
  - business
  - sale
document_type: architecture
generated: false
---
# Snapshot vendable Business vers Vente

Le snapshot vendable est le contrat entre le catalogue Opérations et le module Vente. Il represente une variante a un instant donne, avec son prix, ses canaux, sa taxe, son asset principal et son diagnostic de vendabilite.

## Services

| Service | Role |
|---|---|
| `BusinessCatalogSellableReadService` | Lit Business, calcule le snapshot et explique la vendabilite. |
| `SaleCatalogSnapshotService` | Appelle Business, refuse une variante non vendable si demande et stocke une reference dans Vente. |
| `BusinessProductCompletenessService` | Calcule les scores et exigences manquantes. |
| `BusinessProductAssetService` | Fournit l'asset principal produit/variante avec fallback. |
| `BusinessProductBundleService` | Ajoute les informations de bundle et composants si le produit est une offre composee. |

## Lecture côté Business

API interne principale :

```php
$snapshot = $sellables->getSellableVariantSnapshot($siteId, $variantId, [
    'channel' => 'pos',
    'include_purchase_price' => true,
    'include_internal_fields' => true,
]);
```

Canaux utiles :

- `admin` ;
- `pos` ;
- `ecommerce` ;
- `public` ;
- `quote`.

Le snapshot contient notamment :

- `business_product_id` ;
- `business_variant_id` ;
- `sku` et `barcode` ;
- `product_name` et `variant_name` ;
- `product_type` ;
- `currency` ;
- `sale_price_minor` ;
- `regular_sale_price_minor` ;
- `tax_class_id` et `tax_rate_basis_points` ;
- `discounts_applied` ;
- `main_asset` ;
- `track_stock` ;
- `is_sellable` ;
- `missing_requirements` ;
- `visibility`.

Si le contexte autorise les prix d'achat, il ajoute :

- `purchase_price_minor` ;
- `unit_purchase_price_minor` ;
- `margin_minor` ;
- `margin_percent_basis_points`.

## Lecture côté Vente

Vente doit utiliser :

```php
$snapshot = $saleCatalogSnapshots->snapshotForVariant($siteId, $variantId, 'pos', true);
```

Avec `requireSellable=true`, `SaleCatalogSnapshotService` lance une exception `sale.catalog.variant_not_sellable` si la variante n'est pas vendable.

Le service stocke aussi le dernier snapshot dans `sale_catalog_variant_refs.last_snapshot_json`. Les lignes de panier et de commande peuvent ensuite copier les champs necessaires, sans relire les tables Business.

## Règles de vendabilité

Le snapshot refuse ou signale notamment :

- produit inactif ;
- variante inactive ;
- SKU manquant ;
- prix de vente absent ;
- taxe absente selon le canal ;
- canal POS ou e-commerce desactive ;
- visibilite publique absente pour `public` et `ecommerce` ;
- stock indisponible si le suivi de stock bloque la vente ;
- score de completude non vendable ;
- composant de bundle non vendable.

`missing_requirements` est le champ a afficher ou journaliser pour expliquer le refus.

## Prix d'achat et payload public

`publicPayload()` retire toujours les champs sensibles :

- `purchase_price_minor` ;
- `unit_purchase_price_minor` ;
- `margin_minor` ;
- `margin_percent_basis_points` ;
- `snapshot_json`.

Ne pas exposer ces champs dans une API publique, un storefront ou un payload IA non autorise.

## Offres composées

Pour un produit de type bundle, le snapshot peut inclure :

- `is_bundle` ;
- `bundle_components` ;
- `bundle_pricing_mode` ;
- `bundle_stock_mode` ;
- `bundle_available_quantity` ;
- `bundle_missing_requirements`.

Le bundle est un produit vendable distinct. Ses composants restent des produits ou variantes Business independants.

## Erreurs à éviter

- Lire `business_products` ou `business_product_variants` directement depuis un workflow Vente.
- Recalculer une commande placee depuis le catalogue courant.
- Stocker des montants en `REAL` dans Vente.
- Exposer le prix d'achat dans un payload public.
- Considerer le score de completude comme suffisant sans verifier `is_sellable`.
