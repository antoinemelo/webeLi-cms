---
title: Gérer les sessions et les modes de connexion IAM
audience:
  - administrator
  - superadministrator
status: stable
last_verified: 2026-06-25
source_of_truth: code
source_paths:
  - backend/src
  - frontend/admin-vue/src
  - database
  - backend/src/Application/Iam/IamAdminRepository.php
  - backend/src/Application/Api/Admin/IamAdminApiController.php
  - frontend/admin-vue/src/views/iam/IamUsersView.vue
  - database/migrations/iam
  - `python3 tools/cms.py test`

owners:
  - operations
  - security
document_type: guide
generated: false
---
# Gérer les sessions et les modes de connexion IAM

Cette procédure s’adresse aux administrateurs autorisés à gérer les comptes et leurs sessions. Elle explique les trois modes `login_mode` disponibles, le contrôle des connexions ouvertes et la réaction lorsqu’un utilisateur ne peut plus accéder au back-office.

## Permissions nécessaires

- `sessions.read` pour consulter les sessions ;
- `sessions.manage` pour les révoquer ;
- `users.email_2fa.manage` pour modifier le mode de connexion d’un compte. Le nom de permission est conservé par compatibilité, mais le modèle métier est `login_mode`.

Vérifiez également que vous intervenez sur le bon utilisateur et dans le bon contexte de site avant toute modification.

## Modes de connexion

- `password` : connexion classique par mot de passe, sans challenge supplémentaire.
- `email_code` : code temporaire envoyé par e-mail, sans mot de passe pendant ce parcours. Ce mode n’est pas un TOTP.
- `totp` : mot de passe + code TOTP standard RFC 6238, compatible application d’authentification via URI `otpauth://totp/...`.

`login_mode` est la source de vérité. Les anciens champs `totp_enabled` et `totp_required` peuvent encore apparaître dans les réponses pour compatibilité, mais ils ne doivent plus être utilisés pour décider si un compte passe par le code e-mail ou par un vrai TOTP.

## Fonctionnement de la connexion par code e-mail

Lorsque `login_mode=email_code`, l’utilisateur saisit son adresse de connexion. Le CMS crée alors un défi temporaire dans `iam_email_2fa_challenges` et envoie un code numérique à l’adresse enregistrée sur le compte.

Pendant ce parcours, le mot de passe n’est pas demandé. Le code est limité dans le temps, ne peut être utilisé qu’une fois et les tentatives sont comptabilisées. Cette méthode dépend du bon fonctionnement de l’envoi d’e-mails et de l’accès de l’utilisateur à sa boîte de réception.

## Fonctionnement du TOTP

Lorsque `login_mode=totp`, l’utilisateur saisit son mot de passe puis un code à 6 chiffres généré par une application d’authentification. Le secret est généré en Base32, activé uniquement après confirmation d’un premier code valide, puis stocké chiffré au repos. Après activation, le secret n’est plus renvoyé par l’API.

L’activation TOTP génère aussi des codes de récupération à usage unique. Ils sont affichés une seule fois, au moment de l’activation ou de la régénération. Le CMS stocke uniquement leurs hashes dans `iam_users.totp_recovery_codes_json`; un code utilisé est supprimé immédiatement.

## Changer le mode de connexion

1. Ouvrez **Utilisateurs**, puis la fiche du compte concerné.
2. Ouvrez **Configuration avancée**, puis accédez à **Mode de connexion**.
3. Vérifiez que l’adresse email est correcte et que le compte est actif.
4. Choisissez **Mot de passe classique**, **Code par e-mail** ou **Mot de passe + application TOTP**.
5. Pour `totp`, préparez le secret, scannez l’URI `otpauth` ou saisissez le secret manuel dans l’application, puis confirmez un code courant.
6. Copiez les codes de récupération affichés et transmettez-les par un canal approprié si le compte n’est pas le vôtre.
7. Confirmez l’opération et demandez à l’utilisateur de tester une nouvelle connexion.

Chaque changement de mode révoque les sessions actives de l’utilisateur.

## Désactiver ou remplacer le TOTP

1. Ouvrez la fiche de l’utilisateur.
2. Ouvrez **Configuration avancée**, puis accédez à **Mode de connexion**.
3. Choisissez **Mot de passe classique** ou **Code par e-mail**, ou préparez une rotation TOTP.
4. Confirmez l’opération. Les sessions actives sont révoquées.

Ne désactivez pas un mode sans vérifier que l’utilisateur dispose encore d’un moyen valide de se connecter.

## Régénérer les codes de récupération TOTP

1. Ouvrez la fiche d’un utilisateur en mode `totp`.
2. Choisissez **Régénérer les codes de récupération**.
3. Copiez immédiatement la nouvelle liste. Les anciens codes deviennent invalides.
4. Contrôlez le journal d’audit si la régénération répond à un incident.

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
