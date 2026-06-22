---
title: Créer, modifier et prévisualiser un contenu
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
  - backend/src/Content
  - database/schema/core.sql
  - admin-app/src
generated: false
---
# Créer, modifier et prévisualiser un contenu

## Résultat attendu

Créer, modifier et prévisualiser un contenu.

## Public et droits

**Profils concernés :** editor, translator, publication, admin ou super_admin.  
**Permissions :** `content.read`, `content.create`, `content.update`, `content.draft.create`.

## Prérequis

avoir choisi le site, la langue et un type de contenu dont le blueprint est actif
## Procédure

1. Ouvrez **Contenus**, choisissez le type, puis créez une entrée.
2. Renseignez le titre, le slug ou chemin proposé, les champs obligatoires du blueprint et les blocs autorisés.
3. Enregistrez le brouillon. Le serveur crée ou met à jour une révision ; la projection publique n’est pas remplacée.
4. Modifiez l’entrée puis utilisez **Prévisualiser**. L’aperçu repose sur une URL signée et montre la révision visée.
5. Corrigez les erreurs de validation affichées par champ avant de transmettre au publicateur.

## Résultat observable

Une entrée et une révision de brouillon existent. L’API publique continue de servir la dernière projection publiée tant qu’aucune publication n’a lieu.

## Erreurs fréquentes

- **Champ absent :** vérifiez la version active du blueprint.
- **Slug refusé :** il doit être unique dans la portée site/langue/route.
- **Aperçu expiré :** régénérez l’URL signée.

## Limites

La planification est représentée dans le modèle de workflow, mais ne doit être annoncée comme automatisée que si un worker ou une tâche planifiée de publication est effectivement configuré et validé sur l’instance.
