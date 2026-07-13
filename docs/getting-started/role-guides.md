---
title: Guides par rôle
audience:
  - editor
  - publisher
  - seo
  - catalog-manager
  - sales-operator
  - administrator
  - superadministrator
  - installer
  - developer
  - evaluator
status: stable
last_verified: 2026-07-11
source_of_truth: manual
source_paths:
  - docs
  - frontend/admin-vue/src/router/navigation.ts
owners:
  - documentation
document_type: guide
generated: false
---
# Guides par rôle

Cette page donne un point d’entrée unique par profil. Elle complète [Choisir son parcours documentaire](choose-your-path.md) avec les rôles métier introduits par les modules catalogue et vente.

| Rôle | Objectif | Lire d’abord | Vérifier avec |
|---|---|---|---|
| Éditeur | Créer et prévisualiser des contenus. | [Créer, modifier et prévisualiser](../user-guide/content/create-edit.md) | Scénario `content-page-publish` dans [Scénarios vérifiables](verified-scenarios.md) |
| Publicateur | Relire, publier, restaurer ou archiver. | [Réviser et publier](../user-guide/publication/review-publish.md) | `python3 tools/cms.py e2e --use-built-assets` |
| Responsable SEO | Contrôler indexabilité, métadonnées et routes. | [Workflow SEO](../user-guide/seo/seo-workflow.md) | [Audit SEO](../user-guide/seo/audit-redirects-troubleshooting.md) |
| Gestionnaire catalogue/PIM | Créer produits, variantes, médias et qualité vendable. | [Catalogue](../business/catalogue.md) | Scénarios `catalog-product-create` et `cms-product-link` |
| Opérateur de vente/POS | Saisir une vente interne et contrôler paiements/retours. | [Vente POS](../business/vente-pos.md) | Scénarios `internal-sale` et `sale-return` |
| Administrateur | Configurer sites, langues, modules et réglages. | [Administration](../administration/README.md) | [Référence permissions](../reference/generated/permissions.md) |
| Superadministrateur | Gouverner IAM, sécurité, maintenance et sauvegarde. | [Sessions et 2FA](../administration/users-roles-permissions/sessions-and-2fa.md) | [Sauvegarde et restauration](../operations/backup-restore.md) |
| Installateur | Installer, mettre à jour ou diagnostiquer une instance. | [Installer une release](../installation/install-release.md) | `python3 tools/cms.py validate --full` |
| Développeur | Étendre le CMS, modules, API et contrats. | [Développement](../development/README.md) | `python3 tools/cms.py docs generate && python3 tools/cms.py docs check` |
| Évaluateur indépendant | Vérifier les preuves et limites sans dépendre d’une promesse commerciale. | [Évaluation](../evaluation/README.md) | [Contrôles reproductibles](../evaluation/reproducible-checks.md) |

## Règle de lecture

Un rôle documentaire n’est pas une permission IAM. Les permissions exécutables restent générées dans [Permissions détectées](../reference/generated/permissions.md) et appliquées côté backend.
