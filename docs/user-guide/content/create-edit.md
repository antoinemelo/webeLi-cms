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
  - backend/routes/api.php
  - backend/src/Content
  - database/schema/core.sql
  - admin-app/src

owners:
  - editorial
document_type: procedure
generated: false
---
# Créer, modifier et prévisualiser un contenu

## Résultat attendu

Créer, modifier et prévisualiser un contenu avec l’éditeur structuré, puis contrôler le rendu dans la prévisualisation ou dans l’[éditeur visuel](visual-editor.md) lorsque les champs sont exposés par le thème.

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
5. Lorsque vous voulez corriger le contenu dans son contexte de rendu, ouvrez l’onglet **Visuel**, sélectionnez une zone éditable, enregistrez la modification puis vérifiez la prévisualisation rechargée.
6. Corrigez les erreurs de validation affichées par champ avant de transmettre au publicateur.

## Résultat observable

Une entrée et une révision de brouillon existent. Les modifications réalisées dans l’éditeur structuré ou dans l’éditeur visuel restent dans la révision de travail. L’API publique continue de servir la dernière projection publiée tant qu’aucune publication n’a lieu.

## Erreurs fréquentes

- **Champ absent :** vérifiez la version active du blueprint.
- **Slug refusé :** il doit être unique dans la portée site/langue/route.
- **Aperçu expiré :** régénérez l’URL signée.
- **Zone non sélectionnable en mode visuel :** le champ n’est probablement pas exposé comme éditable dans le template. Utilisez l’éditeur structuré ou demandez l’ajout des attributs Twig nécessaires.

## Limites

La planification est représentée dans le modèle de workflow, mais ne doit être annoncée comme automatisée que si un worker ou une tâche planifiée de publication est effectivement configuré et validé sur l’instance.
