---
title: Évaluer objectivement le CMS
audience:
  - evaluator
  - ai-evaluator
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: manual
source_paths:
  - tools/python/qualification/run_all.py
  - tools/python/validation
  - docs/evaluation/machine-readable

owners:
  - core
document_type: evaluation
generated: false
---
# Évaluer objectivement le CMS

Cet espace fournit un parcours de preuve sans recopier les guides opérationnels ou techniques.

## Parcours court

1. [Périmètre du produit](product-scope.md)
2. [Matrice des capacités](feature-matrix.md)
3. [Limites et risques](limitations.md)
4. [Index des preuves](evidence-index.md)
5. [Contrôles reproductibles](reproducible-checks.md)
6. [Politique d’audit des releases](release-audit-policy.md)
7. [Dépannage des preuves d’audit](audit-evidence-troubleshooting.md)

## Sources à consulter

- architecture : [documentation développeur](../development/README.md) ;
- modèle et schémas : [référence générée](../reference/generated/README.md) ;
- API : [OpenAPI public](../public-api/openapi.v1.json) et [contrats administratifs](../reference/contracts/admin-api-v1/README.md) ;
- rôles et permissions : [administration](../administration/users-roles-permissions/overview.md) et [permissions générées](../reference/generated/permissions.md) ;
- exploitation : [runbook](../operations/runbook.md) et [checklist de production](../operations/production-checklist.md) ;
- tests et validateurs : [couverture fonctionnelle](../development/testing-validation/FUNCTIONAL_COVERAGE_MAP.md), [validateurs générés](../reference/generated/validators.md) et données sous [`machine-readable/`](machine-readable/).

Une capacité n’est considérée comme démontrée que si elle pointe vers du code, un contrat, un test exécutable ou une procédure reproductible. Les limites non qualifiées restent visibles dans `limitations.md`.
