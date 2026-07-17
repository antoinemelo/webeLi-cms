---
title: Opérations — relations, produits, stock et offres
audience:
  - administrator
  - editor
status: current
last_verified: 2026-07-15
source_of_truth: code
source_paths:
  - frontend/admin-vue/src/views/modules/BusinessCrmView.vue
  - frontend/admin-vue/src/views/modules/BusinessCatalogView.vue
  - backend/src/Modules/Business/Services/BusinessOperationsDashboardService.php
  - backend/src/Modules/Sale/Services/SaleInventoryService.php
owners:
  - business
  - sale
document_type: guide
generated: false
---

# Opérations

Opérations regroupe les tâches commerciales qui précèdent ou soutiennent la vente : suivi des Relations, préparation du catalogue, disponibilité, offres, campagnes et Audiences.

## Navigation ordinaire

- **À traiter** présente uniquement des files menant à une résolution : produits incomplets, variantes non vendables, stock faible, commandes en attente de stock, offres à valider ou en conflit et formulaires à rattacher.
- **Relations** rassemble clients, fournisseurs, contacts, mémos et suivis.
- **Produits** réunit contenu, variantes, prix, médias, canaux, complétude et disponibilité.
- **Marketing** rassemble réductions, bundles, campagnes et Audiences selon les permissions.
- **Réglages** contient uniquement les configurations administrables ; les anciennes cartes « Réglages par intention » ont été retirées.

`Segments` n’est pas une entrée de navigation. Le modèle interne reste compatible, mais sa capacité utilisateur s’appelle **Audiences**.

## Stock : une seule vérité transactionnelle

Le catalogue conserve une quantité initiale uniquement pour amorcer une nouvelle variante. Dès que Sale dispose d’un article d’inventaire, son ledger et sa projection sont prioritaires pour Business, Sale et Storefront.

Les valeurs affichées ont un sens distinct :

- **En stock** : quantité physiquement constatée ;
- **Engagé** : quantité réservée par des paniers ou commandes ;
- **Disponible à la vente** : en stock moins engagé ;
- **Entrant** : solde attendu des transferts en cours ;
- **Sur commande** : politique de vente hors stock et délai annoncé.

Une opération ordinaire suit toujours : action → emplacement → quantité → motif → aperçu avant/après → confirmation. Une quantité comptée produit seulement le delta nécessaire. Réception, perte/casse, retour et correction produisent des mouvements typés. Les mouvements du ledger sont immuables au niveau SQLite ; une correction ultérieure est un mouvement compensatoire.

## Offres et Audiences

Une réduction suit le parcours : objectif → périmètre → avantage → période → cumul/priorité → aperçu → activation. L’aperçu compte les produits affectés et signale les chevauchements de portée, canal et période.

Une Audience est une règle explicable, avec un volume et une date de calcul. Elle ne constitue jamais un consentement marketing. Une offre publique peut rester sans Audience.

## Outils avancés

Le ledger détaillé, les réservations, transferts, réconciliations et projections sont réservés à `business.advanced_tools.manage` et aux permissions Sale correspondantes. Le tableau À traiter ne révèle ces diagnostics qu’aux rôles autorisés.

## Limites actuelles

Les réductions catalogue, bundles, campagnes et Audiences disposent d’un parcours démontré. Les tables Sale de promotions panier et coupons ne disposent pas encore d’un parcours d’administration Opérations complet. Les bons cadeaux sont démontrés comme type produit et politique catalogue, pas comme portefeuille financier administrable depuis cette page.
