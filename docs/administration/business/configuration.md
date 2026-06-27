---
title: Configurer le module Business CRM
audience:
  - administrator
  - superadministrator
status: draft
last_verified: 2026-06-27
source_of_truth: code
source_paths:
  - backend/src/Modules/Business/BusinessModuleProvider.php
  - backend/src/Modules/Business/Services/BusinessMessagingProviderManager.php
  - backend/src/Modules/Business/Messaging/WhatsAppCloudApiProvider.php
  - backend/src/Modules/Business/Messaging/TelegramBotProvider.php
  - database/modules/business.sql
  - database/migrations/business/0001_init.sql
owners:
  - business
document_type: guide
generated: false
---
# Configurer le module Business CRM

## Base et module

Le module systeme `business` utilise une base SQLite dediee : `storage/database/business.sqlite`. Le schema de reference est `database/modules/business.sql` et les migrations incrementales sont sous `database/migrations/business/`.

Depuis une installation de developpement, verifiez le plan sans mutation :

```bash
python3 tools/cms.py migrate --module business --plan
```

## Permissions IAM

| Permission | Usage |
|---|---|
| `business.crm.read` | Lire entreprises, contacts, tags, consentements et donnees CRM. |
| `business.crm.manage` | Creer, modifier, archiver, importer et exporter le CRM. |
| `business.memo.read` | Lire les memos accessibles. |
| `business.memo.manage` | Creer, modifier, commenter et archiver les memos. |
| `business.memo.share` | Creer ou revoquer des partages internes et publics. |
| `business.mailing.read` | Lire listes, campagnes et historiques. |
| `business.mailing.manage` | Gerer listes, membres, campagnes et enqueue. |
| `business.messaging.send` | Preparer un message direct apres controle du consentement. |
| `business.messaging.admin` | Consulter providers, outbox et envoyer un test. |

Ces permissions sont verifiees cote backend. Masquer une action dans l'interface ne suffit pas.

## Providers messaging

Les providers `log_only` permettent de tester le flux sans API externe. Les providers WhatsApp et Telegram restent desactives tant que leur configuration n'est pas complete.

Variables lues par les providers runtime :

| Variable | Usage |
|---|---|
| `BUSINESS_WHATSAPP_ENABLED` | Active explicitement le provider WhatsApp runtime. |
| `BUSINESS_WHATSAPP_PHONE_NUMBER_ID` | Identifiant de numero WhatsApp Business. |
| `BUSINESS_WHATSAPP_ACCESS_TOKEN` | Token API WhatsApp, a fournir par l'environnement uniquement. |
| `BUSINESS_WHATSAPP_API_VERSION` | Version d'API Meta si necessaire. |
| `BUSINESS_TELEGRAM_ENABLED` | Active explicitement le provider Telegram runtime. |
| `BUSINESS_TELEGRAM_BOT_TOKEN` | Token de bot Telegram, a fournir par l'environnement uniquement. |

Ne stockez pas de token externe dans le code, les seeds ou la documentation. Si un provider est persiste en base, `secret_ref` doit pointer vers une reference d'environnement, pas vers une valeur secrete.

## Logs et erreurs

L'outbox conserve l'etat du message, les tentatives et la derniere erreur. Les evenements de livraison journalisent les transitions `queued`, `sent`, `failed` ou `skipped`. Le provider log-only journalise un hash du destinataire plutot que la valeur brute.

## Limites d'exploitation

Le module n'ajoute pas de worker autonome ni de connecteur SaaS obligatoire. L'envoi reel depend d'une orchestration et d'un provider compatibles avec les regles legales et les politiques des plateformes.
