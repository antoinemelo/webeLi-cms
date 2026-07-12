---
title: Schema base Vente
audience:
  - developer
status: draft
last_verified: 2026-07-10
source_of_truth: code
source_paths:
  - database/modules/sale.sql
  - database/migrations/sale/
  - backend/src/Modules/Sale/Repositories/
  - backend/src/Modules/Sale/Services/
owners:
  - sale
document_type: architecture
generated: false
---
# Schema base Vente

La base Vente est `storage/database/sale.sqlite`. Son schema canonique est `database/modules/sale.sql`. Elle ne declare pas de cle etrangere vers `business.sqlite` : les identifiants Opérations sont conserves comme references stables, puis les donnees utiles sont copiees dans des snapshots JSON ou colonnes transactionnelles.

## Groupes de tables

| Groupe | Tables | Role |
|---|---|---|
| Canaux | `sale_channels`, `sale_channel_catalog_scopes`, `sale_settings` | Configuration de vente par site, type de canal, devise et exposition publique. |
| Clients | `sale_customer_refs` | Snapshot local d'un contact ou d'une entreprise externe. |
| Catalogue snapshot | `sale_catalog_variant_refs` | Cache local d'une variante vendable issue d'Opérations. |
| Paniers | `sale_carts`, `sale_cart_lines`, `sale_cart_adjustments` | Etat modifiable avant checkout. |
| Commandes | `sale_orders`, `sale_order_lines`, `sale_order_adjustments`, `sale_order_tax_lines`, `sale_order_status_history` | Historique transactionnel immuable sur prix et libelles. |
| Paiements | `sale_payment_methods`, `sale_payment_intents`, `sale_payment_transactions`, `sale_payment_allocations` | Providers locaux, transactions et allocations. |
| POS | `sale_pos_registers`, `sale_pos_devices`, `sale_cash_sessions`, `sale_cash_movements` | Caisses, sessions et mouvements cash. |
| Stock | `sale_stock_locations`, `sale_inventory_items`, `sale_stock_reservations`, `sale_stock_movements` | Stock transactionnel, reservations et journal de mouvements. |
| Recus | `sale_receipts` | Recus POS, confirmations et recus de credit. |
| Retours | `sale_returns`, `sale_return_lines`, `sale_refunds` | Retours physiques et remboursements financiers. |
| Promotions v1 | `sale_promotions`, `sale_coupons` | Base future, non moteur promotionnel avance en v1. |
| Robustesse | `sale_idempotency_keys`, `sale_events`, `sale_outbox` | Rejeu controle, journal metier et integration asynchrone. |

## Montants et taxes

Tous les montants sont stockes en unites mineures entieres (`*_minor`) avec devise explicite. Les taux de taxe utilisent des basis points (`tax_rate_basis_points`). Le schema refuse les totaux negatifs et impose `currency = upper(currency)`.

La commande copie les montants calcules depuis le panier :

- `subtotal_minor` ;
- `discount_total_minor` ;
- `tax_total_minor` ;
- `shipping_total_minor` ;
- `grand_total_minor` ;
- `paid_total_minor` ;
- `refunded_total_minor`.

## Snapshots

`sale_order_lines` contient les colonnes auditables de la vente : SKU, nom produit, nom variante, type produit, quantite, prix, taxe et total. `snapshot_json` conserve le detail utile pour relire la vente sans consulter le catalogue courant.

`sale_carts.customer_snapshot_json`, `sale_orders.customer_snapshot_json`, `billing_address_json` et `shipping_address_json` figent les informations client et adresse au moment du workflow.

## Stock transactionnel

`sale_inventory_items` impose :

```text
available_quantity = on_hand_quantity - reserved_quantity
```

Une reservation cree une ligne `sale_stock_reservations` et un mouvement `reservation`. Une liberation ecrit `release`. Un checkout ecrit `sale` avec une quantite negative. Un retour restocke ecrit `return` avec une quantite positive. Le schema refuse un mouvement de stock a zero.

## Idempotence

`sale_idempotency_keys` protege les scopes :

- `cart.add_line` ;
- `checkout.place_order` ;
- `payment.capture` ;
- `pos.complete_sale` ;
- `refund.create`.

La contrainte unique `(site_id, scope, key_hash)` empeche deux executions concurrentes avec la meme cle. `request_hash` permet de refuser la meme cle avec un payload different.

## Events et outbox

`sale_events` accepte les topics metier declares par le module Vente, notamment commande placee, paiement enregistre, paiement echoue, session POS ouverte/fermee et stock reserve/consomme/libere. Chaque event cree une entree `sale_outbox` avec le meme topic. Les consommateurs CRM, CMS ou IA doivent traiter l'outbox sans bloquer le workflow source.

## Seeds

Le schema installe trois canaux :

- `admin-manual`, actif, non public ;
- `pos-main`, brouillon, non public ;
- `web-main`, actif et public pour les scénarios de développement/test.

Un canal ne peut etre public que s'il est de type `ecommerce`.
L'exposition en production reste en plus contrôlée par `APP_PUBLIC_API_MODULE_ROUTES`, désactivé par défaut dans cet environnement.

## Migrations

Les migrations incrementales sont sous `database/migrations/sale/`. Elles doivent rester compatibles avec le rebuild from scratch : le schema complet et les migrations doivent converger vers le meme modele.
