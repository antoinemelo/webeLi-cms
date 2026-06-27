---
title: API Catalogue Business
audience:
  - administrator
  - superadministrator
  - api-integrator
  - developer
status: draft
last_verified: 2026-06-27
source_of_truth: manual
source_paths:
  - backend/src/Application/Api/Admin/BusinessCatalogApiController.php
  - backend/src/Application/PublicApi/PublicCatalogApiHandler.php
  - backend/src/Application/PublicApi/PosCatalogApiHandler.php
  - backend/src/Modules/Business/Catalog/CatalogPricingService.php
  - backend/src/Modules/Business/Repositories/BusinessCatalogPricingRepository.php
  - backend/routes/api.php
  - database/migrations/business/0003_catalog_schema.sql
owners:
  - business
document_type: reference
generated: false
---
# API Catalogue Business

Cette page décrit l'API catalogue du module `business` pour les développeurs et futurs intégrateurs e-commerce/POS. Les contrats générés restent la référence technique détaillée ; cette page explique le modèle, les règles et les limites v1.

## Modèle de données

Le catalogue utilise `business.sqlite` et les tables principales suivantes :

| Objet | Rôle |
|---|---|
| `business_product_brands` | Marques visibles ou internes. |
| `business_product_categories` | Catégories hiérarchiques simples. |
| `business_products` | Produit logique : type, statut, canaux, descriptions et liens marque/catégorie. |
| `business_product_options` | Options génériques comme taille, couleur, modèle ou durée. |
| `business_product_option_values` | Valeurs disponibles pour une option. |
| `business_product_variants` | SKU réellement vendable, éventuellement lié à un code-barres et au stock. |
| `business_product_base_prices` | Prix d'achat et de vente de base du produit. |
| `business_product_variant_price_adjustments` | Ajustements achat/vente par variante. |
| `business_catalog_discounts` | Réductions simples par marque, catégorie, produit ou variante. |
| `business_stock_movements` | Historique des mouvements de stock simples. |

Un produit peut être `physical`, `service` ou `gift_card`. Les canaux `is_public`, `is_ecommerce_enabled` et `is_pos_enabled` déterminent sa visibilité dans les APIs publiques et POS.

## Règles de prix

La formule canonique est :

```text
Prix d'achat de base
+ ajustement achat variante
= prix d'achat calculé

Prix de vente de base
+ ajustement vente variante
= prix de vente régulier
- réduction active
= prix de vente final
```

Les ajustements supportés sont :

- `none` : pas d'ajustement ;
- `amount_delta` : variation de montant ;
- `percent_delta` : variation en pourcentage ;
- `fixed_override` : remplacement par un montant fixe.

Les réductions ne touchent jamais le prix d'achat. Les futures commandes/factures devront copier les prix calculés au moment de la vente ; elles ne devront pas dépendre d'un recalcul dynamique après modification du catalogue.

## Permissions admin

| Permission | Usage |
|---|---|
| `business.catalog.read` | Lire marques, catégories, produits, options, variantes, stock et offres. |
| `business.catalog.write` | Créer et modifier les ressources catalogue hors prix et offres. |
| `business.catalog.prices.read` | Lire les prix et calculs côté admin. |
| `business.catalog.prices.write` | Modifier prix de base et ajustements. |
| `business.catalog.purchase_prices.read` | Voir prix d'achat et marges. |
| `business.catalog.discounts.write` | Créer, modifier ou archiver les réductions. |
| `business.catalog.stock.write` | Enregistrer des mouvements de stock. |

Sans `business.catalog.purchase_prices.read`, les réponses admin masquent les prix d'achat et les marges.

## Exemples API admin

Créer un produit :

```json
{
  "data": {
    "name": "T-shirt demo",
    "slug": "t-shirt-demo",
    "type": "physical",
    "status": "draft",
    "brand_id": 1,
    "category_id": 2,
    "channels": ["public", "ecommerce", "pos"],
    "base_purchase_price": 12.5,
    "base_sale_price": 25,
    "currency": "CHF",
    "option_ids": [10, 11]
  }
}
```

Route :

```http
POST /admin/api/business/catalog/products
```

Créer une variante :

```json
{
  "data": {
    "sku": "TSHIRT-DEMO-M-BLUE",
    "barcode": "7612345678901",
    "name": "T-shirt M bleu",
    "status": "active",
    "stock_quantity": 20,
    "option_values": {
      "size": "m",
      "color": "blue"
    },
    "purchase_adjustment_type": "amount_delta",
    "purchase_adjustment_value": 2,
    "sale_adjustment_type": "amount_delta",
    "sale_adjustment_value": 5
  }
}
```

Route :

```http
POST /admin/api/business/catalog/products/{id}/variants
```

Créer une réduction simple :

```json
{
  "data": {
    "name": "Lancement e-commerce",
    "type": "percent",
    "value": 10,
    "scope": "product",
    "scope_id": 42,
    "channel": "ecommerce",
    "priority": 100
  }
}
```

Route :

```http
POST /admin/api/business/catalog/discounts
```

Prévisualiser un import CSV :

```json
{
  "data": {
    "csv": "product_name;product_slug;variant_sku;base_sale_price\nProduit test;produit-test;TEST-001;29.90\n",
    "create_brands": true,
    "create_categories": true,
    "create_options": true,
    "overwrite_existing": false
  }
}
```

Route :

```http
POST /admin/api/business/catalog/import/preview
```

## Exemples API publique e-commerce

Liste des produits publics :

```http
GET /api/v1/catalog/products?brand=demo-outdoor&category=marchandises
Authorization: Bearer <token catalog:read>
Accept: application/json
```

Extrait de réponse :

```json
{
  "data": {
    "items": [
      {
        "id": 42,
        "slug": "t-shirt-demo",
        "name": "T-shirt demo",
        "variants": [
          {
            "variant_id": 100,
            "sku": "TSHIRT-DEMO-M-BLUE",
            "pricing": {
              "regular_sale_price": "30.00",
              "final_sale_price": "27.00",
              "discount": {
                "label": "Lancement e-commerce",
                "type": "percent",
                "value": 10
              }
            }
          }
        ]
      }
    ]
  }
}
```

L'API publique catalogue exige `catalog:read` ou l'alias `headless:read`. Elle ne retourne jamais le prix d'achat, les marges ou les quantités exactes de stock.

## Exemples API POS

Bootstrap POS :

```http
GET /api/v1/pos/catalog/bootstrap
Authorization: Bearer <token pos.catalog.read>
Accept: application/json
```

Recherche par code-barres :

```http
GET /api/v1/pos/catalog/variants?barcode=7612345678901
Authorization: Bearer <token pos.catalog.read>
Accept: application/json
```

Extrait de réponse :

```json
{
  "data": {
    "items": [
      {
        "variant_id": 100,
        "sku": "TSHIRT-DEMO-M-BLUE",
        "barcode": "7612345678901",
        "pricing": {
          "regular_sale_price": "30.00",
          "final_sale_price": "27.00"
        }
      }
    ]
  }
}
```

Le scope POS est volontairement séparé : `pos.catalog.read` n'est pas inclus dans `headless:read`.

## Exemple CSV

```csv
product_name;product_slug;type;status;brand;category;is_public;is_ecommerce_enabled;is_pos_enabled;base_purchase_price;base_sale_price;currency;variant_sku;variant_barcode;variant_name;variant_options;purchase_adjustment_type;purchase_adjustment_value;sale_adjustment_type;sale_adjustment_value;stock_quantity
T-shirt demo;t-shirt-demo;physical;active;NOUVELLE MARQUE;Marchandises;1;1;1;12.50;25.00;CHF;TSHIRT-DEMO-M-BLUE;7612345678901;T-shirt M bleu;size:m|color:blue;amount_delta;2;amount_delta;5;20
```

L'import refuse les prix négatifs ou non numériques. L'écrasement d'un produit ou d'une variante existante demande `overwrite_existing: true`.

## Sécurité

- Les prix d'achat et marges restent réservés au back-office autorisé.
- Les APIs publiques/POS ne retournent jamais les prix d'achat.
- Les CSV exportés neutralisent les cellules commençant par `=`, `+`, `-` ou `@`.
- Le POS exige un token avec scope dédié.
- Les endpoints admin restent protégés côté serveur ; le masquage UI n'est pas une règle de sécurité.

## Limites v1

- pas de moteur de promotions avancées ;
- pas de coupons complexes ;
- pas de multi-entrepôts ;
- pas de PIM enterprise ;
- pas d'abonnements ;
- pas de bundles complexes ;
- pas de commandes, factures ou paiements natifs ;
- pas de synchronisation externe e-commerce/POS prête à l'emploi.
