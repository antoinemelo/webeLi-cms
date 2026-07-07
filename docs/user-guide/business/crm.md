---
title: Entreprises et contacts Opérations CRM
audience:
  - administrator
  - superadministrator
  - publisher
status: draft
last_verified: 2026-07-06
source_of_truth: manual
source_paths:
  - backend/src/Application/Api/Admin/BusinessCrmApiController.php
  - backend/src/Modules/Business/Services/BusinessCrmService.php
  - backend/src/Modules/Business/Repositories/BusinessRelationRepository.php
  - backend/src/Modules/Business/Repositories/BusinessCompanyRepository.php
  - backend/src/Modules/Business/Repositories/BusinessContactRepository.php
  - frontend/admin-vue/src/views/modules/BusinessCrmView.vue
  - frontend/admin-vue/src/views/modules/BusinessRelationsView.vue
owners:
  - business
document_type: procedure
generated: false
---
# Entreprises et contacts Opérations CRM

## Resultat attendu

Centraliser les relations CRM par site, qu'il s'agisse de personnes ou d'organisations, avec un statut CRM simple et une liaison optionnelle vers un compte IAM.

## Droits

- Lire : `business.crm.read`
- Creer, modifier, archiver, importer ou exporter : `business.crm.manage`

## Relations unifiees

1. Ouvrez **Modules > Opérations**, puis l'onglet **Relations**.
2. Utilisez la liste **Relations** pour rechercher indifferemment une personne, une organisation, un email ou un telephone.
3. Filtrez par type ou statut : personnes, organisations, prospects, clients, fournisseurs, anciens ou autres.
4. Les indicateurs affichent uniquement les donnees utiles : memos, memos partages et contacts lies pour une organisation.
5. Depuis une relation, utilisez les actions rapides pour ouvrir la fiche, ajouter un memo, ouvrir les memos filtres, preparer un message, gerer les consentements d'un contact ou archiver.

La fiche relation s'ouvre d'abord en lecture : informations cles, coordonnees, audit, contacts lies et derniers memos/activites. La modification se fait uniquement via le bouton **Editer**, qui affiche le formulaire avec les informations existantes. La creation d'une nouvelle relation n'affiche pas les blocs d'information lies tant que la relation n'existe pas.

Les contacts sans organisation reelle restent rattaches a l'organisation systeme `Individus`. Les imports, exports, listes de memos, commentaires et messages sont accessibles depuis le menu d'actions de la vue **Relations**, sans panneau historique separe.

## Entreprises

1. Depuis **Relations**, cliquez sur **Nouvelle relation**, choisissez **Organisation**, puis ajoutez nom, email, telephone, site web ou notes si necessaire.
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

Le CRM ne gere pas les opportunites, les relances, les devis, les commandes ou les factures. Les champs prevus pour de futures extensions ne signifient pas que ces fonctions sont actives. L'usage courant passe par la vue unique **Relations** : les anciens panneaux separes entreprises/contacts ne sont plus exposes dans l'interface principale.
