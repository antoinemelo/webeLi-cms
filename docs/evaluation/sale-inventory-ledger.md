---
title: "Ledger de stock Sale M6"
audience:
  - administrator
  - developer
  - operator
status: accepted
last_verified: 2026-07-14
source_of_truth: manual
source_paths:
  - database/modules/sale.sql
  - backend/src/Modules/Sale/Repositories/SaleInventoryRepository.php
  - backend/src/Modules/Sale/Services/SaleInventoryReconciliationService.php
  - frontend/admin-vue/src/views/modules/SaleStockView.vue
owners:
  - sale
document_type: evaluation
generated: false
---
# Ledger de stock Sale M6

## Décision d’architecture

`sale.sqlite` est l’unique source transactionnelle des quantités. `sale_stock_movements` est le journal immuable ; `sale_inventory_items` est un état matérialisé optimisé mais reconstructible. Business conserve les politiques commerciales (`track_stock`, vente sur commande et délai) et la projection `business_inventory_availability_projections`.

Les anciennes colonnes `business_product_variants.stock_quantity`, `stock_reserved` et la table `business_stock_movements` sont dépréciées. Elles servent uniquement à amorcer les données historiques lors d’une reconstruction from scratch. L’API Business refuse désormais tout nouveau mouvement : les opérations passent par Sale/Stock.

## Conventions

- une réception, un retour, un transfert entrant ou une correction positive porte une quantité positive ;
- une vente, une sortie, un transfert sortant, une correction négative ou une consommation de bundle porte une quantité négative ;
- réservation et libération expliquent le réservé mais ne modifient pas le physique ;
- le physique est la somme des mouvements hors réservation/libération ;
- le réservé est la somme des réservations actives ou confirmées ;
- le disponible vaut `physique - réservé` ;
- les unités sont entières. L’ancien amorçage décimal Business est arrondi à l’unité la plus proche, les demi-unités à l’écart de zéro (`ROUND_HALF_UP`).

Chaque mouvement contient l’item, l’emplacement, la quantité signée, le type, la source, la clé d’idempotence, l’opérateur, la date, la corrélation et le solde physique résultant. Les triggers SQLite refusent mise à jour et suppression.

## Reconstruction et détection

Le seed crée d’abord chaque item à zéro, ajoute un mouvement `initial` traçable, puis matérialise son état. La réconciliation recalcule physique, réservé et disponible et peut réparer le cache. Le gate M6 inspecte le code source et échoue si une mutation de `sale_inventory_items` apparaît hors du repository Inventory ou du service de réconciliation.

## Contrat Shop

Le contrat public versionné `sale.inventory.availability.v1` expose uniquement `in_stock`, `deliverable`, `backorder` ou `unavailable`, ainsi que `last_available` quand la quantité disponible vaut exactement un. Les quantités internes brutes sont retirées du payload public.

## Expérience opérateur

La vue Vente > Stock distingue physique, réservé et disponible. Elle recherche nom, SKU, code-barres ou emplacement, mémorise les filtres, signale rupture/faible/incohérence, montre la source de chaque mouvement et fournit un export soumis à `sale.stock.read`. L’assistant manuel impose item, emplacement, type, quantité, raison, aperçu avant/après puis confirmation. Aucun total courant n’est éditable.
