---
title: Sellables et projections Storefront
audience:
  - developer
  - administrator
status: stable
last_verified: 2026-07-12
source_of_truth: code
source_paths:
  - database/modules/business.sql
  - database/modules/sale.sql
  - database/schema/core.sql
  - backend/src/Application/Business/StorefrontProjectionService.php
  - backend/src/Application/Business/StorefrontProjectionRepository.php
owners:
  - business
  - sale
  - core
document_type: architecture
generated: false
---
# Sellables et projections Storefront

## Identifiants audités et migration

Avant M2, `sale_cart_lines` et `sale_order_lines` identifiaient l’unité ajoutée avec `business_variant_id`. Les snapshots, réservations et références catalogue Sale utilisaient le même identifiant. Le contrat M2 formalise cette unité sous le nom `sellable_id` sans renumérotation : pour les données existantes, `sellable_id = business_variant_id`.

`business_sellables` rend cette correspondance explicite et qualifie l’unité comme `simple`, `variant`, `service`, `gift_card` ou `bundle`. Toute fiche sans variante reçoit une variante par défaut stable durant la migration. Les anciennes colonnes restent présentes pour lire les commandes historiques ; les nouvelles écritures Sale enregistrent les deux identifiants.

Les responsabilités sont distinctes :

- le produit porte identité, type et merchandising ;
- la variante porte les options et le SKU ;
- le sellable est l’unité exacte ajoutable au panier ;
- prix et disponibilité sont calculés dans leur contexte ;
- le contenu CMS porte narration, route et SEO éditorial.

## DTO publics versionnés

Les projections utilisent `storefront.product.v1`, `storefront.collection.v1` et `storefront.sellable.v1`. Un produit projeté contient ses sellables, prix affichables en unités mineures, disponibilité sans stock interne exact, médias publics, CTA, canonical, hreflang, robots et JSON-LD. Un produit inactif, privé, désactivé pour l’e-commerce, incomplet ou sans sellable commandable n’est pas projeté.

La clé de projection est `(site_id, channel_id, locale, product_id)`. `StorefrontProjectionService` lit Business lors du rebuild puis écrit `storefront_product_projections` et `storefront_collection_projections` dans `core.sqlite`. Ensuite, SSR, API headless et blocs CMS lisent uniquement Core ; aucun rendu public ne se connecte à `business.sqlite`.

## Rebuild et invalidation

Les changements de produit, variante, prix, visibilité, disponibilité et média créent une entrée dans `business_storefront_projection_invalidations`. La commande administrative `POST /admin/api/business/pim/storefront-projections/rebuild` reconstruit atomiquement un site/canal/locale et marque les invalidations traitées. Ainsi, un prix ou une disponibilité change sans republier le texte CMS.

Le rebuild complet est déterministe et constitue la stratégie de rattrapage après import, restauration ou modification groupée. Les projections portent un hash de source et une date de génération.

## Composition CMS, SSR et headless

Les blocs disponibles sont `featured_product`, `product_card`, `product_grid`, `collection_grid`, `product_detail` et `add_to_cart`. Une révision CMS stocke seulement les références et paramètres d’affichage. L’hydratation runtime injecte les DTO Core.

Les pages SSR sont `/shop`, `/shop/collections/{slug}` et `/shop/products/{slug}`. Les mêmes données sont disponibles via :

- `GET /api/v1/storefront/products` ;
- `GET /api/v1/storefront/products/{slug}` ;
- `GET /api/v1/storefront/collections`.

L’ajout public au panier exige `sellable_id`. Les alias `variant_id` et `business_variant_id` ne sont plus acceptés par ce point d’entrée, ce qui évite qu’un appelant confonde produit, variante et unité vendable.
