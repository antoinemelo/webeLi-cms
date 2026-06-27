---
title: Business CRM
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
  - backend/routes/api.php
  - backend/routes/web.php
owners:
  - business
document_type: guide
generated: false
---
# Business CRM

Le module Business ajoute un CRM leger au back-office : entreprises, contacts, memos, consentements, mailing simple et outbox messaging. Il ne remplace pas un ERP et ne contient ni pipeline commercial, ni taches, ni rappels, ni comptabilite.

## Parcours

- [Entreprises et contacts](crm.md) : creer une entreprise, rattacher des contacts, utiliser l'entreprise systeme `Individus`.
- [Memos et partages](memos.md) : ecrire un memo, le rattacher a une entreprise ou un contact, partager en interne ou par lien public revocable.
- [Mailing et consentements](mailing.md) : preparer une liste, verifier les destinataires eligibles et respecter les opt-in.

## Droits

L'entree **Business / CRM** est visible avec `business.crm.read`. Les actions d'ecriture, de partage, de mailing et de messaging exigent des permissions supplementaires cote serveur.

## Limites v1

- pas de taches, rappels ou pipeline commercial ;
- pas de paie, comptabilite, commande ou facture ;
- pas d'envoi marketing sans consentement compatible ;
- WhatsApp et Telegram restent des providers configurables, desactives sans configuration officielle.
