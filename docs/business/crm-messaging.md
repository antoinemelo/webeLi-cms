---
title: Messaging Opérations CRM
audience:
  - administrator
  - superadministrator
  - publisher
  - developer
status: draft
last_verified: 2026-07-07
source_of_truth: code
source_paths:
  - backend/src/Application/Api/Admin/BusinessMessagingApiController.php
  - backend/src/Application/Api/Admin/BusinessMailingApiController.php
  - backend/src/Modules/Business/Services/BusinessMessagingService.php
  - backend/src/Modules/Business/Services/BusinessMessagingProviderManager.php
  - backend/src/Modules/Business/Services/BusinessConsentService.php
  - backend/src/Modules/Business/Messaging/LogOnlyMessagingProvider.php
  - backend/src/Modules/Business/Messaging/WhatsAppCloudApiProvider.php
  - backend/src/Modules/Business/Messaging/TelegramBotProvider.php
owners:
  - business
document_type: guide
generated: false
---
# Messaging Opérations CRM

Le messaging CRM couvre les messages directs, les messages de campagne simple et leur suivi dans l'outbox. Il est volontairement abstrait derriere des providers afin d'eviter des appels disperses dans l'interface.

## Canaux

Canaux declares :

- `email` ;
- `whatsapp` ;
- `telegram`.

Un canal declare n'est pas forcement utilisable. Il faut aussi un provider actif, une configuration valide et un consentement compatible pour le contact.

## Consentement

Statuts de consentement :

- `unknown` : pas de preuve exploitable ;
- `opted_in` : envoi autorise pour le canal concerne ;
- `opted_out` : envoi refuse.

Pour les messages marketing, l'absence d'opt-in doit bloquer l'envoi. Les messages transactionnels ou administratifs ne doivent pas etre ajoutes sans regle metier explicite et auditee.

## Providers

| Provider | Usage |
|---|---|
| `log_only` | Journalise une intention d'envoi sans contacter un service externe. |
| email runtime | Utilise le mailer applicatif lorsque aucun provider email explicite n'est configure. |
| WhatsApp Cloud API | Utilise uniquement une configuration officielle et active. |
| Telegram Bot | Utilise uniquement un bot configure et un destinataire compatible. |

Les providers externes doivent rester configurables et testables. Les tests automatises ne doivent pas envoyer de message reel vers un service tiers.

## Outbox

La creation d'un message produit une entree dans l'outbox avec :

- canal ;
- destinataire ;
- sujet ou corps ;
- relation cible ;
- statut ;
- utilisateur emetteur ;
- date d'envoi ou erreur.

L'outbox sert a retrouver les messages et a diagnostiquer les echecs. Les erreurs provider doivent etre stockees de facon lisible sans exposer de secret.

## Messages depuis une relation

1. Ouvrez une relation.
2. Verifiez les coordonnees et consentements.
3. Choisissez le canal disponible.
4. Redigez le message.
5. Envoyez uniquement si le provider et le consentement sont valides.

Si le canal n'est pas configure ou si le consentement est insuffisant, l'API doit retourner une erreur explicite.

## Mailing simple

Le mailing utilise les listes, campagnes et destinataires eligibles. Avant mise en file :

1. creer ou choisir une liste ;
2. verifier les membres ;
3. lancer une preview ;
4. corriger les opt-in manquants ;
5. mettre en file les messages valides.

La mise en file ne garantit pas la livraison externe. Elle indique seulement que DEC CMS a cree les messages a traiter par le provider.

## Limites

Le module ne fournit pas de designer newsletter, statistiques marketing avancees, A/B testing, segmentation complexe ou connecteur SaaS obligatoire. Ces fonctions doivent etre ajoutees comme extensions separees.
