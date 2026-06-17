---
title: API
audience:
  - api-integrator
  - developer
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: contract
source_paths:
  - docs/public-api/openapi.v1.yaml
  - docs/reference/contracts

owners:
  - core
document_type: guide
generated: false
---
# API

Le CMS expose deux surfaces distinctes.

## API publique versionnée

La documentation canonique est sous [`docs/public-api/`](../public-api/) :

- [démarrage rapide](../public-api/quickstart.md) ;
- [authentification](../public-api/authentication.md) ;
- [erreurs](../public-api/errors.md) ;
- [exemples](../public-api/examples.md) ;
- [OpenAPI JSON](../public-api/openapi.v1.json) et [YAML](../public-api/openapi.v1.yaml).

Les endpoints sont également inventoriés automatiquement dans [les routes runtime](../reference/generated/routes.md).

## API administrative interne

L’API du back-office n’est pas un contrat public général. Ses réponses versionnées sont décrites par les contrats JSON sous [`reference/contracts/admin-api-v1/`](../reference/contracts/admin-api-v1/README.md). Les pages [`admin-internal/`](admin-internal/README.md) expliquent les conventions et les intégrations particulières.

## Compatibilité

- La compatibilité de l’API publique suit sa version OpenAPI.
- Les contrats administratifs sont versionnés individuellement mais peuvent évoluer avec le back-office.
- Les permissions doivent être contrôlées côté serveur ; l’absence d’un bouton ne constitue pas une protection.
- Les capacités réellement prises en charge et leurs limites sont indiquées dans [la matrice d’évaluation](../evaluation/feature-matrix.md).
