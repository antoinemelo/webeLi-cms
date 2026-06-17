---
title: Administrer les modules
audience:
  - administrator
  - superadministrator
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: code
source_paths:
  - backend/src
  - frontend/admin-vue/src
  - database

owners:
  - operations
  - core
document_type: guide
permissions:
source_paths:
  - backend/src/Modules
  - database/modules
  - `python3 tools/cms.py validate --category content`
generated: false
---
# Administrer les modules

Les modules déclarent un provider, des routes, ressources, permissions, blueprints et éventuelles tables. Les modules système ne suivent pas nécessairement le même cycle de suppression qu’un module optionnel.

## Procédure

1. Vérifiez la compatibilité et les dépendances.
2. Installez les schémas et seeds par les outils prévus.
3. Activez le module.
4. Vérifiez ses capacités et menus selon les permissions.
5. Avant désactivation, identifiez routes, données et contenus dépendants.

Les modules Forms et AI Assistant sont présents dans cette version. Leur simple présence ne signifie pas qu’un fournisseur externe ou un transport est configuré.
