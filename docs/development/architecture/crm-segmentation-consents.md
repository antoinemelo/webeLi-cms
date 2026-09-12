---
title: Segmentation CRM, préférences et consentements
audience:
  - developer
  - administrator
  - operator
status: stable
last_verified: 2026-07-14
source_of_truth: code
source_paths:
  - backend/src/Modules/Business/Services/BusinessSegmentationService.php
  - backend/src/Modules/Business/Repositories/BusinessConsentRepository.php
  - database/modules/business.sql
owners:
  - business
document_type: architecture
generated: false
---
# Segmentation CRM, préférences et consentements

La segmentation M7.3 est un read model Business. Les segments calculés lisent exclusivement `crm_sale_activities`, jamais les commandes, lignes, paiements ou retours transactionnels de Sale. Les segments manuels conservent des membres explicitement choisis ; ils ne sont pas recalculables. Les deux n’influencent pas le classement public des produits.

## Règles et calcul

Une règle simple possède un critère, un opérateur, une valeur, une explication, une version et une durée de rétention. Les dimensions couvrent nouveau/récurrent, dernier achat, nombre et montant des commandes, canal principal, identifiants minimaux de produit/catégorie, retour/remboursement et bon cadeau. L’aperçu fournit un nombre et des exemples anonymisés. Le recalcul complet reconstruit les membres calculés ; le recalcul incrémental ne réévalue que les contacts touchés depuis le dernier identifiant d’activité projetée.

Les règles avancées restent secondaires et repliées dans l’interface. La date du dernier calcul, la version et l’explication sont visibles.

## Séparations de conformité

Les concepts suivants ne sont jamais interchangeables :

- `crm_consent_events` conserve le registre append-only du consentement marketing, avec canal, finalité, portée site, état, date, source, preuve et rétention ;
- `crm_contact_preferences` conserve canal préféré, plage souhaitée ou demande de ne pas contacter, sans créer d’opt-in ;
- la nécessité transactionnelle appartient au traitement d’une commande ;
- la création de compte est une activité IAM/CRM ;
- l’achat reste une activité commerciale.

Les sources `checkout`, `purchase`, `account` et `transactional` sont refusées lorsqu’elles tentent de créer un consentement CRM. Un retrait met à jour l’état courant mais ajoute un événement `withdrawn` sans effacer les preuves antérieures légalement nécessaires. Aucun contrôle n’est précoché dans l’admin.

## Chronologie et permissions

La chronologie d’une relation réunit les activités de commande, paiement, retour, compte et consentement. Pour un consentement, elle expose la finalité, la portée, le canal, la source, la preuve et la rétention.

- `business.segment.read` / `business.segment.manage` protègent règles, aperçus, calculs et membres ;
- `business.consent.read` / `business.consent.manage` protègent états, historique et préférences.

Les tables sont définies dans le schéma canonique `database/modules/business.sql` et sont créées lors d’une reconstruction from scratch. Aucun mécanisme de migration n’est utilisé ni attendu.
