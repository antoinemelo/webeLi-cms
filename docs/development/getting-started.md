---
title: Préparer l’environnement de développement
audience:
  - developer
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: code
owners:
  - core
document_type: guide
permissions:
source_paths:
  - backend/composer.json
  - tools/cms.py
  - admin-app/package.json
  - ops/.env.example
generated: false
---
# Préparer l’environnement de développement

Installez PHP 8.2 avec `pdo_sqlite`, Python 3, Composer et Node/npm si vous modifiez le back-office. Depuis la racine, consultez `python3 tools/cms.py --help`, initialisez ou reconstruisez une base dédiée, puis exécutez `validate --plan-only`, les validateurs ciblés et les tests.

Ne développez jamais contre les bases de production. Utilisez un environnement et des secrets locaux.
