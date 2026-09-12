---
title: Machines à états et snapshots Sale
audience:
  - developer
  - administrator
status: current
last_verified: 2026-07-15
source_of_truth: manual
source_paths:
  - database/modules/sale.sql
  - database/migrations/sale/0003_state_machines_snapshots.sql
  - backend/src/Modules/Sale/Services/SaleStateMachineService.php
  - backend/src/Modules/Sale/Services/SaleCheckoutService.php
  - backend/src/Modules/Sale/Services/SalePaymentService.php
  - backend/src/Modules/Sale/Services/SaleOrderDossierService.php
  - backend/src/Modules/Sale/Services/SaleDeferredPaymentService.php
  - backend/src/Modules/Sale/Services/SaleOrderDocumentService.php
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
| Commande | `draft → pending_payment/placed/cancelled`; `pending_payment → placed/confirmed/cancelled`; `placed → confirmed/cancelled`; `confirmed → completed/cancelled` | paiement soldé et fulfillment terminé ou non requis avant `completed`; remboursement préalable avant annulation d'une commande encaissée | horodatage terminal, historique spécialisé et générique; `sale.order.placed` ou `sale.order.cancelled` | `sale.orders.manage` |
| Intention de paiement | `requires_payment → requires_action/authorized/partially_captured/captured/cancelled/failed/expired`; `requires_action → authorized/partially_captured/captured/cancelled/failed/expired`; `authorized → partially_captured/captured/cancelled/failed/expired`; `partially_captured → captured/cancelled/failed` | provider compatible, montant et devise de la commande | transaction/allocation atomique; `sale.payment.recorded` ou `sale.payment.failed` | `sale.payments.manage` |
| Fulfillment | `pending → allocated/preparing/blocked/cancelled`; `allocated → preparing/blocked/cancelled`; `preparing → allocated/partially_prepared/partially_shipped/ready_for_pickup/shipped/blocked/cancelled`; `partially_prepared → allocated/preparing/ready_for_pickup/shipped/blocked/cancelled`; `partially_shipped → shipped/blocked/cancelled`; `ready_for_pickup → handed_over/blocked/cancelled`; `shipped → delivered/returned`; `handed_over/delivered → returned`; `blocked → allocated/preparing/cancelled` | commande non annulée, quantités positives non déjà allouées | quantités fulfilled, statut fulfillment de commande, suivi, preuve de remise et horodatages | `sale.fulfillment.manage` |
| Retour | `requested → approved/rejected/cancelled`; `approved → received/cancelled`; `received → completed` | lignes et quantités rattachées à la commande | historique corrélé; restock géré par le workflow inventaire | `sale.returns.manage` |
| Remboursement | `draft → pending/cancelled`; `pending → succeeded/failed/cancelled` | transaction réussie remboursable, montant cumulé inférieur au paiement | transaction `refund`, total remboursé et événement `sale.refund.created` | `sale.refunds.manage` |

Tous les états terminaux refusent explicitement une nouvelle transition. Une version attendue périmée produit `sale.state.concurrent_modification`.

## Traduction opérateur

`SaleOrderDossierService` ne crée aucun état transactionnel. Il traduit le triplet canonique commande/paiement/fulfillment et les backorders en un état lisible et une prochaine action.

| État interne observé | État utilisateur | Prochaine action | Blocage principal |
|---|---|---|---|
| paiement `failed` ou intention `failed/expired` | Paiement à reprendre | envoyer une nouvelle demande | aucun paiement démontré |
| backorder ouvert et paiement non encaissé | En attente de disponibilité | surveiller la disponibilité | stock indisponible |
| paiement `unpaid/pending` | Paiement attendu | envoyer une demande de paiement | livraison interdite |
| paiement `authorized` | Paiement autorisé | capturer quand la commande est prête | capacité et validité provider |
| paiement `partially_paid` | Solde à encaisser | demander le solde | livraison interdite selon politique |
| paiement `paid`, fulfillment `unfulfilled` | À préparer | démarrer la préparation | emplacement requis |
| fulfillment `partially_fulfilled` | Préparation en cours | poursuivre la préparation | quantités restantes |
| fulfillment `fulfilled`, commande non terminée | À clôturer | clôturer la commande | aucun |
| commande `completed/cancelled` | Terminée/Annulée | aucune | état terminal |

Le plan `sale_order_payment_plans` est une politique d’orchestration pour le paiement à disponibilité, l’acompte et le solde. Il ne remplace ni l’état de commande ni l’intention provider. Le passage à disponibilité est idempotent et crée une intention via le contrat provider existant. Une redirection navigateur reste explicitement `payment_proof=false` ; seul un événement provider traité ou un encaissement opérateur autorisé met la commande à `paid`.

## Snapshots historiques

Le checkout copie dans Sale : produit, variante, SKU, libellés, prix régulier et final, remise, taxe, devise, identité client ou invitée, adresses et méthode de livraison. `source_cart_id` est unique : même sans clé d'idempotence, deux validations concurrentes ne peuvent pas créer deux commandes.

Après placement, des triggers SQLite interdisent la modification des snapshots client, adresses, livraison et devise de la commande, ainsi que les colonnes transactionnelles et `snapshot_json` des lignes. Les quantités fulfilled/returned restent les seules informations opérationnelles évolutives d'une ligne. Une ligne de commande placée ne peut pas être supprimée.

Le fulfillment copie à son tour l'adresse et la méthode de livraison de la commande. Aucun workflow ne relit le PIM ou le CRM pour réécrire une vente historique.

Les confirmations, factures, notes de crédit, bons de livraison et tickets POS de `sale_order_documents` sont des snapshots versionnés. Une facture exige `payment_status=paid`, une note de crédit exige un remboursement, un bon de livraison exige une expédition/remise, et un ticket POS final exige une vente POS payée. Les documents émis ne peuvent être ni modifiés ni supprimés ; une correction produit une nouvelle version ou un document correctif.

## Diagnostic non destructif

```bash
python3 tools/cms.py validate --validator SALE_STATE_INTEGRITY
```

Le diagnostic vérifie le schéma, les paniers convertis, la cohérence des totaux de paiement, les horodatages terminaux, les fulfillments et la concordance entre la dernière transition et l'état courant. Il ne modifie aucune donnée et ne propose aucune correction automatique.
