---
title: Créer et organiser un menu
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
  - backend/src/Menu
  - admin-app/src
generated: false
---
# Créer et organiser un menu

## Résultat attendu

Créer et organiser un menu. La procédure décrit uniquement les fonctions visibles dans la version `dec_v05-e14n`.

## Public et droits

**Profils concernés :** editor autorisé, publication ou admin.  
**Permissions :** `menu.read`, `menu.manage`.

## Prérequis

un site, une langue et des destinations existantes
## Procédure

1. Créez ou ouvrez un menu identifié par une clé stable.
2. Ajoutez les éléments et choisissez une destination interne ou une URL autorisée.
3. Organisez l’ordre et la hiérarchie.
4. Vérifiez le site et la langue de chaque destination.
5. Enregistrez, puis contrôlez le rendu public et l’API `/api/v1/menus/{key}`.

## Risques

Une destination dépubliée peut produire un lien mort. Un changement de slug ne garantit pas la mise à jour automatique d’un lien libre saisi comme URL.
