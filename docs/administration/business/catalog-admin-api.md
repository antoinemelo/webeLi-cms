---
title: API admin du catalogue Business
audience:
  - administrator
  - superadministrator
  - developer
status: draft
last_verified: 2026-06-27
source_of_truth: code
source_paths:
  - backend/src/Application/Api/Admin/BusinessCatalogApiController.php
  - backend/routes/api.php
  - backend/src/Modules/Business/BusinessModuleProvider.php
  - database/modules/business.sql
owners:
  - business
document_type: reference
generated: false
---
# API admin du catalogue Business

Le module `business` expose une API admin pour gérer le catalogue depuis le back-office. Les routes sont sous `/admin/api/business/catalog/*` et utilisent l'enveloppe admin standard `data` / `meta`.

## Ressources couvertes

| Ressource | Routes |
|---|---|
| Marques | `GET/POST /brands`, `GET/PATCH/DELETE /brands/{id}` |
| Catégories | `GET/POST /categories`, `GET/PATCH/DELETE /categories/{id}` |
| Produits | `GET/POST /products`, `GET/PATCH/DELETE /products/{id}` |
| Variantes | `GET/POST /products/{id}/variants`, `GET/PATCH/DELETE /variants/{id}` |
| Options | `GET/POST /options`, `PATCH/DELETE /options/{id}` |
| Valeurs d'options | `POST /options/{id}/values`, `PATCH/DELETE /option-values/{id}` |
| Prix | `GET /products/{id}/prices`, `PUT /products/{id}/base-prices`, `PUT /variants/{id}/price-adjustments`, `GET /variants/{id}/computed-prices` |
| Réductions | `GET/POST /discounts`, `GET/PATCH/DELETE /discounts/{id}` |
| Stock simple | `GET /variants/{id}/stock`, `POST /variants/{id}/stock-movements`, `GET /stock-movements` |
| Import/export CSV | `GET /export.csv`, `POST /import/preview`, `POST /import/apply` |

## Back-office Vue

L'écran `/admin/app/business/catalog` expose la gestion catalogue pour les utilisateurs autorisés. Il s'appuie sur les routes admin ci-dessus et garde les calculs critiques de prix côté serveur :

- liste produits avec filtres par recherche, type, marque, catégorie, statut, canal et stock faible ;
- fiche produit avec informations générales, canaux, prix de base, options, variantes, stock et offres actives ;
- variantes avec prix calculés, réduction active et stock ;
- écran offres pour les réductions simples ;
- bloc CSV pour exporter le catalogue, prévisualiser un import et appliquer un import validé.

## Import/export CSV

`GET /admin/api/business/catalog/export.csv` retourne un CSV `;` orienté tableur. Les colonnes suivent le format :

```text
product_id, product_name, product_slug, type, status, brand, category, sku_base,
is_public, is_ecommerce_enabled, is_pos_enabled, base_purchase_price,
base_sale_price, currency, tax_class, options, variant_id, variant_sku,
variant_barcode, variant_name, variant_options, purchase_adjustment_type,
purchase_adjustment_value, sale_adjustment_type, sale_adjustment_value,
computed_purchase_price, computed_regular_sale_price, computed_final_sale_price,
stock_quantity
```

Les cellules `variant_options` utilisent `option:value|option:value`. Les exports neutralisent les cellules commençant par `=`, `+`, `-` ou `@`. Sans `business.catalog.purchase_prices.read`, les colonnes d'achat restent présentes mais leurs valeurs sont vides.

`POST /admin/api/business/catalog/import/preview` accepte un JSON `{ "csv": "...", "create_brands": true, "create_categories": true, "create_options": true, "overwrite_existing": false }` et retourne un rapport ligne par ligne sans écrire. `POST /admin/api/business/catalog/import/apply` applique le même format si toutes les lignes sont valides.

Règles v1 :

- les prix négatifs ou non numériques sont refusés explicitement ;
- une marque, catégorie, option ou valeur manquante n'est créée que si l'option correspondante est active ;
- un produit ou une variante existante n'est pas écrasé sans `overwrite_existing: true` ;
- l'import applique les prix de base et les ajustements de variantes ;
- l'import est atomique : une erreur empêche les écritures.

## Permissions

| Permission | Usage |
|---|---|
| `business.catalog.read` | Lire marques, catégories, produits, options, variantes et réductions. |
| `business.catalog.write` | Créer, modifier et archiver les ressources catalogue hors prix et réductions. |
| `business.catalog.prices.read` | Lire les prix de vente et prix calculés. |
| `business.catalog.prices.write` | Modifier les prix de base et ajustements de variantes. |
| `business.catalog.purchase_prices.read` | Lire les prix d'achat et les marges. Sans ce droit, ces champs sont retirés des réponses. |
| `business.catalog.discounts.write` | Créer, modifier et archiver les réductions. |
| `business.catalog.stock.write` | Réservé aux mouvements de stock simples. |

## Contrôles couverts

Le test `tools/php/tests/unit/business_catalog_api_controller_test.php` vérifie le CRUD marque, la création produit, les options génériques, la variante taille/couleur/modèle, les prix de base, les ajustements, les mouvements de stock simples, les réductions pourcentage/montant et le masquage des prix d'achat pour un utilisateur sans droit dédié.

Le test `tools/php/tests/unit/business_catalog_csv_service_test.php` vérifie l'export avec/sans prix d'achat, le dry-run d'erreur, la création produit + variante + ajustements, le refus d'écrasement sans confirmation et le refus des prix négatifs.

Le smoke ciblé catalogue lance les suites schéma, prix, API admin, API publique, POS et CSV :

```bash
python3 tools/cms.py business smoke
```

Commande ciblée :

```bash
php tools/php/tests/unit/business_catalog_api_controller_test.php
php tools/php/tests/unit/business_catalog_csv_service_test.php
```
