---
title: Gérer le cycle de vie des blueprints
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
  - blueprints.read
  - blueprints.manage
  - admin.blueprints.write
  - admin.blueprints.versions.write
  - admin.blueprints.delete
source_paths:
  - backend/routes/api.php
  - backend/src/Blueprints
  - database/schema/core.sql
  - `python3 tools/cms.py validate --category content`
generated: false
---
# Gérer le cycle de vie des blueprints

**Permissions :** `blueprints.read`, `blueprints.manage`, `admin.blueprints.write`, `admin.blueprints.versions.write`, `admin.blueprints.delete` selon l’action.

Un blueprint versionné définit le type de contenu, ses capacités, champs, interface, validation, SEO, routage, workflow, traduction et permissions. Une version active pilote l’éditeur et le contrat public.

## Procédure

1. Créez une version de travail, sans modifier silencieusement la version active.
2. Validez les clés, types de champs et politiques.
3. Contrôlez la compatibilité avec les entrées existantes.
4. Activez explicitement la version.
5. Vérifiez l’éditeur, la prévisualisation, la publication et l’API.

## Risque

Une activation incompatible peut rendre des données non éditables ou non publiables. Sauvegardez avant activation et prévoyez une version de retour.
