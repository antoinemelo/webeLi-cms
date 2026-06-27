---
title: Memos et partages Business CRM
audience:
  - administrator
  - superadministrator
  - publisher
status: draft
last_verified: 2026-06-27
source_of_truth: manual
source_paths:
  - backend/src/Application/Api/Admin/BusinessCrmApiController.php
  - backend/src/Application/Frontend/BusinessMemoShareController.php
  - backend/src/Modules/Business/Services/BusinessMemoSharingService.php
  - backend/src/Modules/Business/Repositories/BusinessMemoRepository.php
  - backend/routes/web.php
owners:
  - business
document_type: procedure
generated: false
---
# Memos et partages Business CRM

## Resultat attendu

Ajouter des notes CRM rattachees a une entreprise, a un contact, ou aux deux, puis les partager uniquement avec les personnes prevues.

## Droits

- Lire les memos : `business.memo.read`
- Creer, modifier, commenter ou archiver : `business.memo.manage`
- Partager en interne ou par lien public : `business.memo.share`

## Creer un memo

1. Ouvrez une entreprise ou un contact dans **Business**.
2. Creez un memo avec un titre et un contenu.
3. Verifiez qu'au moins une cible est renseignee : entreprise, contact, ou les deux.

Un memo sans cible est refuse. Les memos archives ne sont plus servis par le lien public.

## Partage interne

Le partage interne cible des utilisateurs IAM. Il donne acces au memo dans le contexte admin, sous reserve d'une session valide et des permissions du back-office.

## Partage public

Le partage public cree un lien secret de lecture seule :

- route publique : `GET /business/memos/share/{token}` ;
- token retourne une seule fois lors de la creation du partage ;
- token stocke sous forme de hash, pas en clair ;
- lien revocable via l'API admin ;
- reponse marquee `noindex, nofollow`.

Utilisez le partage public pour un extrait precis et revocable, pas pour exposer l'ensemble du CRM.

## Limites

Le module ne fournit pas de commentaires publics, d'edition collaborative ni de signature client. Les liens publics doivent rester rares et etre revoques lorsqu'ils ne sont plus utiles.
