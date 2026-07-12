---
title: Machines à états et snapshots Sale
audience:
  - developer
  - administrator
status: draft
last_verified: 2026-07-12
source_of_truth: manual
source_paths:
  - database/modules/sale.sql
  - database/migrations/sale/0003_state_machines_snapshots.sql
  - backend/src/Modules/Sale/Services/SaleStateMachineService.php
  - backend/src/Modules/Sale/Services/SaleCheckoutService.php
  - backend/src/Modules/Sale/Services/SalePaymentService.php
  - tools/python/validation/database/sale_state_integrity.py
owners:
  - sale
document_type: specification
generated: false
---
# Machines à états et snapshots Sale

## Principe

`SaleStateMachineService` est l'autorité de transition. Les contrôleurs appellent les services métier ; ils ne modifient jamais directement un statut. Chaque écriture utilise le statut et la `version` lus comme préconditions optimistes, puis ajoute une ligne dans `sale_state_transitions` dans la même transaction.

Chaque transition possède un `correlation_id`, propagé au checkout, à la commande, aux événements et à l'outbox. `sale_order_status_history` reste le journal spécialisé lisible des commandes, tandis que `sale_state_transitions` couvre tous les agrégats.

## Machines

| Agrégat | États et transitions autorisées | Préconditions principales | Effets et événement | Permission d'API |
|---|---|---|---|---|
| Panier | `draft → active/cancelled`; `active → abandoned/converted/expired/cancelled`; `abandoned → active/cancelled` | panier actif et version courante pour checkout | `converted_order_id`, historique corrélé, événement `sale.order.placed` au checkout | `sale.carts.manage` / `sale.checkout` |
| Commande | `draft → placed/cancelled`; `placed → confirmed/cancelled`; `confirmed → completed/cancelled` | paiement soldé et fulfillment terminé ou non requis avant `completed`; remboursement préalable avant annulation d'une commande encaissée | horodatage terminal, historique spécialisé et générique; `sale.order.placed` ou `sale.order.cancelled` | `sale.orders.manage` |
| Intention de paiement | `requires_payment → requires_action/authorized/captured/cancelled/failed/expired`; `requires_action → authorized/captured/cancelled/failed/expired`; `authorized → captured/cancelled/failed/expired` | provider compatible, montant et devise de la commande | transaction/allocation atomique; `sale.payment.recorded` ou `sale.payment.failed` | `sale.payments.manage` |
| Fulfillment | `pending → preparing/cancelled`; `preparing → partially_shipped/shipped/cancelled`; `partially_shipped → shipped/cancelled`; `shipped → delivered/returned`; `delivered → returned` | commande non annulée, quantités positives non déjà expédiées | quantités fulfilled, statut fulfillment de commande, horodatages d'expédition/livraison | `sale.orders.manage` |
| Retour | `requested → approved/rejected/cancelled`; `approved → received/cancelled`; `received → completed` | lignes et quantités rattachées à la commande | historique corrélé; restock géré par le workflow inventaire | `sale.refunds.manage` |
| Remboursement | `draft → pending/cancelled`; `pending → succeeded/failed/cancelled` | transaction réussie remboursable, montant cumulé inférieur au paiement | transaction `refund`, total remboursé et événement `sale.refund.created` | `sale.refunds.manage` |

Tous les états terminaux refusent explicitement une nouvelle transition. Une version attendue périmée produit `sale.state.concurrent_modification`.

## Snapshots historiques

Le checkout copie dans Sale : produit, variante, SKU, libellés, prix régulier et final, remise, taxe, devise, identité client ou invitée, adresses et méthode de livraison. `source_cart_id` est unique : même sans clé d'idempotence, deux validations concurrentes ne peuvent pas créer deux commandes.

Après placement, des triggers SQLite interdisent la modification des snapshots client, adresses, livraison et devise de la commande, ainsi que les colonnes transactionnelles et `snapshot_json` des lignes. Les quantités fulfilled/returned restent les seules informations opérationnelles évolutives d'une ligne. Une ligne de commande placée ne peut pas être supprimée.

Le fulfillment copie à son tour l'adresse et la méthode de livraison de la commande. Aucun workflow ne relit le PIM ou le CRM pour réécrire une vente historique.

## Diagnostic non destructif

```bash
python3 tools/cms.py validate --validator SALE_STATE_INTEGRITY
```

Le diagnostic vérifie le schéma, les paniers convertis, la cohérence des totaux de paiement, les horodatages terminaux, les fulfillments et la concordance entre la dernière transition et l'état courant. Il ne modifie aucune donnée et ne propose aucune correction automatique.
