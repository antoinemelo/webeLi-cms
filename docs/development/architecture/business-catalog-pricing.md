---
title: Modele prix et variantes Business Catalogue
audience:
  - developer
  - administrator
status: draft
last_verified: 2026-06-27
source_of_truth: analysis
source_paths:
  - /home/amelo/Documents/DEV/Ecol_WebeLi/web/_CATALOGUE/04_MODELE_PRIX_VARIANTES.md
  - database/modules/business.sql
  - database/migrations/business/0003_catalog_schema.sql
  - database/migrations/business/0004_catalog_demo_seed.sql
  - backend/src/Modules/Business/Catalog/CatalogPricingService.php
  - backend/src/Modules/Business/Repositories/BusinessCatalogPricingRepository.php
  - tools/php/tests/unit/business_catalog_pricing_service_test.php
owners:
  - business
document_type: specification
generated: false
---
# Modele prix et variantes Business Catalogue

Ce modele fixe le calcul minimal des prix du catalogue Business. Il se limite au prix d'achat, au prix de vente, aux ajustements de variante et aux remises commerciales simples. Les futurs modules commandes et factures devront copier les montants calcules au moment de la transaction.

## Donnees persistantes

Tables ajoutees dans `business.sqlite` :

- `business_products` : fiche produit/service/bon cadeau ;
- `business_product_variants` : SKU vendable rattache a un produit ;
- `business_product_base_prices` : prix de base achat/vente par `price_kind` ;
- `business_product_variant_price_adjustments` : ajustement de variante par `price_kind` ;
- `business_catalog_discounts` : remise simple appliquee au prix de vente.

`price_kind` vaut strictement :

- `purchase` ;
- `sale`.

`adjustment_type` vaut strictement :

- `amount_delta` ;
- `percent_delta` ;
- `fixed_override`.

Une variante sans ajustement n'a pas de ligne dans `business_product_variant_price_adjustments` et herite du prix de base.

## Formules

Prix regulier achat :

```text
regular_purchase_price = base_purchase_price + purchase_adjustment
```

Prix regulier vente :

```text
regular_sale_price = base_sale_price + sale_adjustment
```

Prix de vente final :

```text
final_sale_price = regular_sale_price - active_sale_discount
```

Marge brute :

```text
gross_margin_amount = final_sale_price - regular_purchase_price
gross_margin_percent = gross_margin_amount / final_sale_price * 100
```

Les remises ne modifient jamais le prix d'achat. Elles s'appliquent uniquement au prix de vente calcule.

## Selection des remises

La v1 n'empile pas les offres. Lorsqu'une variante est eligible a plusieurs remises actives sur le canal demande, `CatalogPricingService` applique une seule remise selon cet ordre :

1. specificite de portee : `variant`, puis `product`, puis `category`, puis `brand` ;
2. priorite croissante dans une meme portee ;
3. identifiant croissant en dernier recours pour garder un resultat stable.

Les remises expirees, futures, archivees ou rattachees a un autre canal sont ignorees. Une remise `amount` ne peut jamais produire un prix final negatif : le prix de vente final est borne a `0.00`.

## Arrondis

La devise par defaut est `CHF`. Les montants sont arrondis a 2 decimales par `CatalogPricingService`. Aucun arrondi psychologique ou fiscal avance n'est applique dans ce point.

## Payloads attendus

Le resume interne peut contenir :

- `base_purchase_price` ;
- `purchase_adjustment_type` et `purchase_adjustment_value` ;
- `regular_purchase_price` ;
- `base_sale_price` ;
- `sale_adjustment_type` et `sale_adjustment_value` ;
- `regular_sale_price` ;
- `active_discount` ;
- `final_sale_price` ;
- `gross_margin_amount` et `gross_margin_percent`.

Le payload public ne doit jamais exposer :

- `base_purchase_price` ;
- `purchase_adjustment_type` ;
- `purchase_adjustment_value` ;
- `regular_purchase_price` ;
- `gross_margin_amount` ;
- `gross_margin_percent`.

## Tests

Le test `tools/php/tests/unit/business_catalog_pricing_service_test.php` couvre :

- heritage des prix de base sans ajustement ;
- ajustements achat et vente par montant ;
- ajustements achat et vente par pourcentage ;
- ajustements achat et vente differents ;
- remise pourcentage et montant uniquement sur le prix de vente ;
- remises marque, categorie, produit et variante selon la specificite puis la priorite ;
- remises expirees, futures ou limitees au canal POS ;
- calcul de marge ;
- prix de vente final borne a zero ;
- absence de prix d'achat dans le payload public.
