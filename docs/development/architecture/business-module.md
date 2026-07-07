---
title: Architecture du module Opérations CRM
audience:
  - developer
  - administrator
status: draft
last_verified: 2026-06-27
source_of_truth: code
source_paths:
  - backend/src/Modules/Business/BusinessModuleProvider.php
  - backend/src/Modules/Business/Repositories
  - backend/src/Modules/Business/Services
  - backend/src/Modules/Business/Messaging
  - backend/src/Application/Api/Admin/BusinessCrmApiController.php
  - backend/src/Application/Api/Admin/BusinessMailingApiController.php
  - backend/src/Application/Api/Admin/BusinessMessagingApiController.php
  - database/modules/business.sql
  - backend/routes/api.php
  - backend/routes/web.php
owners:
  - business
document_type: architecture
generated: false
---
# Architecture du module Opérations CRM

## Perimetre

`business` est un module systeme leger livre avec le CMS. Il ajoute une base `business.sqlite` pour le CRM, les memos, les consentements, le mailing simple et l'outbox messaging.

Il ne contient pas de commerce, comptabilite, paie, pipeline commercial, taches ou rappels.

## Base SQLite

La base `storage/database/business.sqlite` est reconstruite depuis `database/modules/business.sql`. La premiere migration est `database/migrations/business/0001_init.sql`.

Groupes de tables :

- `business_companies`, `business_contacts`, `business_tags`, `business_tag_links` ;
- `crm_memos`, `crm_memo_shares`, `crm_memo_comments` ;
- `crm_contact_channels`, `crm_consents` ;
- `crm_mailing_lists`, `crm_mailing_list_members`, `crm_mailings`, `crm_mailing_recipients` ;
- `crm_messaging_providers`, `crm_message_templates`, `crm_message_outbox`, `crm_message_delivery_events`.

Les references vers le noyau restent sous forme d'identifiants (`site_id`, `iam_user_id`) afin d'eviter une contrainte cross-database SQLite.

## Couches PHP

- Repositories : acces SQLite, normalisation, contraintes locales.
- Services CRM : orchestration metier simple, entreprise systeme `Individus`, liaison IAM unique.
- Services memo : creation, partage IAM, partage public par token hashe.
- Services consentement/mailing : eligibility, preview, enqueue.
- Services messaging : outbox et resolution de providers.
- Controleurs admin : API interne `/admin/api/business/*`, avec permissions IAM.
- Controleurs frontend : partage public de memo et desabonnement.

## Messaging

Le provider `log_only` est utilisable sans secret externe pour les tests. Les providers WhatsApp Cloud et Telegram Bot valident leur configuration mais ne doivent pas servir de contournement non officiel.

Un message direct ou mailing doit passer par un canal et un consentement compatibles avant creation dans l'outbox.

## Routes

La surface admin reelle est documentee dans [API interne Opérations CRM](../../api/admin-internal/business-crm.md). Les routes publiques sont limitees a :

- `GET /business/memos/share/{token}` ;
- `GET /business/unsubscribe/{token}` ;
- `POST /business/unsubscribe/{token}`.

## Limites v1

Les champs et tables sont volontairement preparatoires pour de futures extensions, mais aucune commande, facture, paie, relance ou pipeline n'est actif dans cette version.
