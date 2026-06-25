---
title: Validation P1-01 AUTH-NAMING-001
audience:
  - developer
  - administrator
status: stable
last_verified: 2026-06-25
source_of_truth: code
owners:
  - security
  - qa
document_type: validation
generated: false
---

# Validation P1-01 AUTH-NAMING-001

- Date : 2026-06-25
- Branche / archive : mod-p1-01-auth-naming
- Runtime : PHP 8.4 fourni (`/home/amelo/Documents/DEV/Ecol_WebeLi/web/runtime/dist/webeLi-audit-runtime-linux-x86_64-php8.4/bin/php`)
- Sujet : séparation explicite des modes IAM `password`, `email_code`, `totp`

## Résultat

- `login_mode` est la source de vérité.
- `password` conserve la connexion mot de passe sans challenge.
- `email_code` utilise uniquement `iam_email_2fa_challenges` et n’est pas présenté comme TOTP.
- `totp` utilise mot de passe + code RFC 6238, secret Base32, URI `otpauth://totp/...`, 6 chiffres, SHA1, période 30 secondes.
- Le secret TOTP est stocké chiffré et n’est pas renvoyé après activation.
- Tout changement de mode révoque les sessions actives.

## Compatibilité

Les anciens endpoints `/admin/api/iam/users/{id}/totp/*` sont conservés temporairement comme compatibilité. Les endpoints explicites `/admin/api/iam/users/{id}/login-mode*` sont à utiliser pour les nouveaux clients.

## Dette restante

P1-02 / recovery codes reste hors périmètre P1-01. Les réponses gardent `recovery_codes: []` sur les endpoints de compatibilité.
