---
title: Application API publique
audience:
  - developer
  - api-integrator
status: stable
last_verified: 2026-06-14
source_of_truth: code
source_paths:
  - backend/src
  - frontend/admin-vue/src
  - tools/python
  - frontend
  - admin-app/src
  - database

owners:
  - api
  - documentation
document_type: guide
generated: false
---

# Application API publique

L’API publique v1 utilise son kernel, son responder et son contexte de requête. Elle expose uniquement les données publiées autorisées pour le site et la langue résolus. Les erreurs, en-têtes, pagination et limites de débit sont normalisés par la couche API publique.

Les contrats OpenAPI sous `docs/public-api/` et les contrats JSON sous `docs/reference/contracts/headless-v1/` sont les références générées ou vérifiées. Les routes administratives ne font pas partie de cette surface publique.
