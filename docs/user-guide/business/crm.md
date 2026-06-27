---
title: Entreprises et contacts Business CRM
audience:
  - administrator
  - superadministrator
  - publisher
status: draft
last_verified: 2026-06-27
source_of_truth: manual
source_paths:
  - backend/src/Application/Api/Admin/BusinessCrmApiController.php
  - backend/src/Modules/Business/Services/BusinessCrmService.php
  - backend/src/Modules/Business/Repositories/BusinessCompanyRepository.php
  - backend/src/Modules/Business/Repositories/BusinessContactRepository.php
  - frontend/admin-vue/src/views/modules/BusinessCrmView.vue
owners:
  - business
document_type: procedure
generated: false
---
# Entreprises et contacts Business CRM

## Resultat attendu

Centraliser des entreprises et contacts par site, avec un statut CRM simple et une liaison optionnelle vers un compte IAM.

## Droits

- Lire : `business.crm.read`
- Creer, modifier, archiver, importer ou exporter : `business.crm.manage`

## Entreprises

1. Ouvrez **Modules > Business**.
2. Dans l'onglet entreprises, creez une fiche avec un nom, puis ajoutez email, telephone, site web, adresse ou notes si necessaire.
3. Choisissez un statut : `prospect`, `client`, `supplier`, `former_client` ou `other`.
4. Archivez une fiche lorsqu'elle ne doit plus apparaitre dans les listes actives.

Un statut inconnu est refuse par l'API. Les exports CSV neutralisent les cellules qui pourraient etre interpretees comme formules par un tableur.

## Contacts

1. Creez le contact depuis une entreprise existante ou laissez l'entreprise vide.
2. Si aucune entreprise reelle n'est selectionnee, le contact est rattache a l'entreprise systeme `Individus`.
3. Renseignez nom, email, telephone, mobile, langue ou fonction selon les informations disponibles.
4. Si le contact correspond a un utilisateur IAM, renseignez son identifiant IAM. Un compte IAM actif ne peut etre lie qu'a un seul contact actif.

Chaque contact appartient a une seule entreprise. Le rattachement IAM est optionnel et peut etre reutilise apres archivage de l'ancien contact.

## Recherche et tags

La recherche porte sur les noms normalises et les coordonnees principales. Les tags servent a filtrer localement les entreprises ou contacts ; ils ne remplacent pas les statuts CRM.

## Limites

Le CRM ne gere pas les opportunites, les relances, les devis, les commandes ou les factures. Les champs prevus pour de futures extensions ne signifient pas que ces fonctions sont actives.
