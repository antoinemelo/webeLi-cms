---
title: API administrative de l’assistant IA
audience:
  - developer
  - api-integrator
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: code
owners:
  - api
document_type: guide
permissions: []
source_paths:
  - backend/src
  - admin-app/src
  - database
generated: false
---

# API administrative de l’assistant IA

Cette API est interne au back-office. Elle nécessite une session administrative valide, le contexte attendu et les permissions IA correspondant à l’opération.

## Fournisseurs et catalogue

- `POST /admin/api/ai/provider-types`
- `POST /admin/api/ai/providers`
- `DELETE /admin/api/ai/providers/{key}`
- `POST /admin/api/ai/models`
- `DELETE /admin/api/ai/models/{providerKey}/{modelKey}`

## Tests

- `POST /admin/api/ai/providers/test`
- `GET /admin/api/ai/providers/test/stream`
- `POST /admin/api/ai/editorial/test-generation`

Le test en streaming utilise un flux SSE. Le client doit traiter les erreurs structurées et la fermeture anticipée de la connexion.

## Suggestions, usage et tâches

Les autres groupes couvrent les prompts, les suggestions, l’usage, les budgets et les tâches asynchrones. La liste exhaustive des routes est générée depuis le provider du module et les références de routes.

## Sécurité

Les réponses ne doivent jamais exposer une clé fournisseur en clair. Les secrets sont référencés par `api_key_ref` et protégés par une clé maître conservée hors base.
