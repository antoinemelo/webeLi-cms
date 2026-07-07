---
title: Gouvernance des blueprints de modules
audience:
  - developer
status: stable
last_verified: 2026-07-07
source_of_truth: code
source_paths:
  - backend/src
  - frontend/admin-vue/src
  - tools/python
  - frontend
  - admin-app/src
  - database

owners:
  - core
  - documentation
document_type: guide
generated: false
---

# Gouvernance des blueprints de modules

Les modules déclarent leurs ressources, blueprints et schémas administratifs par leurs providers. Les contrats `admin.module_blueprints.v1.json`, `admin.module_resource_schema.v1.json` et `public.module_resource_schema.v1.json` décrivent les surfaces attendues.

Un module ne doit pas maintenir une copie parallèle de son schéma dans l’interface. Le provider, le registre de modules, les blueprints actifs et les contrats générés doivent rester alignés.

## Cas Business CRM

Le module `business` déclare des blueprints admin CRM pour documenter ses ressources (`business.relation`, `business.company`, `business.contact`, `business.memo`, `business.memo_comment`, `business.message`, `business.consent`, `business.mailing_list`, `business.mailing_list_member`).

Ces blueprints sont des schémas de gouvernance et de découverte : ils exposent champs, types, validations, permissions, relations et politique d'export. Ils préparent l'import/export, les contrats admin, la maintenance et une future couche IA.

Ils ne créent pas de routes headless publiques CRM. La valeur canonique reste `headless.enabled=false` et `public=false` pour ces ressources, car elles contiennent des données personnelles ou opérationnelles. L'UX CRM reste spécifique dans Vue et ne devient pas un CRUD générique généré par blueprint.
