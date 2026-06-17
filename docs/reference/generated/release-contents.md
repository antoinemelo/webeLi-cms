---
title: Règles de contenu des releases
audience:
  - developer
  - installer
  - evaluator
status: stable
version: 1.0
source_of_truth: generated
generator: tools/python/generators/generate_documentation.py
owners:
  - core
document_type: reference
generated: true
---
# Règles de contenu des releases

> Fichier généré. Ne pas modifier directement.

## Bases SQLite attendues

- `core.sqlite`
- `iam.sqlite`
- `forms.sqlite`
- `cookies.sqlite`
- `ai.sqlite`

## Fichiers de garde conservés

- `storage/backups/.gitkeep`
- `storage/backups/.htaccess`
- `storage/backups/sqlite/.gitkeep`
- `storage/backups/sqlite/.htaccess`
- `storage/cache/.gitkeep`
- `storage/database/.gitkeep`
- `storage/database/.htaccess`
- `storage/deployments/.gitkeep`
- `storage/deployments/releases/.gitkeep`
- `storage/exports/.gitkeep`
- `storage/exports/.htaccess`
- `storage/exports/static/.gitkeep`
- `storage/exports/static/.htaccess`
- `storage/logs/.gitkeep`
- `storage/logs/.htaccess`
- `storage/uploads/.gitkeep`

## Exclusions du package

- `.git`
- `.git/*`
- `.DS_Store`
- `Thumbs.db`
- `vendor`
- `vendor/*`
- `backend/vendor`
- `backend/vendor/*`
- `admin-app/assets/*.js.map`
- `ops/ftp.deploy.json`
- `backend/storage`
- `backend/storage/*`
- `storage/cache/*`
- `storage/logs/*`
- `storage/logs/**`
- `storage/backups/*`
- `storage/backups/**`
- `storage/uploads/*`
- `storage/exports`
- `storage/exports/*`
- `storage/exports/**`
- `storage/deployments/current.json`
- `storage/deployments/history.ndjson`
- `storage/deployments/release-manifest.json`
- `storage/deployments/*.json`
- `storage/deployments/*.ndjson`
- `storage/deployments/releases/*`
- `storage/qualification`
- `storage/qualification/*`
- `storage/qualification/**`
- `storage/audit-results`
- `storage/audit-results/*`
- `storage/audit-results/**`
- `storage/database/*.sqlite`
- `storage/database/*.sqlite-*`
- `docs/internal`
- `docs/internal/*`
- `docs/internal/**`
- `docs/archive`
- `docs/archive/*`
- `docs/archive/**`
- `docs/history`
- `docs/history/*`
- `docs/history/**`
- `docs/adr`
- `docs/adr/*`
- `docs/adr/**`
- `tests/*`
- `**/__pycache__/*`
- `**/*.pyc`

Le contenu exact d’une archive produite doit être vérifié par la chaîne de release ; cette page décrit les règles du packager, pas le résultat d’un build particulier.
