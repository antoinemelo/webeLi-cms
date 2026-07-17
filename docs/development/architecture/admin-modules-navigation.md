---
title: Architecture visible du back-office
audience:
  - administrator
  - developer
status: current
last_verified: 2026-07-16
source_of_truth: code
source_paths:
  - frontend/admin-vue/src/router/index.ts
  - frontend/admin-vue/src/router/navigation.ts
  - backend/src/Modules/Business/BusinessModuleProvider.php
  - backend/src/Modules/Sale/SaleModuleProvider.php
owners:
  - core
  - business
  - sale
document_type: architecture
generated: false
---
# Architecture visible du back-office

## Vocabulaire produit

- **webeLi** est le nom de la plateforme.
- **DEC CMS Core** est le noyau éditorial et technique : contenus, révisions, publication, routes, médias, multisite, langues, IAM et cycle de vie des modules.
- **Cockpit** est l’espace de pilotage transversal : éléments à traiter, qualité, configuration et maintenance selon les permissions.
- **Studio** regroupe les tâches éditoriales : pages, articles, formulaires, SEO et médias.
- **Opérations** regroupe les relations clients et fournisseurs, les produits et le stock PIM, les offres et le marketing. Les relations restent la propriété du CRM interne et les produits celle de Business/PIM.
- **Ventes** regroupe les commandes, le suivi des paiements et factures, les points de vente et la configuration des sites e-commerce. Sale reste propriétaire des données transactionnelles.

## Frontières

E-Commerce n’est pas un module autonome : sa configuration est rattachée à `Ventes > Réglages` et protégée par `sale.settings.manage`. L’ouverture de cette section n’ajoute aucune route `/shop`, aucun item de menu public, aucun panier et aucune publication.

Les réservations, la logistique interne, le rapprochement des identités, le ledger et les diagnostics de reconstruction ou de provider sont des outils avancés. Leur accès cumule `sale.advanced_tools.manage` et la permission métier nécessaire. Les reconstructions Opérations cumulent `business.advanced_tools.manage` et la permission métier nécessaire.

## Navigation

Les espaces métier utilisent des URL de tâche partageables et une navigation secondaire compacte. Les anciennes URL utiles redirigent vers leur destination canonique. Sur petit écran, la même liste permissionnée est présentée dans un sélecteur natif ; elle ne constitue pas une interface fonctionnellement différente.

La carte complète des routes et redirections est maintenue dans `docs/reference/admin-convergence-routes.md`. La composition des rôles ordinaires et avancés est décrite dans `docs/administration/admin-convergence-roles.md`.
