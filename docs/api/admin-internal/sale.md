---
title: API interne Vente
audience:
  - developer
  - administrator
  - superadministrator
status: draft
last_verified: 2026-07-10
source_of_truth: contract
source_paths:
  - backend/src/Modules/Sale/SaleModuleProvider.php
  - backend/src/Application/Api/Admin/SaleAdminApiController.php
  - backend/routes/api.php
owners:
  - sale
document_type: reference
generated: false
---
# API interne Vente

Cette surface alimente le back-office Vente. Elle exige une session admin, le contexte de site et les permissions `sale.*`. Les routes sont declarees par `SaleModuleProvider`; le router central peut exposer un sous-ensemble selon l'etat d'integration.

## Schema et tableau de bord

| Methode | Route | Permission |
|---|---|---|
| GET | `/admin/api/sale/schema` | `sale.read` |
| GET | `/admin/api/sale/dashboard` | `sale.read` |
| GET | `/admin/api/sale/settings` | `sale.settings.manage` |

## Canaux

| Methode | Route | Permission |
|---|---|---|
| GET | `/admin/api/sale/channels` | `sale.settings.manage` |
| POST | `/admin/api/sale/channels` | `sale.settings.manage` |
| GET | `/admin/api/sale/channels/{id}` | `sale.settings.manage` |
| PATCH | `/admin/api/sale/channels/{id}` | `sale.settings.manage` |
| POST | `/admin/api/sale/channels/{id}/archive` | `sale.settings.manage` |

## Paniers et commandes

| Methode | Route | Permission |
|---|---|---|
| GET | `/admin/api/sale/carts` | `sale.orders.read` |
| POST | `/admin/api/sale/carts` | `sale.orders.manage` |
| GET | `/admin/api/sale/carts/{id}` | `sale.orders.read` |
| POST | `/admin/api/sale/carts/{id}/lines` | `sale.orders.manage` |
| PATCH | `/admin/api/sale/carts/{id}/lines/{line_id}` | `sale.orders.manage` |
| DELETE | `/admin/api/sale/carts/{id}/lines/{line_id}` | `sale.orders.manage` |
| POST | `/admin/api/sale/carts/{id}/recalculate` | `sale.orders.manage` |
| POST | `/admin/api/sale/carts/{id}/checkout` | `sale.orders.manage` |
| GET | `/admin/api/sale/orders` | `sale.orders.read` |
| POST | `/admin/api/sale/orders` | `sale.orders.manage` |
| GET | `/admin/api/sale/orders/{id}` | `sale.orders.read` |
| PATCH | `/admin/api/sale/orders/{id}` | `sale.orders.manage` |
| POST | `/admin/api/sale/orders/{id}/cancel` | `sale.orders.manage` |
| GET | `/admin/api/sale/orders/{id}/events` | `sale.orders.read` |
| GET | `/admin/api/sale/orders/{id}/receipt` | `sale.orders.read` |

## Paiements et remboursements

| Methode | Route | Permission |
|---|---|---|
| GET | `/admin/api/sale/payments` | `sale.payments.read` |
| GET | `/admin/api/sale/payment-methods` | `sale.payments.read` |
| POST | `/admin/api/sale/payment-methods` | `sale.payments.manage` |
| POST | `/admin/api/sale/orders/{id}/payments` | `sale.payments.manage` |
| GET | `/admin/api/sale/orders/{id}/payments` | `sale.payments.read` |
| POST | `/admin/api/sale/payments/{transaction_id}/refund` | `sale.refunds.manage` |

## POS

| Methode | Route | Permission |
|---|---|---|
| GET | `/admin/api/sale/pos/bootstrap` | `sale.pos.use` |
| GET | `/admin/api/sale/pos/catalog` | `sale.pos.use` |
| GET | `/admin/api/sale/pos/variants` | `sale.pos.use` |
| GET | `/admin/api/sale/pos/registers` | `sale.pos.use` |
| POST | `/admin/api/sale/pos/sessions/open` | `sale.cash.manage` |
| POST | `/admin/api/sale/pos/sessions/{id}/close` | `sale.cash.manage` |
| POST | `/admin/api/sale/pos/carts` | `sale.pos.use` |
| POST | `/admin/api/sale/pos/carts/{id}/lines` | `sale.pos.use` |
| PATCH | `/admin/api/sale/pos/carts/{id}/lines/{line_id}` | `sale.pos.use` |
| DELETE | `/admin/api/sale/pos/carts/{id}/lines/{line_id}` | `sale.pos.use` |
| POST | `/admin/api/sale/pos/carts/{id}/adjustments` | `sale.pos.use` |
| POST | `/admin/api/sale/pos/checkout` | `sale.pos.use` |
| GET | `/admin/api/sale/pos/orders/{id}/receipt` | `sale.pos.use` |
| POST | `/admin/api/sale/pos/orders/{id}/receipt/email` | `sale.pos.use` |

## Stock

| Methode | Route | Permission |
|---|---|---|
| GET | `/admin/api/sale/stock` | `sale.stock.read` |
| GET | `/admin/api/sale/stock/items` | `sale.stock.read` |
| POST | `/admin/api/sale/stock/adjustments` | `sale.stock.manage` |
| GET | `/admin/api/sale/stock/movements` | `sale.stock.read` |

## Imports, exports et rapports

| Methode | Route | Permission |
|---|---|---|
| GET | `/admin/api/sale/catalog/export.pdf` | `sale.read` |
| GET | `/admin/api/sale/export/orders.csv` | `sale.reports.read` |
| GET | `/admin/api/sale/export/order-lines.csv` | `sale.reports.read` |
| GET | `/admin/api/sale/export/payments.csv` | `sale.reports.read` |
| GET | `/admin/api/sale/export/pos-sessions.csv` | `sale.reports.read` |
| GET | `/admin/api/sale/export/stock-movements.csv` | `sale.stock.read` |
| GET | `/admin/api/sale/export/returns-refunds.csv` | `sale.reports.read` |
| POST | `/admin/api/sale/import/stock/preview` | `sale.stock.manage` |
| POST | `/admin/api/sale/import/stock/apply` | `sale.stock.manage` |
| GET | `/admin/api/sale/reports/daily` | `sale.reports.read` |
| GET | `/admin/api/sale/reports/orders` | `sale.reports.read` |
| GET | `/admin/api/sale/reports/channels` | `sale.reports.read` |
| GET | `/admin/api/sale/reports/payment-methods` | `sale.reports.read` |
| GET | `/admin/api/sale/reports/pos-sessions` | `sale.reports.read` |
| GET | `/admin/api/sale/reports/stock` | `sale.reports.read` |
| GET | `/admin/api/sale/reports/refunds` | `sale.reports.read` |

## Contextes IA locaux

| Methode | Route | Permission |
|---|---|---|
| GET | `/admin/api/sale/ai/schema` | `sale.reports.read` |
| GET | `/admin/api/sale/ai/orders/{id}/summary-context` | `sale.orders.read` |
| GET | `/admin/api/sale/ai/pos/day-summary-context` | `sale.reports.read` |
| GET | `/admin/api/sale/ai/customers/{type}/{id}/analysis-context` | `sale.orders.read` |
| GET | `/admin/api/sale/ai/unpaid-orders-context` | `sale.reports.read` |

Ces endpoints preparent des contextes locaux. Ils ne declenchent aucun appel IA externe et retournent `external_ai_allowed=false`.

## Idempotence

Les workflows critiques acceptent une cle via payload ou en-tete `Idempotency-Key` selon le endpoint :

- ajout de ligne panier ;
- checkout ;
- paiement ;
- remboursement ;
- checkout POS.

Une meme cle avec le meme payload rejoue la reponse. Une meme cle avec un payload different est refusee.

## API publique e-commerce optionnelle

Les routes `/api/v1/sale/channels/{code}/...` ne sont pas des routes admin. Elles restent anonymes mais ne fonctionnent que pour un canal `ecommerce`, actif et public. Elles n'exposent ni prix d'achat, ni listing public de commandes.
