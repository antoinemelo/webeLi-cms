---
title: Commandes Vente
audience:
  - administrator
  - superadministrator
status: draft
last_verified: 2026-07-12
source_of_truth: code
source_paths:
  - frontend/admin-vue/src/views/modules/SaleView.vue
  - backend/src/Application/Api/Admin/SaleAdminApiController.php
  - backend/src/Modules/Sale/Services/SaleOrderService.php
  - backend/src/Modules/Sale/Services/SalePaymentService.php
  - backend/src/Modules/Sale/Services/SaleInventoryService.php
  - backend/src/Modules/Sale/Services/SaleReceiptService.php
  - backend/src/Modules/Sale/Services/SaleReturnService.php
  - backend/src/Modules/Sale/Services/SaleOrderTimelineService.php
owners:
  - sale
document_type: guide
generated: false
---
# Commandes Vente

Une commande Vente est un snapshot transactionnel. Elle conserve ce qui a ete vendu, a quel prix, avec quelle taxe, quel paiement et quels mouvements de stock.

## Liste des commandes

L'onglet **Commandes** affiche les commandes recentes avec recherche, pagination, filtres, choix de colonnes, import/export et actions secondaires. Les statuts importants sont :

- commande : `draft`, `placed`, `confirmed`, `completed`, `cancelled` ;
- paiement : `unpaid`, `pending`, `authorized`, `partially_paid`, `paid`, `partially_refunded`, `refunded`, `failed` ;
- fulfillment : `not_required`, `unfulfilled`, `partially_fulfilled`, `fulfilled`, `returned`.

## Fiche commande

La fiche commande doit etre lue comme une preuve de vente. Les lignes affichent les valeurs copiees au checkout :

- SKU ;
- nom produit ;
- nom variante ;
- quantite ;
- prix regulier ;
- prix final ;
- remise ;
- taxe ;
- total ligne.

Ces informations ne sont pas recalculees depuis le catalogue courant.

## Paiement manuel

Pour enregistrer un paiement :

1. ouvrez la commande ;
2. choisissez le moyen de paiement ;
3. indiquez le montant ;
4. validez.

Le montant ne peut pas depasser le solde restant. Le paiement cree une transaction et met a jour le statut de paiement de la commande.

Les encaissements peuvent être fractionnés entre espèces, virement, paiement manuel et terminal externe. Une correction opérateur ajoute une écriture dédiée et justifiée ; elle ne modifie ni ne supprime la transaction d'origine. Une intention encore non capturée peut être annulée, tandis qu'une intention capturée doit passer par un remboursement.

## Remboursement

Un remboursement part d'une transaction de paiement. Il ne supprime pas le paiement original et ne modifie pas les lignes vendues. Il ajoute une trace de remboursement, met a jour le total rembourse et peut produire un recu de credit selon le flux utilise.

Le montant cumulé remboursé ne peut jamais dépasser le montant réussi de la transaction source. Une clé d'idempotence permet de rejouer la demande sans créer un second remboursement.

## Retours

Un retour suit `requested → approved → received → completed`. Les quantités sont contrôlées par rapport aux lignes historiques. La fin du workflow augmente `returned_quantity` et, lorsque la ligne le demande, écrit un mouvement de stock `return`. La permission `sale.returns.manage` est distincte de `sale.refunds.manage` : un retour physique n'autorise pas implicitement un mouvement financier.

## Reçu et chronologie

Le reçu FR ou EN possède un numéro stable pour un état financier donné. Il fige la date, l'opérateur, les lignes, taxes, paiements et remboursements sans recopier les payloads provider. Une évolution des totaux produit une nouvelle version, notamment un reçu de crédit après remboursement.

La chronologie agrège les transitions, paiements, corrections, mouvements de stock, retours, remboursements, événements d'intégration et rapprochements client. Elle sert à expliquer la commande ; elle ne constitue pas une comptabilité légale.

## Vente invitée et rapprochement CRM

Une commande peut être créée sans identifiant CRM. Le rapprochement ultérieur renseigne les références entreprise/contact et ajoute une trace corrélée, sans modifier `customer_snapshot_json` : l'identité connue au moment de la vente reste intacte.

## Annulation

Annuler une commande placee change son statut en `cancelled`. Les reservations non consommees peuvent etre liberees. Les ventes deja consommees et les paiements existants restent historises : l'annulation ne remplace pas un remboursement.

## Stock

Lorsqu'une ligne porte une variante avec suivi de stock :

1. l'ajout au panier reserve la quantite disponible ;
2. le checkout consomme la reservation ;
3. un mouvement `sale` diminue le stock sur main ;
4. un retour restocke ecrit un mouvement `return`.

Une modification manuelle de stock doit passer par les ajustements Vente afin de conserver un mouvement.

## Exports

Les exports commande servent au controle operationnel et a l'audit :

- commandes ;
- lignes de commandes ;
- paiements ;
- retours et remboursements.

Les prix d'achat de ligne restent masques par defaut. Ils ne doivent etre exposes qu'avec la permission appropriee et un parametre explicite.

## Bonnes pratiques

- Utilisez une cle d'idempotence pour les actions automatisees.
- Ne modifiez pas le catalogue pour corriger une commande deja placee.
- Preferez un remboursement ou une note interne a une suppression de trace.
- Verifiez le canal et la devise avant une saisie manuelle.
