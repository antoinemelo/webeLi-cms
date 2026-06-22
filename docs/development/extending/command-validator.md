---
title: Ajouter une commande ou un validateur
audience:
  - developer
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: manual
owners:
  - documentation
document_type: guide
source_paths:
  - backend/src
  - admin-app/src
  - database
  - tools
generated: false
---

# Ajouter une commande ou un validateur

Une commande stable s’intègre à `tools/cms.py`; un validateur s’enregistre dans le registry. Fournissez aide, code de retour, mode JSON si conventionnel, test et documentation générée. N’utilisez pas un validateur pour masquer une dette par exception permanente.
