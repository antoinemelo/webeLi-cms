---
title: "Sale M5.4 — captures, remboursements et réconciliation"
audience:
  - developer
  - administrator
  - operator
status: accepted
last_verified: 2026-07-13
source_of_truth: manual
source_paths:
  - backend/src/Modules/Sale/Services/SalePaymentService.php
  - backend/src/Modules/Sale/Services/SaleOnlinePaymentService.php
  - database/modules/sale.sql
owners:
  - sale
document_type: architecture
generated: false
---
# Sale M5.4 — captures, remboursements et réconciliation

## Objectif

Le paiement local reste un registre financier immuable. Une réponse HTTP incertaine d'un prestataire ne doit jamais provoquer une seconde capture ou un second remboursement, ni être présentée comme un échec financier certain.

## Journal durable des opérations

Une capture est réservée dans `sale_payment_transactions` avec le statut `pending` avant l'appel externe. Un remboursement est réservé de la même manière dans `sale_refunds`. La clé d'idempotence est unique dans le périmètre financier concerné et est transmise sans modification au prestataire.

Les appels réseau s'exécutent hors transaction SQLite. Une réussite finalise l'écriture existante ; un timeout conserve l'opération en attente et programme une reprise exponentielle. Après le nombre maximal d'essais, l'opération rejoint la dead-letter et exige une vérification, sans transformer un résultat inconnu en échec certain. Les événements `requested`, `retry_scheduled`, `completed` et `dead_lettered` alimentent l'outbox transactionnelle.

Les invariants sont les suivants :

- la somme capturée ne dépasse jamais la somme autorisée ;
- une seconde capture n'est possible que si le provider annonce `multiple_capture` ;
- les remboursements `pending` et `succeeded` réservent tous deux le montant, ce qui empêche deux requêtes concurrentes de dépasser la capture ;
- un remboursement conserve son motif structuré et peut référencer un retour ;
- les totaux de la commande et du payment intent ne sont modifiés qu'après une réussite confirmée.

Le worker périodique utilise `backend/bin/console payments:retry [site_id] [limit]`. La réconciliation planifiée utilise `backend/bin/console payments:reconcile [site_id]`.

## Réconciliation et centre d'exceptions

La réconciliation compare le statut, le montant, la devise, les captures et les remboursements locaux à l'état relu côté prestataire. Une capture distante manquante localement peut être réparée par l'événement provider normalisé. Une capture locale absente chez le prestataire, une divergence de remboursement, de montant ou de devise exige toujours une intervention humaine.

Chaque résultat persiste une priorité, le montant exposé, une action recommandée et l'obligation éventuelle d'une décision humaine. Le centre d'exceptions de l'écran Paiements présente les dossiers par cause et priorité, avec commande, client, ancienneté et montant. Une résolution manuelle exige une note et conserve l'opérateur et l'horodatage. La prévisualisation de lot refuse de déclarer sûr un ensemble hétérogène ou une divergence financière humaine.

## Exploitation

- Surveiller les compteurs `pending_operations`, les dead-letters et les divergences critiques.
- Rejouer les opérations avec le worker ; ne jamais créer une nouvelle clé pour contourner une opération incertaine.
- Vérifier le relevé du prestataire avant de résoudre une divergence de montant, devise, capture locale ou remboursement.
- La base canonique est `database/modules/sale.sql`. Le projet repartant de zéro, aucune migration n'est créée ni planifiée.
