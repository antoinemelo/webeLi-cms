---
title: Sécuriser une instance de production
audience:
  - administrator
  - superadministrator
status: stable
last_verified: 2026-06-14
source_of_truth: procedure
source_paths:
  - tools/python/operations
  - tools/python/qualification/run_all.py
  - ops/.env.example
  - .htaccess
  - backend/src/Security
  - config/app.php

owners:
  - operations
  - security
document_type: guide
generated: false
---
# Sécuriser une instance de production

## Checklist

- `APP_ENV=production`, `APP_DEBUG=0`.
- Clé de prévisualisation stable et secrète.
- HTTPS, HSTS et en-têtes activés après validation du domaine.
- Répertoires `database`, `storage`, `ops`, `tools` protégés du web.
- CORS limité aux origines nécessaires.
- Authentification Bearer et scopes configurés pour l’API publique si elle est protégée.
- Limites de requêtes activées.
- Comptes nominatifs, 2FA, sessions révocables.
- Sauvegardes testées et stockées hors du serveur.
- Journaux surveillés sans secrets.

## En-têtes HTTP à vérifier

Le serveur web ou le proxy doit envoyer au minimum les en-têtes suivants sur les pages publiques et sur le back-office :

- `Content-Security-Policy` limite les sources autorisées pour les scripts, styles, images, cadres et connexions réseau. Commencez avec une politique compatible avec le thème et le back-office, puis resserrez-la après avoir examiné les violations dans le navigateur.
- `X-Content-Type-Options: nosniff` empêche le navigateur d’interpréter une ressource avec un type différent de celui annoncé.
- `Strict-Transport-Security` impose HTTPS après la première connexion sécurisée. Activez-le uniquement lorsque le domaine et ses sous-domaines sont durablement disponibles en HTTPS.

Contrôlez les en-têtes depuis l’extérieur de l’hébergement, par exemple avec `curl -I https://example.test/`, puis vérifiez aussi une page du back-office et une réponse de l’API. Une configuration présente dans Apache ou Nginx mais absente de la réponse réelle n’est pas considérée comme active.

La CSP peut nécessiter des adaptations de thème ; ne la désactivez pas globalement sans analyser la violation.
