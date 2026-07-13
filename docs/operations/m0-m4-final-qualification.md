---
title: Qualification finale M0 à M4
audience:
  - operator
  - developer
status: draft
last_verified: 2026-07-13
source_of_truth: code
source_paths:
  - frontend/admin-vue/tests/e2e/m0-m4-release-candidate.spec.ts
  - frontend/admin-vue/tests/e2e/omnichannel-release-gate.spec.ts
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
python3 tools/cms.py e2e --use-built-assets
python3 tools/cms.py qualify --profile release --continue-on-failure --no-cache
```

La qualification release contrôle également la gate omnicanale storefront/POS, routes/OpenAPI/SDK, CORS, FR/EN, permissions, multisite, reconstruction from scratch, sauvegarde/restauration incluant Business et Sale, événements/outbox, baseline de performance, secrets/PII, documentation, builds et structure de release. Les rapports et preuves sont écrits sous `storage/qualification/` et dans le répertoire de preuves configuré par la commande.

Une étape ignorée ne constitue pas une preuve verte. La commande prépare automatiquement une instance isolée, les bases reconstruites, le compte administrateur et le récepteur webhook ; aucune variable `E2E_*` n’est requise.
