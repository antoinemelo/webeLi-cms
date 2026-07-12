---
title: Qualification finale M0 à M4
audience:
  - operator
  - developer
status: draft
last_verified: 2026-07-12
source_of_truth: code
source_paths:
  - frontend/admin-vue/tests/e2e/m0-m4-release-candidate.spec.ts
  - tools/python/qualification/run_all.py
  - tools/cms.py
owners:
  - core
  - sale
  - business
document_type: runbook
generated: false
---
# Qualification finale M0 à M4

Le scénario Playwright `m0-m4-release-candidate.spec.ts` vérifie le catalogue publié, son affichage dans le checkout CMS, le panier, l’identité, les adresses, le fulfillment calculé, la TVA, les CGV, l’idempotence, la commande admin, le paiement local, le reçu, le retour et remboursement partiels, le stock, la chronologie, les événements et la création facultative du compte.

Exécution de référence :

```bash
python3 tools/cms.py migrate --plan
python3 tools/cms.py e2e
python3 tools/cms.py qualify --profile release --continue-on-failure --no-cache
```

La qualification release contrôle également routes/OpenAPI/SDK, CORS, FR/EN, permissions, multisite, migrations et reconstruction, sauvegarde/restauration incluant Business et Sale, événements/outbox, baseline de performance, secrets/PII, documentation, builds et structure de release. Les rapports et preuves sont écrits sous `storage/qualification/` et dans le répertoire de preuves configuré par la commande.

Une étape ignorée faute d’identifiants ou d’environnement E2E ne constitue pas une preuve verte : le candidat release doit être exécuté avec `E2E_BASE_URL`, `E2E_ADMIN_EMAIL` et `E2E_ADMIN_PASSWORD` sur l’instance isolée préparée par l’outil.
