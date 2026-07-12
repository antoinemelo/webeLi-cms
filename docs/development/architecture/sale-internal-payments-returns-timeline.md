---
title: Vente interne, paiements, retours et chronologie
audience:
  - developer
  - administrator
status: draft
last_verified: 2026-07-12
source_of_truth: manual
source_paths:
  - database/migrations/sale/0004_internal_sales_timeline.sql
  - backend/src/Modules/Sale/Services/SalePaymentService.php
  - backend/src/Modules/Sale/Services/SaleReceiptService.php
  - backend/src/Modules/Sale/Services/SaleReturnService.php
  - backend/src/Modules/Sale/Services/SaleOrderTimelineService.php
  - tools/php/tests/unit/sale_internal_sales_test.php
owners:
  - sale
document_type: specification
generated: false
---
# Vente interne, paiements, retours et chronologie

## Écritures financières

Une vente accepte plusieurs règlements locaux et additionne uniquement les allocations de transactions `payment` ou `capture` réussies. Les providers v1 sont `cash`, `bank_transfer`, `manual_card` et `external_terminal`. L'intention, l'autorisation éventuelle, la capture ou le règlement local, le remboursement, l'annulation avant capture et l'échec restent des états ou transactions distincts.

Les tables `sale_payment_transactions`, `sale_payment_allocations`, `sale_refunds` et `sale_financial_corrections` refusent toute suppression par trigger. Une correction est une écriture signée, motivée, corrélée et idempotente ; elle ne réécrit jamais une transaction existante.

Un remboursement est limité au reliquat remboursable de sa transaction source. Son workflow `draft → pending → succeeded/failed` et sa transaction financière sont enregistrés dans la même opération métier.

## Rejouage

Les paiements et remboursements utilisent `SaleIdempotencyService`. Les retours stockent une clé et le hash exact de la requête : le même payload rejoue la réponse, tandis qu'un payload différent avec la même clé échoue. Une intention déjà annulée rejoue son résultat ; une intention capturée refuse l'annulation.

## Retours et stock

`SaleReturnService` valide les quantités par ligne, puis impose le cycle demandé, approuvé, reçu et terminé. La complétion incrémente la quantité retournée avec contrôle concurrent et écrit, si demandé, un mouvement de restock référencé au retour.

Les droits sont séparés : `sale.pos.use`, `sale.payments.manage`, `sale.returns.manage` et `sale.refunds.manage`. Les routes de lecture conservent leurs permissions dédiées.

## Reçus

`SaleReceiptService` persiste un snapshot FR ou EN. Le numéro dépend de la commande, de la langue et des totaux payé/remboursé : le rejeu d'un même état retourne le même reçu ; un changement financier crée une nouvelle preuve. Le snapshot contient l'opérateur, les lignes, les taxes, les paiements réussis et les remboursements, mais aucun payload provider.

## Client et chronologie

Une vente invitée conserve son snapshot client. `SaleOrderService::reconcileCustomer()` ajoute ensuite les références CRM et une ligne d'audit, puis vérifie que le snapshot historique est strictement inchangé.

`SaleOrderTimelineService` fusionne et trie transitions, paiements, corrections, mouvements de stock, retours, remboursements, événements d'intégration et rapprochements CRM. Les données techniques sensibles des événements et providers ne sont pas exposées.

## Contrôle

```bash
php tools/php/tests/unit/sale_internal_sales_test.php
php tools/php/tests/unit/sale_admin_api_controller_test.php
php tools/php/tests/unit/sale_module_contracts_test.php
python3 tools/cms.py validate --validator SALE_STATE_INTEGRITY
```

Les rapports Sale existants restent des vues opérationnelles simples. Ils ne constituent ni un grand livre ni une comptabilité légale.
