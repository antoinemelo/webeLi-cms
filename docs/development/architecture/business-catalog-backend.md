---
title: Backend Business Catalogue
audience:
  - developer
  - administrator
status: draft
last_verified: 2026-06-27
source_of_truth: analysis
source_paths:
  - /home/amelo/Documents/DEV/Ecol_WebeLi/web/_CATALOGUE/07_BACKEND_REPOSITORIES_SERVICES.md
  - backend/src/Modules/Business/Repositories/CatalogProductRepository.php
  - backend/src/Modules/Business/Repositories/CatalogVariantRepository.php
  - backend/src/Modules/Business/Repositories/CatalogDiscountRepository.php
  - backend/src/Modules/Business/Repositories/CatalogStockRepository.php
  - backend/src/Modules/Business/Services/CatalogProductService.php
  - backend/src/Modules/Business/Services/CatalogVariantService.php
  - backend/src/Modules/Business/Services/CatalogDiscountService.php
  - backend/src/Modules/Business/Services/CatalogStockService.php
  - backend/src/Modules/Business/Catalog/CatalogPricingService.php
  - tools/php/tests/unit/business_catalog_backend_services_test.php
owners:
  - business
document_type: guide
generated: false
---
# Backend Business Catalogue

La couche backend du catalogue est decoupee en repositories SQL et services metier. Elle reste interne au module `business` ; les endpoints API et l'interface Vue sont traites dans les points suivants.

## Repositories

- `CatalogBrandRepository` : creation et archivage de marques.
- `CatalogCategoryRepository` : creation et archivage de categories.
- `CatalogProductRepository` : creation, mise a jour, archivage de produits et prix de base.
- `CatalogVariantRepository` : creation et archivage de variantes, validation SKU et valeurs d'options liees au produit.
- `CatalogPricingRepository` : alias canonique du repository de snapshots prix utilise par `CatalogPricingService`.
- `CatalogDiscountRepository` : creation, archivage et selection des reductions actives par canal/portee.
- `CatalogStockRepository` : mouvements de stock et mise a jour denormalisee des quantites.

## Services

- `CatalogProductService` : valide les produits et refuse l'activation sans variante active.
- `CatalogVariantService` : valide les variantes, SKU, options et ajustements achat/vente.
- `CatalogPricingService` : calcule prix regulier achat/vente, prix final vente, remise et marge.
- `CatalogDiscountService` : valide les reductions et garantit leur application sur vente uniquement.
- `CatalogStockService` : applique les mouvements simples et empeche le stock negatif sans backorder.
- `CatalogVisibilityService` : nettoie les payloads publics des prix d'achat et marges.

Des interfaces de services existent pour stabiliser les futurs controleurs API :

- `CatalogProductServiceContract`
- `CatalogVariantServiceContract`
- `CatalogDiscountServiceContract`
- `CatalogStockServiceContract`

## Regles couvertes

- un produit actif doit avoir au moins une variante active et un prix de vente ;
- une variante ne peut pas utiliser une option non liee au produit ;
- un SKU actif est unique ;
- les ajustements `percent_delta` sont des pourcentages ;
- une reduction montant ne produit jamais de prix final negatif ;
- le prix d'achat et les marges ne sont pas exposes par les payloads publics ;
- les mouvements de stock refusent le negatif lorsque `allow_backorder=false`.

## Tests

Le test `tools/php/tests/unit/business_catalog_backend_services_test.php` couvre le parcours backend minimal : creation marque/categorie/produit, variante avec options, activation, calcul prix/remise/marge, mouvements de stock et payload public.
