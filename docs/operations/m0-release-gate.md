---
title: Gate de sortie M0
audience:
  - developer
  - administrator
  - evaluator
status: current
last_verified: 2026-07-11
source_of_truth: code
source_paths:
  - tools/python/qualification/run_all.py
  - tools/python/qualification/performance_baseline.py
  - tools/python/validation/qualification/backup_restore_roundtrip.py
owners:
  - core
  - operations
document_type: procedure
generated: false
---
# Gate de sortie M0

La procédure officielle est unique :

```bash
python3 tools/cms.py qualify --profile release
```

Elle s’exécute depuis le dépôt source complet. Une release déjà installée expose
les contrôles autonomes `smoke`, `validate`, `docs check`, `backup`,
`backup --restore` et `migrate --plan`, mais pas les tests source, le build
frontend, Composer, npm ni Playwright.

## Résultats

- `0` : gate réussie ;
- `1` : échec critique ;
- `2` : gate incomplète, par exemple prérequis absent ;
- `3` : erreur interne de l’orchestrateur.

Les preuves sont écrites dans `storage/qualification/latest.json` et
`storage/qualification/latest.md`. Le JSON contient la version, le commit,
l’environnement, les commandes, les durées, les codes de sortie, les limites de
qualification, les SHA-256 d’artefacts et la matrice source/release.

## Couverture obligatoire

La gate couvre les validateurs statiques, tests PHP/Python/TypeScript, build
back-office, rebuild isolé, plan de migration, smoke HTTP, E2E éditorial,
catalogue, panier, checkout avec paiement local, stock, backup/restore,
intégrité SQLite, documentation, OpenAPI/SDK et audits Composer/npm.

La baseline performance M0 est volontairement modérée. Le seuil critique par
scénario est configurable :

```bash
AMCMS_M0_PERF_CRITICAL_MS=2500 AMCMS_M0_PERF_REPEAT=5 \
  python3 tools/cms.py qualify --profile release
```

Le rapport de performance détaillé est
`storage/qualification/performance/latest.json`.
