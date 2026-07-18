---
title: Index des preuves
audience:
  - evaluator
status: stable
last_verified: 2026-07-14
source_of_truth: procedure
source_paths:
  - tools/python/qualification/run_all.py
  - tools/python/validation
  - docs/evaluation/machine-readable

owners:
  - core
document_type: evaluation
generated: false
---
# Index des preuves

| Sujet | Preuve primaire | Preuve complémentaire |
|---|---|---|
| Architecture | [Architecture](../development/architecture/README.md) | [`machine-readable/architecture.json`](machine-readable/architecture.json) |
| Bases et projections | [Schémas générés](../reference/generated/database-schema.md) | [`machine-readable/databases.json`](machine-readable/databases.json) |
| Routes et API | [Routes générées](../reference/generated/routes.md) | [OpenAPI public](../public-api/openapi.v1.json) et contrats administratifs |
| Permissions | [Permissions générées](../reference/generated/permissions.md) | [Administration des rôles](../administration/users-roles-permissions/overview.md) |
| Fonctionnalités | [Matrice des capacités](feature-matrix.md) | [`machine-readable/features.json`](machine-readable/features.json) |
| Tests | [Couverture fonctionnelle](../development/testing-validation/FUNCTIONAL_COVERAGE_MAP.md) | [`machine-readable/tests.json`](machine-readable/tests.json) |
| Providers de paiement M5 | [Comparaison UX](payment-provider-ux-comparison.md) | [`machine-readable/sale-payment-provider-interchangeability.json`](machine-readable/sale-payment-provider-interchangeability.json) |
| Ledger de stock Sale M6 | [Architecture et UX](sale-inventory-ledger.md) | [`machine-readable/sale-inventory-ledger.json`](machine-readable/sale-inventory-ledger.json) |
| Réservations et disponibilité M6.2 | [Politique, cycle de vie et UX](sale-reservations-availability.md) | [`machine-readable/sale-reservations-availability.json`](machine-readable/sale-reservations-availability.json) |
| Bundles et stock composé M6.3 | [Stratégies, disponibilité et retours](bundle-stock-strategies.md) | [`machine-readable/bundle-stock-strategies.json`](machine-readable/bundle-stock-strategies.json) |
| Catalogue public M8.2 | [Recherche, facettes et tris](storefront-catalog-search-facets-40.md) | test unitaire `storefront_projection_test.php` et contrat `public.storefront.products.index.v1` |
| Merchandising Shop M8.3 | [Promotions, nouveautés et popularité](storefront-merchandising-popularity-41.md) | tests `storefront_merchandising_test.php`, `storefront_projection_test.php` et scénario Playwright point 41 |
| Gate release Shop point 48 | [Méthode et limites](shop-operational-release-gate-48.md) | [`machine-readable/shop-operational-release-48.json`](machine-readable/shop-operational-release-48.json) et rapport runtime `storage/qualification/shop-operational/latest.json` |
| Validations | [Registre généré](../reference/generated/validators.md) | [`machine-readable/validators.json`](machine-readable/validators.json) |
| Release | [Contenu généré](../reference/generated/release-contents.md) | [`machine-readable/release-contents.json`](machine-readable/release-contents.json) |
| Audit de release | [Politique d’audit](release-audit-policy.md) | `latest-release-audit.md` et `machine-readable/latest-release-audit.json` après une mineure/majeure |
| Exploitation | [Runbook](../operations/runbook.md) | [Contrôles reproductibles](reproducible-checks.md) |
| Limites | [Limites et risques](limitations.md) | résultats datés de qualification |

Les fichiers machine-readable sont générés par `python3 tools/cms.py docs evaluation-generate`. Ils servent à localiser les preuves ; ils ne remplacent pas leur exécution.
