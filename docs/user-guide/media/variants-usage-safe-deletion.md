---
title: Générer des variantes et supprimer un média sans casser un contenu
audience:
  - editor
  - publisher
  - seo
status: stable
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
  - backend/src/Media
generated: false
---
# Générer des variantes et supprimer un média sans casser un contenu

## Résultat attendu

Générer des variantes et supprimer un média sans casser un contenu.

## Public et droits

**Profils concernés :** editor, publication, admin.  
**Permissions :** `media.update`, `media.delete`.

## Prérequis

avoir identifié tous les usages du média
## Variantes

Utilisez les presets proposés par `/admin/api/media/variant-presets` puis lancez la génération. Contrôlez que le driver de stockage et les outils d’image requis sont disponibles.

## Médias inutilisés

La vue `/admin/api/media/unused` aide à repérer les médias sans référence connue. Elle ne remplace pas une vérification des templates, contenus externes ou liens copiés manuellement.

## Suppression sûre

1. Consultez la fiche et les usages.
2. Remplacez le média dans les contenus concernés.
3. Vérifiez les versions publiées.
4. Utilisez l’action de suppression sûre.
5. Contrôlez les pages publiques.

La suppression peut être irréversible sans sauvegarde du stockage et des bases.
