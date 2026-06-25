---
title: Administrer imports, exports éditoriaux et export statique
audience:
  - administrator
  - superadministrator
status: stable
last_verified: 2026-06-24
source_of_truth: procedure
source_paths:
  - backend/src
  - frontend/admin-vue/src
  - database

owners:
  - operations
  - core
document_type: guide
source_paths:
  - backend/routes/api.php
  - tools/cms.py
  - tools/python
  - docs/reference/contracts/editorial-package-1.0
generated: false
---
# Administrer imports, exports éditoriaux et export statique

**Permissions :** `imports_exports.read`, `imports_exports.write`, `imports_exports.manage`.

Les paquets éditoriaux suivent les schémas sous `reference/contracts/editorial-package-1.0`. L’export statique utilise `python3 tools/cms.py export` ou les routes administratives.

## Sécurité

Sauvegardez avant import, validez le manifeste et interdisez les chemins sortant de la destination. Pour un export statique, testez routes, assets et liens dans le répertoire produit.

L’export multisite écrit les routes HTML sous un préfixe isolé par domaine et `base_path` primaire du site, avec fallback sur `site_key`. Le rapport `static-export-report.json` contient un bloc `output_plan` à vérifier avant publication : `collision_count` et `invalid_output_path_count` doivent valoir `0`, et `output_paths_total` doit être égal à `unique_output_paths`.

Une collision non résolue fait échouer l’export avant écriture des pages HTML. Les routes concernées sont listées dans `output_plan.collisions`, ce qui permet de corriger le domaine, le préfixe de langue, le `base_path` ou la route publiée avant de relancer l’export.
