---
title: Gérer les taxonomies et leurs termes
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
  - backend/routes/api.php
  - database/schema/core.sql
  - database/seeds/core_seed.sql

owners:
  - editorial
document_type: procedure
generated: false
---
# Gérer les taxonomies et leurs termes

## Résultat attendu

Gérer les taxonomies et leurs termes.

## Public et droits

**Profils concernés :** editor autorisé, seo, admin.  
**Permissions :** `taxonomy.read`, `taxonomy.manage`.

## Prérequis

un type de contenu déclarant la taxonomie comme autorisée
## Procédure

1. Créez une taxonomie avec une clé stable.
2. Ajoutez les termes et, si le modèle le permet, leur hiérarchie.
3. Affectez les termes depuis les contenus compatibles.
4. Vérifiez le résultat dans l’API publique des taxonomies et dans les pages qui les utilisent.

## Limites

Une taxonomie existante n’est pas automatiquement disponible pour tous les types de contenu ; la politique du blueprint fait foi.
