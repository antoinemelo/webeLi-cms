---
title: Administrer imports, exports éditoriaux et export statique
audience:
  - administrator
  - superadministrator
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: procedure
source_paths:
  - backend/src
  - frontend/admin-vue/src
  - database

owners:
  - operations
  - core
document_type: guide
permissions:
  - imports_exports.read
  - imports_exports.write
  - imports_exports.manage
  - pdo_sqlite
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

L’exécution de l’export n’a pas pu être validée dans l’environnement d’audit, car PHP ne disposait pas de `pdo_sqlite`; la commande et son aide ont été vérifiées.
