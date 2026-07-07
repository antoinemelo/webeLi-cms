---
title: Business
audience:
  - administrator
  - superadministrator
  - publisher
status: draft
last_verified: 2026-06-27
source_of_truth: manual
source_paths:
  - backend/src/Modules/Business/BusinessModuleProvider.php
  - frontend/admin-vue/src/views/modules/BusinessCrmView.vue
  - frontend/admin-vue/src/views/modules/BusinessCatalogView.vue
  - backend/src/Application/Api/Admin/BusinessCatalogApiController.php
  - backend/routes/api.php
  - backend/routes/web.php
owners:
  - business
document_type: guide
generated: false
---
# Opérations

Le module Opérations regroupe le CRM leger et le catalogue commercial du back-office : relations, consentements, memos, messages, mailing simple, produits, variantes, prix, stock et offres. Il ne remplace pas un ERP et ne contient ni pipeline commercial, ni taches, ni rappels, ni comptabilite.

## Parcours

- [Entreprises et contacts](crm.md) : travailler depuis l'onglet **Relations**, creer une organisation, rattacher des personnes, utiliser l'entreprise systeme `Individus`.
- [Memos et partages](memos.md) : ecrire un memo depuis une relation ou la liste secondaire, le rattacher a une organisation ou une personne, partager en interne ou par lien public revocable.
- [Mailing et consentements](mailing.md) : preparer une liste, verifier les destinataires eligibles et respecter les opt-in.
- [Guide utilisateur Opérations CRM](../../business/crm-guide-utilisateur.md) : parcours court pour rechercher, creer, modifier, archiver, restaurer, partager un memo et envoyer un message.
- [Guide administrateur Opérations CRM](../../business/crm-guide-admin.md) : permissions, entreprise systeme `Individus`, providers, logs, exports et limites.
- [Messaging Opérations CRM](../../business/crm-messaging.md) : canaux, consentements, providers, outbox et mailing simple.
- [IA et decouverte de schema Opérations CRM](../../business/crm-ia.md) : blueprints admin, usage futur IA et absence d'exposition headless publique CRM.
- [Revue finale refonte Opérations CRM](../../business/crm-redesign-final-review.md) : controles, limites, recommandations P0/P1/P2 et checklist release.
- [Catalogue Business](../../business/catalogue.md) : creer marques, categories, produits, variantes, prix, reductions simples et stock.
- [API Catalogue Business](../../business/catalogue-api.md) : comprendre les endpoints admin, publics et POS, les permissions et les regles de prix.
- [Audit UX Opérations CRM](../../business/crm-ux-audit.md) : constats et backlog de refonte CRM Relations.
- [Principes UX Opérations CRM](../../business/crm-ux-principles.md) : vocabulaire, densite, fiche relation, actions et responsive.

## Droits

L'entree **Business** est visible avec une permission de lecture CRM ou catalogue. Les actions d'ecriture, de partage, de mailing, de messaging, de prix, de stock et d'offres exigent des permissions supplementaires cote serveur.

## Limites v1

- pas de taches, rappels ou pipeline commercial ;
- pas de PIM enterprise ni de promotions complexes ;
- pas de paie, comptabilite, commande ou facture ;
- pas d'envoi marketing sans consentement compatible ;
- WhatsApp et Telegram restent des providers configurables, desactives sans configuration officielle.
