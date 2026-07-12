---
title: Inventaire, disponibilité et réservations Sale
audience:
  - developer
  - administrator
  - evaluator
status: stable
last_verified: 2026-07-12
source_of_truth: code
source_paths:
  - database/modules/sale.sql
  - database/modules/business.sql
  - backend/src/Modules/Sale/Repositories/SaleInventoryRepository.php
  - backend/src/Modules/Sale/Services/SaleInventoryService.php
  - backend/src/Modules/Sale/Services/SaleInventoryReconciliationService.php
  - tools/php/tests/unit/sale_inventory_service_test.php
  - tools/php/tests/unit/sale_inventory_reconciliation_test.php
owners:
  - sale
  - business
document_type: architecture
generated: false
---

# Inventaire, disponibilité et réservations Sale

## Audit et source de vérité

L'audit identifie deux familles de quantités historiques : `business_product_variants.stock_quantity/stock_reserved` et `sale_inventory_items.on_hand_quantity/reserved_quantity/available_quantity`. Il identifie aussi deux journaux, `business_stock_movements` et `sale_stock_movements`.

La source transactionnelle unique est désormais **`sale.sqlite`**. Business conserve ses colonnes historiques uniquement pour amorcer un sellable qui n'a encore jamais été matérialisé dans Sale. Dès qu'une projection Sale existe, les mutations de stock Business sont refusées. `business_inventory_availability_projections` est une projection reconstruisible et non une vérité transactionnelle.

Un `sale_inventory_item` associe exactement un `sellable_id` et un `location_id`. Les formules sont déterministes :

```text
stock physique = on_hand_quantity
stock réservé = somme des réservations active ou confirmed
stock disponible = on_hand_quantity - reserved_quantity
```

Un item non suivi ne crée ni réservation ni consommation physique. Un stock négatif est refusé sauf politique explicite `allow_negative` sur l'item.

## Lieux et canaux

Chaque canal est associé à un lieu par `sale_inventory_channel_configs`. Une caisse utilise obligatoirement le `stock_location_id` de son registre et sa session ouverte ; elle ne peut pas consommer le stock d'un autre lieu. Storefront et administration utilisent le lieu configuré pour leur canal. Le retrait local utilise le lieu du canal en v1 ; la livraison ne change pas la source physique.

## Mouvements immuables

Le journal Sale accepte réception, sortie, correction, retour, transfert entrant/sortant, réservation, libération et consommation. Les triggers interdisent toute modification ou suppression. Une clé d'idempotence protège les réceptions, corrections, transferts, retours et consommations contre les retries. Un transfert produit deux écritures liées par `transfer_key`, dans une seule transaction.

## Stratégie de réservation v1

L'ajout ou la modification du panier réalise un contrôle souple et ne réserve rien. L'étape `review` du checkout crée une réservation atomique avec un TTL de 30 minutes. La validation la passe à `confirmed`. Une conversion administrative ou POS effectue réservation et confirmation dans la même transaction de création de commande. La consommation décrémente simultanément le physique et le réservé.

La clé `cart:{cart}:sellable:{sellable}:location:{location}` rend la création rejouable. Les écritures utilisent une mise à jour conditionnelle sur la disponibilité : deux checkouts ne peuvent donc pas réserver le même dernier article. La consommation et la libération revendiquent d'abord l'état de réservation ; un retry ou une reprise après erreur ne décrémente jamais deux fois.

Les réservations expirées, y compris confirmées mais non consommées, sont libérées par la tâche d'expiration existante.

## Types de produits

- Services, numériques et bons cadeaux ne suivent pas de stock physique.
- Un bundle en mode `components` réserve chaque composant requis, multiplié par la quantité du panier.
- Un bundle `virtual` ou `none` ne réserve pas de stock propre.
- Le backorder ne crée jamais de stock négatif : la part physiquement disponible peut être réservée, le solde reste à servir ultérieurement.

## Projection et réconciliation

`SaleInventoryReconciliationService` compare pour chaque item le réservé stocké avec les réservations actives et le physique avec la somme du journal. Il peut réparer uniquement les valeurs dérivées, publie un rapport persistant dans `sale_inventory_reconciliation_runs`, reconstruit la projection Business puis invalide les projections Storefront concernées.

L'API `POST /admin/api/sale/stock/reconciliation` exécute cette tâche. `POST /admin/api/sale/stock/transfers` réalise un transfert idempotent. Une seconde réconciliation sans mutation doit produire zéro écart.
