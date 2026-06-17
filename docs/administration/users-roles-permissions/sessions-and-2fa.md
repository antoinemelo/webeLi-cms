---
title: Gérer les sessions et la connexion par code email
audience:
  - administrator
  - superadministrator
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: code
source_paths:
  - backend/src
  - frontend/admin-vue/src
  - database

owners:
  - operations
  - security
document_type: guide
permissions:
  - sessions.read
  - sessions.manage
  - users.email_2fa.manage
source_paths:
  - backend/src/Application/Iam/IamAdminRepository.php
  - backend/src/Application/Api/Admin/IamAdminApiController.php
  - frontend/admin-vue/src/admin/securityPanel.ts
  - database/migrations/iam
  - `python3 tools/cms.py test`
generated: false
---
# Gérer les sessions et la connexion par code email

Cette procédure s’adresse aux administrateurs autorisés à gérer les comptes et leurs sessions. Elle explique comment activer la **2FA par email**, contrôler les connexions ouvertes et réagir lorsqu’un utilisateur ne peut plus accéder au back-office.

## Permissions nécessaires

- `sessions.read` pour consulter les sessions ;
- `sessions.manage` pour les révoquer ;
- `users.email_2fa.manage` pour activer ou désactiver la connexion par code email sur un compte.

Vérifiez également que vous intervenez sur le bon utilisateur et dans le bon contexte de site avant toute modification.

## Fonctionnement de la connexion par code email

Lorsque cette option est active, l’utilisateur saisit son adresse de connexion. Le CMS crée alors un défi temporaire dans `iam_email_2fa_challenges` et envoie un **code numérique envoyé** à l’adresse enregistrée sur le compte.

Pendant ce parcours, le **mot de passe n’est pas demandé**. Le code est limité dans le temps, ne peut être utilisé qu’une fois et les tentatives sont comptabilisées. Cette méthode dépend donc du bon fonctionnement de l’envoi d’emails et de l’accès de l’utilisateur à sa boîte de réception.

## Activer la 2FA par email

1. Ouvrez **Utilisateurs**, puis la fiche du compte concerné.
2. Accédez à **Configuration avancée**.
3. Vérifiez que l’adresse email est correcte et que le compte est actif.
4. Dans **Connexion par code email**, choisissez **Activer la connexion par code email**.
5. Confirmez l’opération.
6. Demandez à l’utilisateur de fermer sa session, puis de tester une nouvelle connexion.
7. Contrôlez qu’il reçoit le code et qu’il peut terminer la connexion.

L’activation ne fournit aucun secret à copier et ne nécessite pas d’application d’authentification. Le facteur repose sur l’adresse email associée au compte.

## Désactiver la connexion par code email

1. Ouvrez la fiche de l’utilisateur.
2. Accédez à **Configuration avancée**.
3. Dans **Connexion par code email**, choisissez **Désactiver la connexion par code email**.
4. Confirmez l’opération.
5. Demandez à l’utilisateur de tester le mode de connexion qui reste autorisé pour son compte.

Ne désactivez pas ce mode sans vérifier que l’utilisateur dispose encore d’un moyen valide de se connecter.

## Consulter et révoquer les sessions

1. Ouvrez la fiche de l’utilisateur ou l’écran de gestion des sessions.
2. Repérez les sessions actives à partir de leur date, de leur adresse IP et de leur agent utilisateur lorsque ces informations sont disponibles.
3. Révoquez une session inconnue ou devenue inutile.
4. En cas de suspicion de compromission, révoquez toutes les sessions du compte, vérifiez son adresse email et contrôlez le journal d’audit.

La révocation coupe l’accès associé à la session, mais ne corrige pas à elle seule la cause d’un incident. Vérifiez aussi les rôles, les permissions et les changements récents du compte.

## L’utilisateur ne reçoit pas le code

Contrôlez dans cet ordre :

1. l’adresse email enregistrée sur le compte ;
2. l’état actif du compte ;
3. la configuration d’envoi des emails de l’instance ;
4. les courriers indésirables et les règles de filtrage du destinataire ;
5. les journaux applicatifs et les événements d’authentification ;
6. l’expiration ou la consommation du défi dans `iam_email_2fa_challenges`.

Ne communiquez jamais un code reçu à la place de l’utilisateur. Après plusieurs échecs, laissez expirer le défi en cours et relancez une connexion propre.

## Contrôles après intervention

- la connexion aboutit avec le compte concerné ;
- un ancien code est refusé après utilisation ou expiration ;
- les sessions révoquées ne donnent plus accès au back-office ;
- l’opération apparaît dans les journaux disponibles ;
- les rôles et permissions du compte n’ont pas été modifiés involontairement.
