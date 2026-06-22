---
title: Comprendre les statuts, révisions et recherche éditoriale
audience:
  - editor
  - publisher
  - seo
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: manual
source_paths:
  - backend/src
  - frontend/admin-vue/src

owners:
  - editorial
document_type: procedure
source_paths:
  - backend/routes/api.php
  - backend/src
  - database/schema/core.sql
  - admin-app/src
generated: false
---
# Comprendre les statuts, révisions et recherche éditoriale

## Résultat attendu

Comprendre les statuts, révisions et recherche éditoriale. La procédure décrit uniquement les fonctions visibles dans la version `dec_v05-e14n`.

## Public et droits

**Profils concernés :** editor, translator, publication, seo.  
**Permissions :** `content.read`; `content.revisions.restore` pour restaurer.

## Prérequis

avoir accès à l’entrée dans la portée active
## Statuts

Le workflow natif comprend notamment `draft`, `review`, `published`, `archived` et `scheduled` dans les blueprints qui l’activent. Le statut éditorial et la projection publique sont synchronisés par le pipeline de publication, pas par une simple modification de champ.

## Révisions

Chaque enregistrement significatif produit une révision. Vous pouvez consulter une révision, la prévisualiser et, avec la permission adéquate, la restaurer comme nouvelle base de travail. Restaurer ne publie pas automatiquement.

## Rechercher et filtrer

Utilisez les filtres de type, statut, site, langue et texte. La recherche du back-office concerne les données éditoriales accessibles ; la recherche publique `/api/v1/search` ne renvoie que des données publiées.

## Risques

La purge de révisions et la suppression d’une entrée sont distinctes. Avant toute suppression définitive, vérifiez la politique de sauvegarde.
