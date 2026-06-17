---
title: Dépublier, archiver, supprimer ou restaurer
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
permissions:
  - content.publish
  - content.delete
  - content.revisions.restore
source_paths:
  - backend/routes/api.php
  - `python3 tools/cms.py test`
  - backend/src
generated: false
---
# Dépublier, archiver, supprimer ou restaurer

## Résultat attendu

Dépublier, archiver, supprimer ou restaurer. La procédure décrit uniquement les fonctions visibles dans la version `dec_v05-e14n`.

## Public et droits

**Profils concernés :** publication, admin ou super_admin.  
**Permissions :** `content.publish`, `content.delete`, `content.revisions.restore` selon l’action.

## Prérequis

avoir identifié les dépendances de route, menu, média et API
## Dépublier

Dépublier retire la projection publique de l’entrée sans effacer son historique éditorial. Vérifiez ensuite l’URL publique et les liens entrants.

## Archiver

Archiver conserve l’entrée mais la retire des parcours éditoriaux actifs. Les archives ne sont visibles dans le menu que pour les profils disposant de la capacité correspondante.

## Supprimer

La route `DELETE /admin/api/entries/{id}` existe. Traitez cette action comme destructive : sauvegardez, vérifiez les références et ne supposez pas qu’une restauration UI soit disponible.

## Restaurer une révision

La restauration crée une base éditoriale à partir d’une révision antérieure ; publiez explicitement après contrôle si vous souhaitez la rendre publique.
