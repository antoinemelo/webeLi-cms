---
title: "Réservations et disponibilité Sale M6.2"
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
  - backend/src/Modules/Sale/Services/SaleInventoryService.php
  - frontend/admin-vue/src/views/modules/SaleReservationsView.vue
owners:
  - sale
document_type: evaluation
generated: false
---
# M6.2 — Réservations, disponibilité et backorder

## Décision

Sale conserve l’unique état canonique. Une réservation physique protège une quantité disponible par mise à jour conditionnelle atomique. Un backorder n’est jamais confondu avec du stock : il est enregistré dans `sale_stock_backorders`, avec quantité, délai, canal, panier ou commande et cycle de vie propre.

La politique par défaut est sûre et visible : le storefront réserve au début du checkout pendant 30 minutes, dans une durée maximale de deux heures. Le POS réserve au placement de commande sur l’emplacement de la caisse et refuse le backorder implicite. Le retrait utilise l’emplacement choisi et refuse également le backorder implicite.

Les autres déclencheurs configurables sont le placement de commande, l’autorisation du paiement et la capture. Une politique tardive augmente le risque d’échec au paiement ; elle doit donc être choisie explicitement par un opérateur disposant de `sale.settings.manage`.

## Cycle de vie et reprise

Les états physiques sont `active`, `confirmed`, `consumed`, `released`, `expired` et `cancelled`. Les transitions terminales utilisent un claim conditionnel : une consommation ne peut pas être libérée et un worker rejoué ne libère jamais deux fois. Le renouvellement n’est accepté que dans sa fenêtre, sans dépasser `max_expires_at`.

Le worker périodique est :

```text
php backend/bin/console worker:reservations [site_id]
```

En cas de perte de disponibilité, l’API répond en conflit avec le sellable concerné, conserve le panier et propose réduction de quantité, autre variante, backorder explicite ou retrait de ligne.

## Expérience

Le Shop, le panier et le checkout utilisent le contrat `sale.inventory.availability.v1` et les libellés « En stock », « Sur commande » et « Indisponible ». La quantité exacte disponible reste masquée par défaut ; « dernier disponible » reste un indicateur dédié. Le panier affiche le délai de backorder et l’heure d’expiration de sa protection.

Le back-office expose `/admin/app/sale/reservations` : actives, expirant bientôt, bloquées, liées à une commande et backorders, avec TTL humain. La libération exige `sale.stock.manage` et une raison, enregistrée avec l’opérateur dans le ledger immuable.

## Preuves

- `sale_reservation_lifecycle_test.php` : dernier article/deux clients, retry, renouvellement borné, double expiration, backorder, POS et interdiction de libérer une consommation.
- `sale_admin_api_controller_test.php` : permissions, politique, renouvellement et libération auditée.
- `sale_public_api_handler_test.php` : disponibilité, expiration et solutions de reprise dans le panier.
- `sale-reservations.spec.ts` : décision opérateur, TTL humain, mobile, libération et configuration.
