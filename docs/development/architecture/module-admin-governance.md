---
title: Gouvernance administrative des modules
audience:
  - developer
status: stable
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
source_paths:
  - backend/src
  - frontend
  - admin-app/src
  - database
generated: false
---

# Gouvernance administrative des modules

La présence d’un module dans le back-office dépend de son enregistrement, de ses capacités, de ses permissions et de ses routes administratives. La visibilité d’une carte ou d’un menu ne remplace jamais l’autorisation backend.

Chaque module doit exposer une configuration cohérente, déclarer ses permissions, limiter sa portée au site lorsque nécessaire et fournir des contrats pour les endpoints administratifs utilisés par l’interface.
