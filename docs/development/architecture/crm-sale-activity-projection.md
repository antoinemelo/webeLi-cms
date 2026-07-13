---
title: Projection des activités Vente dans le CRM
audience:
  - developer
  - administrator
status: stable
last_verified: 2026-07-13
source_of_truth: code
source_paths:
  - database/modules/business.sql
  - database/modules/sale.sql
  - backend/src/Modules/Business/Services/SaleCrmActivityProjectionService.php
  - backend/src/Modules/Business/Contracts/CrmSaleActivityV1.php
  - backend/src/Modules/Sale/Pricing/CustomerPricingContextProvider.php
owners:
  - business
  - sale
document_type: architecture
generated: false
---

# Projection des activités Vente dans le CRM

Le CRM expose un read model commercial reconstruit depuis `sale_events` et
`sale_outbox`. Vente reste la source de vérité des commandes, paiements,
livraisons, retours et remboursements. Le CRM n'écrit jamais dans les tables
`sale_orders` et ne modifie jamais `customer_snapshot_json`.

## Contrat v1

Chaque ligne `crm_sale_activities` contient la version du DTO, le type et la
date de l'activité, le site, le canal (`web`, `pos`, `admin`), les références
CRM facultatives, l'identifiant de l'événement source, la référence métier, un
résumé non sensible et le statut. `source_event_id` est unique : un rejeu de
l'outbox ne crée donc pas de doublon.

Événements projetés : commande passée/confirmée/annulée, paiement capturé ou
échoué, livraison terminée, retour créé, remboursement terminé, carte cadeau
émise/utilisée et vente POS terminée. `cart.abandoned` n'est volontairement pas
projeté : une simple inactivité du panier ne justifie ni la création d'une
relation CRM ni une action marketing.

## Résolution d'identité

L'ordre de résolution est strict :

1. identifiants `customer_contact_id` et `customer_company_id` de la commande ;
2. lien IAM–CRM actif et explicite dans `sale_customer_account_links` ;
3. rattachement manuel ultérieur via l'API CRM, avec motif et audit immuable ;
4. à défaut, activité anonyme.

Aucun rapprochement n'est fait à partir d'un courriel et aucun contact factice
n'est créé. Le rattachement tardif ne touche que la projection CRM.

## API et exploitation

- `GET /admin/api/business/sale-activities/unlinked` liste les activités anonymes.
- `POST /admin/api/business/sale-activities/{id}/link` rattache une activité avec audit.
- `POST /admin/api/business/sale-activities/reconcile` détecte et, par défaut, répare les projections manquantes ; il rapporte aussi les doublons.
- `GET /admin/api/business/relations/{type}/{id}/activity` consomme les événements en attente du site et affiche les activités Vente dans la timeline existante.

Le contexte client transmis à Pricing passe par
`CustomerPricingContextProvider`. Il est en lecture seule et contient les
segments ainsi qu'un booléen `marketing_allowed`. Une action marketing doit
exiger ce booléen ; l'activité opérationnelle reste, elle, indépendante du
consentement marketing.

Les tables sont définies uniquement dans les schémas canoniques reconstruits
from scratch. Aucune migration n'est nécessaire ni planifiée.
