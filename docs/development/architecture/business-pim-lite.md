---
title: Architecture PIM-lite Opérations
audience:
  - developer
  - administrator
  - evaluator
status: draft
last_verified: 2026-07-09
source_of_truth: code
source_paths:
  - database/modules/business.sql
  - backend/src/Modules/Business/BusinessModuleProvider.php
  - backend/src/Application/Api/Admin/BusinessPimApiController.php
  - backend/src/Modules/Business/Services/BusinessPimAdminService.php
  - backend/src/Modules/Business/Services/BusinessProductCompletenessService.php
  - backend/src/Modules/Business/Services/BusinessProductAssetService.php
  - backend/src/Modules/Business/Services/BusinessCatalogSellableReadService.php
  - backend/src/Modules/Sale/Services/SaleCatalogSnapshotService.php
  - docs/development/architecture/business-pim-lite-schema.md
owners:
  - business
  - sale
document_type: architecture
generated: false
---
# Architecture PIM-lite Opérations

Le PIM-lite est la couche de qualite produit du module **Opérations**. Il reste dans `business.sqlite`, car il enrichit le catalogue existant : produits, variantes, prix, medias, attributs, completude, canaux et offres composees.

Il ne devient pas un module separe et ne deplace pas les donnees vers Vente.

```text
Opérations / business.sqlite
  produits, variantes, medias produit, attributs, completude, prix catalogue

Vente / sale.sqlite
  paniers, commandes, paiements, reservations, mouvements transactionnels
```

## Pourquoi le PIM-lite reste dans Business

Business est deja proprietaire du catalogue exploite par le back-office, le POS et l'e-commerce. Les tables PIM-lite ajoutent des informations de qualite autour de ce catalogue, sans changer le domaine transactionnel :

- `business_product_assets` relie un media CMS a un produit ou une variante ;
- `business_attributes`, `business_attribute_groups` et `business_attribute_options` structurent les attributs ;
- `business_product_attribute_values` et `business_variant_attribute_values` portent les valeurs ;
- `business_product_completeness_scores` donne le diagnostic de qualite ;
- `business_product_bundles` et `business_bundle_components` decrivent les offres composees.

Ces donnees sont des donnees de reference. Elles peuvent changer tant qu'aucune vente n'est figée.

## Pourquoi Vente reste séparé

Vente conserve ses propres objets transactionnels dans `sale.sqlite` :

- paniers et lignes ;
- commandes et lignes ;
- paiements ;
- sessions POS ;
- reservations et mouvements de stock transactionnels ;
- recus, retours et evenements.

Une commande placee ne doit jamais etre recalculee depuis un produit modifie plus tard. Vente copie donc les informations utiles sous forme de snapshots.

## Frontière entre Business et Vente

Vente ne doit pas lire directement les tables `business_*` dans ses workflows. Le contrat stable est :

1. `BusinessCatalogSellableReadService` construit un snapshot de variante vendable depuis Business.
2. `SaleCatalogSnapshotService` consomme ce snapshot et le copie dans `sale_catalog_variant_refs`.
3. Les paniers et commandes Vente copient ensuite les champs nécessaires dans leurs lignes.

Cette frontiere evite la duplication du catalogue dans `sale.sqlite` et limite le couplage entre domaines.

## Où ajouter une règle de complétude

Une regle de completude se traite a deux niveaux :

- donnee configurable : `business_product_completeness_rules` si la regle doit etre stockee ;
- logique de calcul : `BusinessProductCompletenessService` si la regle doit influencer le score, `is_sellable`, `missing` ou `warnings`.

Le recalcul est expose par `BusinessPimAdminService::recalculateProductCompleteness()` et par les endpoints admin PIM. Le snapshot vendable lit ensuite les scores via `BusinessCatalogSellableReadService`.

Chemin normal :

```text
modification produit/variante/attribut/media
  -> recalcul BusinessProductCompletenessService
  -> business_product_completeness_scores
  -> BusinessCatalogSellableReadService
  -> SaleCatalogSnapshotService si Vente en a besoin
```

## Sécurité des prix d'achat

Les prix d'achat et marges sont admin-only.

- `BusinessCatalogSellableReadService::publicPayload()` retire les prix d'achat, marges et champs internes.
- `BusinessCatalogSellableReadService::aiSnapshot()` masque aussi ces champs si l'appelant n'a pas le droit.
- Les endpoints publics catalogue ne doivent jamais exposer `purchase_price_minor`, `unit_purchase_price_minor`, `margin_minor` ou `margin_percent_basis_points`.

La permission de reference reste `business.catalog.purchase_prices.read`.

## API publique catalogue

Les endpoints publics catalogue peuvent exposer uniquement les donnees utiles au client :

- nom, slug et descriptions publiques ;
- prix de vente ;
- images publiques ;
- attributs publics ;
- disponibilite generale.

Ils ne creent pas d'API PIM publique generale. Les endpoints PIM restent sous `/admin/api/business/pim/...`.

## Reprise de Vente

Pour reprendre Vente au point 16, partir du snapshot vendable et non des tables Business :

1. appeler `SaleCatalogSnapshotService::snapshotForVariant()` ;
2. refuser les variantes non vendables ;
3. copier les montants en unites mineures ;
4. copier le SKU, libelle, taxe, remise, asset et exigences utiles ;
5. stocker le snapshot dans les lignes de panier ou commande ;
6. ne pas recalculer une commande historique depuis Business.

Les details du contrat sont dans [Snapshot vendable Business vers Vente](business-sellable-snapshot.md).
