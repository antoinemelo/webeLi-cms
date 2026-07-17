---
title: E-Commerce dans Ventes
audience:
  - administrator
  - superadministrator
status: draft
last_verified: 2026-07-16
source_of_truth: code
source_paths:
  - backend/src/Modules/Sale/SaleModuleProvider.php
  - backend/src/Application/Api/Admin/SaleEcommerceAdminApiController.php
  - frontend/admin-vue/src/views/modules/SaleEcommerceSettings.vue
owners:
  - business
  - sale
document_type: guide
generated: false
---
# E-Commerce dans Ventes

La configuration des sites e-commerce se trouve sous **Ventes > Réglages > E-Commerce**. Elle présente une matrice site ou sous-site × langue et reflète les canaux publics déjà configurés par les domaines propriétaires.

## Propriété et accès

Il n’existe plus de module Commerce à installer ou activer. L’accès à cette configuration utilise `sale.settings.manage`, comme les autres réglages Ventes. Les anciennes URL `/commerce` et `/modules/commerce` redirigent vers la section canonique ; l’ancienne API `/admin/api/commerce/shops` reste un alias de compatibilité de `/admin/api/sale/ecommerce/shops`.

Une configuration publique signalée comme existante provient des données Sale et des mappings storefront déjà présents. Elle n’est ni créée ni validée par l’ouverture du panneau E-Commerce.

## Propriété des données

- Business/PIM possède les produits et leurs informations de vente.
- Sale possède commandes, paiements, factures, POS, réservations et ledger transactionnel.
- DEC CMS Core possède sites, langues, contenu, menus et routes publiques.
- Ventes orchestre leur configuration visible sans dupliquer ces modèles.

## Blueprints et routes headless

E-Commerce ne déclare pas un second blueprint Produit, Commande ou Panier : ces structures sont déjà déclarées par leurs propriétaires Business/PIM et Sale. La matrice est un agrégat en lecture seule de `cms_sales_channel_storefronts`, des sites/langues du Core et des canaux Sale.

Les routes headless nécessaires au commerce existent déjà sous leurs propriétaires uniques : `/api/v1/storefront/*` pour les projections publiques du catalogue et `/api/v1/sale/channels/*` pour bootstrap, panier et checkout. Les redéclarer dans une couche de configuration créerait des routes concurrentes.

## Limites actuelles

La matrice est en lecture seule. Les actions Studio et Preview servent à revenir aux surfaces propriétaires. Aucun bouton d’activation de Shop n’est présenté tant que son contrat transactionnel et ses tests ne sont pas livrés.
