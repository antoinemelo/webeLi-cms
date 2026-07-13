---
title: Contrat transversal SalesChannel
audience:
  - developer
  - administrator
status: stable
last_verified: 2026-07-12
source_of_truth: code
source_paths:
  - database/modules/sale.sql
  - database/modules/business.sql
  - database/schema/core.sql
  - backend/src/Modules/Sale/Services/SalesChannelResolverService.php
  - backend/src/Modules/Sale/Services/SalesChannelIntegrityService.php
owners:
  - sale
  - business
document_type: architecture
generated: false
---
# Contrat transversal SalesChannel

## Autorité et identifiant

`sale_channels.id` est l’identifiant canonique `channel_id`. Le module Sale possède le registre parce que les paniers, commandes, paiements et caisses le référencent déjà. L’identifiant est stable, partagé comme entier entre les bases SQLite, sans clé étrangère inter-base.

Le contrat exposé est `channel_id`, `site_id`, `code`, `type`, `name`, `default_locale`, `default_currency`, `status`, `created_at`, `updated_at`. Les types canoniques sont `storefront`, `pos`, `admin` et `partner`. La colonne historique `channel_type` reste présente pendant la compatibilité : `ecommerce` correspond à `storefront`, et un partenaire conserve temporairement le stockage historique `admin` tout en étant distingué par `channel_kind`.

## Audit des notions existantes

| Domaine | Notion historique | Décision |
|---|---|---|
| CMS | `sites`, `site_domains`, route publique | configuration locale `cms_sales_channel_storefronts` |
| Business/PIM | enums texte `ecommerce`, `pos`, `catalogue`, `admin` dans visibilité, offres et prix | alias local dans `business_sales_channel_configs`, lié au `channel_id` canonique |
| Sale/Pricing | `sale_channels.channel_type`, `currency`, port catalogue | registre canonique ; alias texte traduit à la frontière Business |
| Inventory | lieux de stock sans canal | `sale_inventory_channel_configs` lie canal, lieu et politique |
| POS | `sale_pos_registers.channel_id` et `location_name` | `stock_location_id` obligatoire à la résolution POS ; caisses et terminaux restent locaux au POS |
| Transactions | `sale_carts.channel_id`, `sale_orders.channel_id`, intentions et snapshots | références existantes conservées, sans réattribution |

Les termes `site` restent un contexte éditorial et de routage, pas un synonyme de canal. `source` sur une commande reste une provenance/audit, jamais un identifiant de canal. Aucun `market` ou `store` concurrent n’est introduit.

## Résolution

Le `SalesChannelResolverService` est le seul point de résolution partagé :

- storefront : configuration CMS active et par défaut, ou code explicite ;
- headless : `channel_id` ou code explicite, sinon storefront par défaut ;
- back-office : canal admin explicite ou défaut admin actif ;
- POS : caisse active, canal POS actif et lieu de stock actif.

Une valeur explicite inconnue, inactive, d’un autre site ou du mauvais type produit une erreur. Il n’existe aucun repli silencieux. Les modules lisent leur propre configuration ; le résolveur et le validateur sont des coordinateurs applicatifs, pas des dépendances SQL entre modules.

## Migration sans perte

La migration ajoute `channel_kind` et `is_default` sans renuméroter `sale_channels.id`. Elle transforme `ecommerce` en `storefront`, conserve `pos` et `admin`, puis initialise les configurations locales à partir des canaux existants. Un lieu `channel-default` est créé par site et les caisses existantes y sont reliées. Les alias texte Business existants restent valides.

Le retour arrière applicatif peut ignorer les nouvelles colonnes et tables : les anciennes colonnes ne sont ni supprimées ni réécrites. La suppression physique des ajouts demanderait une reconstruction SQLite et n’est donc pas utilisée comme rollback automatique.

## Intégrité et exploitation

`GET /admin/api/sale/channels/resolve` permet de vérifier la résolution avec `context=storefront|headless|admin|pos`. `GET /admin/api/sale/channels/integrity` contrôle les références inconnues, sites incohérents, types incorrects, devises transactionnelles incohérentes, configurations orphelines et POS sans lieu. Ces routes exigent `sale.channels.manage`.

Invariants : un panier garde un seul `channel_id`, une commande conserve celui de son origine, et une vente POS ne commence pas sans caisse et lieu résolus.
