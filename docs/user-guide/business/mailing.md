---
title: Mailing et consentements Business CRM
audience:
  - administrator
  - superadministrator
  - publisher
status: draft
last_verified: 2026-06-27
source_of_truth: manual
source_paths:
  - backend/src/Application/Api/Admin/BusinessMailingApiController.php
  - backend/src/Application/Api/Admin/BusinessMessagingApiController.php
  - backend/src/Application/Frontend/BusinessUnsubscribeController.php
  - backend/src/Modules/Business/Services/BusinessConsentService.php
  - backend/src/Modules/Business/Services/BusinessMailingService.php
  - backend/routes/api.php
  - backend/routes/web.php
owners:
  - business
document_type: procedure
generated: false
---
# Mailing et consentements Business CRM

## Resultat attendu

Preparer une diffusion simple vers des contacts consentants, verifier les destinataires avant mise en file et conserver un historique d'envoi dans l'outbox.

## Droits

- Lire listes et campagnes : `business.mailing.read`
- Gerer listes, membres, campagnes et enqueue : `business.mailing.manage`
- Envoyer un message contact direct : `business.messaging.send`

## Consentements

Un contact doit disposer d'un canal compatible et d'un consentement `opt_in` pour recevoir un message marketing. Les canaux v1 sont `email`, `whatsapp` et `telegram`.

Un `opt_out` ou l'absence de consentement exclut le contact de la preview et de l'enqueue. Le desabonnement public utilise :

- `GET /business/unsubscribe/{token}` pour afficher la confirmation ;
- `POST /business/unsubscribe/{token}` pour confirmer.

## Mailing simple

1. Creez une liste de mailing sur le canal voulu.
2. Ajoutez des contacts a la liste.
3. Creez une campagne en brouillon avec sujet et corps.
4. Lancez la preview des destinataires pour verifier les opt-in.
5. Lancez l'enqueue uniquement si la preview est correcte.

L'enqueue cree des messages dans `crm_message_outbox`. Il ne garantit pas a lui seul une livraison externe : celle-ci depend du provider configure et de ses propres regles.

## WhatsApp et Telegram

WhatsApp doit passer par l'API officielle WhatsApp Business Platform / Cloud API ou un provider explicitement configure. Telegram doit passer par Bot API ou un provider officiel/configure, avec un identifiant de chat valide ou une interaction prealable.

Sans configuration, ces providers restent desactives. Cette absence ne bloque ni le CRM, ni les consentements, ni les tests.

## Limites

Le module ne fournit pas d'outil de design newsletter, d'A/B testing, de segmentation avancee ou de statistiques marketing completes.
