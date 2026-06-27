---
title: API interne Business CRM
audience:
  - developer
  - administrator
  - superadministrator
status: draft
last_verified: 2026-06-27
source_of_truth: contract
source_paths:
  - backend/routes/api.php
  - backend/routes/web.php
  - backend/src/Modules/Business/BusinessModuleProvider.php
  - backend/src/Application/Api/Admin/BusinessCrmApiController.php
  - backend/src/Application/Api/Admin/BusinessMailingApiController.php
  - backend/src/Application/Api/Admin/BusinessMessagingApiController.php
owners:
  - business
document_type: reference
generated: false
---
# API interne Business CRM

Cette surface alimente le back-office. Elle exige une session admin, le contexte de site lorsque necessaire, les protections CSRF des ecritures admin et la permission indiquee par endpoint.

## CRM

| Methode | Route | Permission |
|---|---|---|
| GET | `/admin/api/business/companies` | `business.crm.read` |
| GET | `/admin/api/business/companies/export.csv` | `business.crm.manage` |
| POST | `/admin/api/business/companies` | `business.crm.manage` |
| GET | `/admin/api/business/companies/{id}` | `business.crm.read` |
| PATCH | `/admin/api/business/companies/{id}` | `business.crm.manage` |
| POST | `/admin/api/business/companies/{id}/archive` | `business.crm.manage` |
| GET | `/admin/api/business/contacts` | `business.crm.read` |
| GET | `/admin/api/business/contacts/export.csv` | `business.crm.manage` |
| POST | `/admin/api/business/contacts/import.csv` | `business.crm.manage` |
| POST | `/admin/api/business/contacts` | `business.crm.manage` |
| GET | `/admin/api/business/contacts/{id}` | `business.crm.read` |
| PATCH | `/admin/api/business/contacts/{id}` | `business.crm.manage` |
| POST | `/admin/api/business/contacts/{id}/archive` | `business.crm.manage` |
| GET | `/admin/api/business/tags` | `business.crm.read` |
| POST | `/admin/api/business/tags` | `business.crm.manage` |
| PATCH | `/admin/api/business/tags/{id}` | `business.crm.manage` |
| DELETE | `/admin/api/business/tags/{id}` | `business.crm.manage` |
| POST | `/admin/api/business/tag-links` | `business.crm.manage` |
| DELETE | `/admin/api/business/tag-links` | `business.crm.manage` |
| GET | `/admin/api/business/contacts/{id}/consents` | `business.crm.read` |
| PATCH | `/admin/api/business/contacts/{id}/consents/{channel}` | `business.crm.manage` |

## Memos

| Methode | Route | Permission |
|---|---|---|
| GET | `/admin/api/business/memos` | `business.memo.read` |
| POST | `/admin/api/business/memos` | `business.memo.manage` |
| GET | `/admin/api/business/memos/{id}` | `business.memo.read` |
| PATCH | `/admin/api/business/memos/{id}` | `business.memo.manage` |
| POST | `/admin/api/business/memos/{id}/archive` | `business.memo.manage` |
| GET | `/admin/api/business/memos/{id}/comments` | `business.memo.read` |
| POST | `/admin/api/business/memos/{id}/comments` | `business.memo.manage` |
| GET | `/admin/api/business/memos/{id}/shares` | `business.memo.share` |
| POST | `/admin/api/business/memos/{id}/shares` | `business.memo.share` |
| DELETE | `/admin/api/business/memos/{id}/shares/{shareId}` | `business.memo.share` |
| POST | `/admin/api/business/memos/{id}/public-share` | `business.memo.share` |
| DELETE | `/admin/api/business/memos/{id}/public-share` | `business.memo.share` |

## Mailing et messaging

| Methode | Route | Permission |
|---|---|---|
| GET | `/admin/api/business/mailing/lists` | `business.mailing.read` |
| POST | `/admin/api/business/mailing/lists` | `business.mailing.manage` |
| GET | `/admin/api/business/mailing/lists/{id}` | `business.mailing.read` |
| PATCH | `/admin/api/business/mailing/lists/{id}` | `business.mailing.manage` |
| POST | `/admin/api/business/mailing/lists/{id}/members` | `business.mailing.manage` |
| DELETE | `/admin/api/business/mailing/lists/{id}/members/{contactId}` | `business.mailing.manage` |
| GET | `/admin/api/business/mailing/campaigns` | `business.mailing.read` |
| POST | `/admin/api/business/mailing/campaigns` | `business.mailing.manage` |
| GET | `/admin/api/business/mailing/campaigns/{id}` | `business.mailing.read` |
| PATCH | `/admin/api/business/mailing/campaigns/{id}` | `business.mailing.manage` |
| POST | `/admin/api/business/mailing/campaigns/{id}/preview-recipients` | `business.mailing.read` |
| POST | `/admin/api/business/mailing/campaigns/{id}/enqueue` | `business.mailing.manage` |
| POST | `/admin/api/business/mailing/campaigns/{id}/cancel` | `business.mailing.manage` |
| GET | `/admin/api/business/messaging/providers` | `business.messaging.admin` |
| GET | `/admin/api/business/messaging/outbox` | `business.messaging.admin` |
| POST | `/admin/api/business/messaging/send-test` | `business.messaging.admin` |
| POST | `/admin/api/business/contacts/{id}/messages` | `business.messaging.send` |

## Routes publiques ciblees

| Methode | Route | Usage |
|---|---|---|
| GET | `/business/memos/share/{token}` | Lecture seule d'un memo partage par token secret. |
| GET | `/business/unsubscribe/{token}` | Affichage de confirmation de desabonnement. |
| POST | `/business/unsubscribe/{token}` | Confirmation du desabonnement. |

Ces routes ne sont pas une API headless CRM. Elles sont limitees a un token, non indexables lorsque du contenu partage est affiche, et testees avec token faux ou revoque.
