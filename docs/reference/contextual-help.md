---
title: Registre d’aide contextuelle
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
  - frontend/admin-vue/src/contextualHelp.ts
  - frontend/admin-vue/src/components/ui/ContextualHelpLink.vue
  - frontend/admin-vue/src/views
owners:
  - documentation
document_type: reference
generated: false
---
# Registre d’aide contextuelle

Chaque entrée possède un identifiant stable utilisé par le back-office. Une icône `bi-bookmarks` est placée dans le titre, à côté de l’indicateur d’information issu du blueprint, et ouvre directement la documentation cible. Aucun panneau d’aide redondant n’est affiché dans la page.

| Identifiant stable | Écran ou fonctionnalité | Documentation cible | Résumé court | Erreurs fréquentes |
|---|---|---|---|---|
| `docs.index` | Actifs > Docs | [Documentation du CMS](../README.md) | Trouver la bonne source documentaire et filtrer par profil. | Chercher une règle de permission dans la documentation ; modifier une page générée manuellement. |
| `settings.configuration` | Cockpit > Configuration | [Administration](../administration/README.md) | Régler sites, langues, apparence, SEO, médias et paramètres système. | Modifier le mauvais site actif ; confondre langue de contenu et langue d’interface. |
| `system.blueprints` | Cockpit > Structures de contenu | [Blueprints](../administration/content-model/blueprints.md) | Maintenir les structures éditoriales, champs et groupes réutilisables. | Supprimer un champ système ; activer un brouillon sans relire les versions. |
| `tools.maintenance` | Cockpit > Maintenance | [Maintenance](../administration/maintenance.md) | Lire versions, bases, dépendances, cache, recherche et journaux. | Confondre inventaire informatif et mise à jour ; vider le cache pour corriger une migration manquante. |
| `modules.sale` | Modules > Vente | [Vente](../business/vente.md) | Contrôler commandes, POS, paiements et stock transactionnel. | Créer une vente sans canal actif ; interpréter un paiement en attente comme payé. |
| `modules.commerce` | Ventes > Réglages > E-Commerce | [E-Commerce](../business/commerce.md) | Comprendre la configuration des sites e-commerce rattachée aux réglages Ventes. | Supposer qu’ouvrir les réglages publie un Shop ; confondre canal existant et Shop activé. |
| `business.crm` | Modules > Business CRM | [CRM](../business/crm-guide-utilisateur.md) | Gérer contacts, sociétés, mémos, consentements et messages. | Travailler sur une relation archivée ; oublier les consentements avant un message. |

## Règles de maintenance

- Ajoutez une entrée ici avant d’ajouter un lien d’aide contextuelle dans l’interface.
- L’identifiant ne doit pas changer après publication ; ajoutez une nouvelle entrée si le sens fonctionnel change.
- Le lien doit pointer vers une page indexée par le viewer Docs.
- Les erreurs fréquentes doivent rester courtes et orienter vers la documentation cible.
