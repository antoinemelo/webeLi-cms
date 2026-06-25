---
title: Configurer l’instance
audience:
  - installer
  - superadministrator
status: stable
last_verified: 2026-06-14
source_of_truth: configuration
source_paths:
  - backend/composer.json
  - frontend/admin-vue/package.json
  - config
  - ops/.env.example
  - config/app.php

owners:
  - operations
document_type: procedure
generated: false
---
# Configurer l’instance

La source de référence est `ops/.env.example`. Les variables couvrent environnement, base path, langues, thème, chemins Twig/Vue, prévisualisation, worker, maintenance automatique, sessions, réinitialisation de mot de passe, mail, en-têtes de sécurité, CORS, rate limiting, authentification Bearer et contrôles de santé natifs.

Ne dupliquez pas ici chaque valeur : consultez [la référence des variables](../reference/generated/configuration.md). En production, conservez les secrets hors du dépôt et appliquez des permissions de fichier restrictives.
