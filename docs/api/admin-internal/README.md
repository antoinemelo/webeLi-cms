---
title: API administrative interne
audience:
  - developer
  - administrator
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: contract
owners:
  - api
document_type: reference
source_paths:
  - backend/routes/api.php
  - docs/reference/contracts/admin-api-v1
generated: false
---
# API administrative interne

L’API administrative alimente le back-office. Elle n’est pas destinée aux intégrations publiques et ne bénéficie pas de la même politique de stabilité que l’API `/api/v1`.

Chaque appel exige une session valide, le contexte de site et de langue lorsque l’action est contextualisée, ainsi que la permission contrôlée côté serveur. Ne déduisez jamais une autorisation de la seule présence d’un bouton dans l’interface.

## Références

- [Contrats JSON de l’API administrative](../../reference/contracts/admin-api-v1/README.md)
- [Authentification, contexte et portées](../../public-api/authentication.md)
- [Politique de compatibilité](../README.md)

Pour une intégration externe, utilisez l’[API publique](../README.md).
