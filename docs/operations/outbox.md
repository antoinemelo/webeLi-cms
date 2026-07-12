---
title: Outbox transactionnelle
audience:
  - administrator
  - developer
status: draft
last_verified: 2026-07-11
source_of_truth: code
source_paths:
  - backend/src/Service/OutboxService.php
  - backend/src/Infrastructure/Persistence/Sql/SqlOutboxEventRepository.php
  - backend/src/Worker/OutboxWorker.php
  - database/schema/core.sql
  - tools/python/commands/outbox.py
owners:
  - core
document_type: operations
generated: false
---
# Outbox transactionnelle

L’outbox core stocke les événements métier dans `core.sqlite` avant leur
publication asynchrone. Un producteur doit appeler `OutboxService::push()` dans
la même transaction que l’écriture métier quand l’événement dépend de cette
écriture. Si la transaction est annulée, l’événement est annulé avec elle.

Ce socle M0 suppose une instance reconstruite avec le schéma courant. Il ne
fournit pas de migration de données historique pour les anciennes lignes
`outbox_events`.

## Enveloppe

Chaque ligne `outbox_events` contient l’enveloppe minimale suivante :

| Champ | Rôle |
|---|---|
| `event_id` | Identifiant stable unique de l’événement. |
| `event_type` / `topic` | Type métier versionnable ; `topic` reste l’alias compatible pour les webhooks existants. |
| `schema_version` | Version du contrat de payload. |
| `occurred_at` | Date métier de production. |
| `site_id` | Site concerné si pertinent. |
| `correlation_id` | Corrélation entre requête, événement et logs. |
| `causation_id` | Événement parent si pertinent. |
| `aggregate_type` / `aggregate_id` | Agrégat métier source. |
| `payload_json` | Payload métier minimal, sans secret ni PII inutile. |
| `metadata_json` | Métadonnées techniques filtrées. |

## États

| État | Signification |
|---|---|
| `pending` | Disponible au traitement. |
| `processing` | Réservé par un worker jusqu’à `locked_until`. |
| `failed` | Échec temporaire ; `available_at` indique la prochaine tentative. |
| `dead_letter` | Échec terminal ou quarantaine manuelle. |
| `processed` | Traité avec succès. |
| `archived` | Traité puis archivé par politique de conservation. |

Le claim est conditionnel sur `status`, `available_at`, `attempts` et
`locked_until`. Deux workers concurrents ne doivent pas obtenir le même
événement disponible.

## CLI

```bash
python3 tools/cms.py outbox stats
python3 tools/cms.py outbox run --limit 25
python3 tools/cms.py outbox show 123
python3 tools/cms.py outbox retry 123 --reset-attempts
python3 tools/cms.py outbox dead-letter 123 --reason "provider disabled"
python3 tools/cms.py outbox restore 123
python3 tools/cms.py outbox archive --older-than-days 30
```

`stats` expose la profondeur de file, les échecs, les dead-letters, l’événement
ouvert le plus ancien, le dernier état du worker et la dernière erreur provider
webhook connue.

## Reprise

1. Inspecter l’état : `python3 tools/cms.py outbox stats`.
2. Lire l’événement : `python3 tools/cms.py outbox show <id>`.
3. Corriger la cause externe si nécessaire.
4. Relancer : `python3 tools/cms.py outbox retry <id> --reset-attempts`.
5. Traiter un lot : `python3 tools/cms.py outbox run --limit 25`.

Pour isoler un événement dangereux, utiliser `dead-letter`. Pour le rendre à
nouveau traitable, utiliser `restore`.

## Logs

Le worker journalise `request_id`, `event_id`, `event_type`, `correlation_id`,
`causation_id`, `aggregate_type` et `aggregate_id`. Il ne journalise pas le
corps complet `payload_json`. Les messages d’erreur sont tronqués et filtrent
les fragments évidents de secret, token, cookie, authorization ou password.

## Idempotence consommateur

`outbox_consumptions` conserve une clé `(event_id, consumer_key)`. Si un
événement déjà consommé est relancé, le worker le marque traité sans réexécuter
le side effect du consommateur concerné.
