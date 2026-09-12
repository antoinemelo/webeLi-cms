---
title: Sellables et projections Storefront
audience:
  - developer
  - administrator
status: stable
last_verified: 2026-07-17
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

## DTO publics versionnés et index de lecture

Les projections utilisent `storefront.product.v3`, `storefront.collection.v1` et `storefront.sellable.v2`. Le DTO produit v3 contient ses variantes, prix normal et final en unités mineures, promotions, disponibilité publique à quatre états, médias, groupes et attributs publics, CTA conditionnel, contenus CMS liés, livraison, paiement, relations typées, canonical, hreflang, robots et JSON-LD. Un produit inactif, privé ou désactivé pour l’e-commerce n’est pas projeté. Un produit public momentanément non commandable reste consultable avec l’état `unavailable`, sans CTA actif.

## Blocs Commerce Studio

Les blocs `featured_product`, `product_card`, `product_grid`, `collection_grid`, `product_detail` et `add_to_cart` appartiennent à Studio, pas à un module d'administration autonome. Une révision conserve uniquement leurs références stables, règles et préférences d'affichage. `ProductContentLinkService` injecte les DTO projetés au preview, au SSR, au headless et pendant le rendu d'un export statique.

`product_grid` supporte une sélection principale explicite, par marque, catégorie, groupe, attribut public, promotion, nouveauté, popularité ou relation, puis un override manuel ordonné. Une modification Business ou Sale reconstruit la projection sans imposer une republication CMS. Un changement de règle du bloc suit en revanche le workflow normal des révisions CMS. Un export statique doit être reconstruit pour incorporer une projection plus récente ; l'ajout au panier demeure une mutation runtime Sale.

La clé de projection est `(site_id, channel_id, locale, product_id)`. `StorefrontProjectionService` lit Business lors du rebuild puis écrit dans `core.sqlite` :

- `storefront_product_projections` et `storefront_collection_projections` pour les DTO publics immuables ;
- `storefront_product_query_index` pour les champs scalaires déterministes de recherche et de tri ;
- `storefront_product_facet_values` pour les marques, catégories, groupes, disponibilités et attributs publics filtrables.

Le moteur ne fait jamais de `LIKE` sur `dto_json`. Ensuite, SSR, API headless et blocs CMS lisent uniquement Core ; aucun rendu public ne se connecte à `business.sqlite`.

## Recherche, facettes et tris

`StorefrontProjectionRepository::products()` constitue le service de requête commun au SSR et à `GET /api/v1/storefront/products`. Il applique :

- recherche sur le texte public projeté (nom, résumé, description, marque, groupes et attributs marqués recherchables) ;
- OR entre plusieurs valeurs d’une même facette et AND entre facettes différentes ;
- compteurs contextuels recalculés en ignorant seulement la facette en cours ;
- facettes d’attribut uniquement lorsqu’un groupe produit unique est sélectionné ;
- tris stables `relevance`, `name`, `newest`, `price_asc`, `price_desc`, `promo_amount` et `promo_percent`, avec `product_id` comme dernier départage ;
- pagination déterministe, y compris lorsque des valeurs de tri sont absentes.

Un canal Storefront fixe la devise de comparaison. La devise reste néanmoins dans l’index et précède le prix dans les tris afin que des données historiques incohérentes restent déterministes au lieu d’être comparées silencieusement.

## Merchandising agrégé

`StorefrontMerchandisingService` construit les sections publiques à partir des mêmes projections Core. Les promotions utilisent les remises et la disponibilité projetées ; les nouveautés utilisent `storefront_product_publication_history`, dont `first_published_at` est préservé lors d’une reconstruction. Les changements de prix, stock, produit ou publication sont donc pris en compte au rebuild sans lecture publique de Business ou Sale.

La popularité est une mesure collective et non une personnalisation. Les vues de fiches et recherches ayant au moins un résultat sont agrégées par `(site, langue, jour, type, clé)`. La déduplication utilise un HMAC éphémère ; aucune IP, identité CRM/IAM, adresse e-mail, référence de panier ou jeton client n’est persisté. Les aperçus, routes administratives, clients headless et robots connus ne contribuent pas aux compteurs. Sans signal suffisant, l’ordre retombe de façon déterministe sur la première publication puis l’identifiant du produit.

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
