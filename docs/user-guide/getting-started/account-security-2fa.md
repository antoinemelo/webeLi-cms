---
title: Sécuriser son compte et utiliser la double authentification
audience:
  - editor
  - publisher
  - seo
status: stable
last_verified: 2026-06-14
source_of_truth: manual
source_paths:
  - backend/src
  - frontend/admin-vue/src

owners:
  - editorial
document_type: procedure
source_paths:
  - backend/routes/api.php
  - backend/src
  - ops/.env.example
  - database/migrations/iam
generated: false
---
# Sécuriser son compte et utiliser la double authentification

## Résultat attendu

Sécuriser son compte et utiliser la double authentification.

## Public et droits

**Profils concernés :** tout utilisateur; gestion d’autrui réservée aux administrateurs.  
**Permissions :** `profile.update`; `users.email_2fa.manage` ou permissions IAM pour agir sur autrui.

## Prérequis

être connecté et connaître son mot de passe actuel
## Procédure

1. Ouvrez le profil et changez le mot de passe avec un mot de passe unique d’au moins la longueur configurée.
2. Activez TOTP depuis l’écran de sécurité lorsqu’il est proposé, scannez le secret avec une application compatible et confirmez un code.
3. Enregistrez les codes de récupération hors du navigateur.
4. Pour la 2FA par e-mail, vérifiez l’adresse et la capacité de livraison du transport configuré.
5. En cas de perte du second facteur, utilisez un code de récupération ou demandez à un superadministrateur de désactiver/réinitialiser le facteur.

## Risques et récupération

Ne copiez jamais le secret TOTP dans un ticket. La désactivation administrative doit être journalisée. Une réinitialisation de mot de passe peut révoquer les sessions selon `APP_PASSWORD_RESET_REVOKE_SESSIONS`.

## Limites

L’envoi e-mail dépend du transport serveur. L’absence de livraison n’est pas compensée par un fournisseur externe intégré par défaut.
