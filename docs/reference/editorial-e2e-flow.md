---
title: Smoke E2E éditorial
audience:
  - developer
  - evaluator
status: stable
last_verified: 2026-06-25
source_of_truth: tests
source_paths:
  - frontend/admin-vue/tests/e2e/editorial-publish-flow.spec.ts
  - backend/routes/api.php
owners:
  - core
document_type: reference
generated: false
---
# Smoke E2E éditorial

P1-04 couvre un parcours éditorial minimal pour une page et un article :
création, brouillon, prévisualisation, publication, rendu front SSR et lecture
headless du contenu publié.

| Type | Route publique attendue | Contrôle headless |
| --- | --- | --- |
| Page | `/{slug}` | `GET /api/v1/content-by-path?path=/{slug}&lang=fr` |
| Article | `/articles/{slug}` | `GET /api/v1/content-by-path?path=/articles/{slug}&lang=fr` |

Le test utilise le compte E2E injecté par `python3 tools/cms.py e2e` via
`E2E_ADMIN_EMAIL` et `E2E_ADMIN_PASSWORD`. Les titres et slugs sont uniques à
chaque exécution.

Commande isolée recommandée après build des assets :

```bash
python3 tools/cms.py e2e --use-built-assets
```

Commande ciblée possible contre une instance E2E déjà configurée :

```bash
cd frontend/admin-vue && npm run test:e2e -- tests/e2e/editorial-publish-flow.spec.ts
```
