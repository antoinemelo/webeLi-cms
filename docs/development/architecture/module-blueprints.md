---
title: Gouvernance des blueprints de modules
audience:
  - developer
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: code
source_paths:
  - backend/src
  - frontend/admin-vue/src
  - tools/python

owners:
  - core
  - documentation
document_type: guide
permissions: []
source_paths:
  - backend/src
  - frontend
  - admin-app/src
  - database
generated: false
---

# Gouvernance des blueprints de modules

Les modules déclarent leurs ressources, blueprints et schémas administratifs par leurs providers. Les contrats `admin.module_blueprints.v1.json`, `admin.module_resource_schema.v1.json` et `public.module_resource_schema.v1.json` décrivent les surfaces attendues.

Un module ne doit pas maintenir une copie parallèle de son schéma dans l’interface. Le provider, le registre de modules, les blueprints actifs et les contrats générés doivent rester alignés.
