---
title: Matrice rôles, tâches et permissions du back-office convergé
audience:
  - administrator
  - developer
status: current
last_verified: 2026-07-16
source_of_truth: code
source_paths:
  - database/seeds/iam_seed.sql
  - backend/src/Modules/Business/BusinessModuleProvider.php
  - backend/src/Modules/Sale/SaleModuleProvider.php
  - frontend/admin-vue/src/router/navigation.ts
owners:
  - core
  - business
  - sale
document_type: reference
generated: false
---

# Matrice rôles, tâches et permissions du back-office convergé

Les rôles ci-dessous sont des profils fonctionnels de référence. Les permissions restent attribuées par site dans IAM ; le nom du rôle ne remplace jamais le contrôle serveur.

| Profil | Tâches ordinaires | Permissions principales | Non visible sans permission supplémentaire |
|---|---|---|---|
| Service client | rechercher une Relation, lire la chronologie, commandes, formulaires et livraisons, ajouter un mémo | `business.crm.read`, `business.memo.read`, `business.memo.manage`, `sale.orders.read`, `sale.payments.read` | rapprochement de profils, données de consentement non autorisées |
| Opérateur Ventes | traiter une commande, demander ou constater un paiement, préparer ou livrer | `sale.read`, `sale.orders.read`, `sale.orders.manage`, permissions paiement et livraison nécessaires | ledger, réservations et diagnostics provider |
| Opérateur POS | ouvrir une session, vendre, remettre ou différer, rattacher facultativement une Relation | `sale.pos.use` et permissions POS explicites | réglages, correction de caisse et remboursement selon délégation |
| Gestionnaire Produit/stock | gérer produit, variante, prix et correction guidée du stock | `business.catalog.read`, `business.catalog.write`, `business.catalog.stock.write` | reconstruction Storefront et ledger détaillé |
| Responsable Offres | créer, prévisualiser et activer une offre, utiliser une Audience | `business.catalog.read`, `business.catalog.discounts.write`, éventuellement `business.segment.read` | consentements et providers de communication sans permissions dédiées |
| Responsable E-Commerce | lire la matrice site × langue et ouvrir Studio depuis les réglages Ventes | `sale.settings.manage` | outils avancés ou réglages métier sans leurs permissions dédiées |
| Super-admin / développeur | diagnostiquer stock, réservations, projections, identités et rattachements ambigus | `sale.advanced_tools.manage` ou `business.advanced_tools.manage` **et** chaque permission métier requise | aucune dérogation à la permission métier sous-jacente |

## Règles de cumul

- `sale.advanced_tools.manage` ne donne pas à lui seul le droit de réparer le stock, de gérer un paiement ou de fusionner des profils.
- `business.advanced_tools.manage` ne donne pas à lui seul le droit d’écrire le catalogue ou de décider un rattachement de formulaire.
- Une permission métier ordinaire ne permet pas d’appeler directement une API avancée. Les tests contrôleurs utilisent un rôle possédant la permission métier mais pas la garde avancée et attendent un refus.
- Les entrées Réservations, Exécution logistique et Identités ne font pas partie de la navigation ordinaire.
- Une Audience facilite un ciblage autorisé ; elle ne confère jamais un consentement.
