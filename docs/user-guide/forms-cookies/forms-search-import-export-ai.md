---
title: Utiliser formulaires, recherche, imports, exports et assistant IA
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
  - backend/src/Modules/Forms
  - backend/src/Modules/AiAssistant
  - backend/routes/api.php
  - database/modules
generated: false
---
# Utiliser formulaires, recherche, imports, exports et assistant IA

## Résultat attendu

Utiliser formulaires, recherche, imports, exports et assistant IA. La procédure décrit uniquement les fonctions visibles dans la version `dec_v05-e14n`.

## Public et droits

**Profils concernés :** editor, publication, seo, admin selon la fonction.  
**Permissions :** `forms.read/manage`, `imports_exports.read/write/manage`, permissions IA.

## Prérequis

modules correspondants activés et configurés
## Formulaires

Créez le formulaire, définissez ses champs, publiez son schéma puis contrôlez les soumissions et l’export CSV. Les données collectées doivent respecter la politique de confidentialité de l’exploitant.

## Recherche

La recherche publique interroge l’index de projections publiées. Une entrée brouillon n’y apparaît pas.

## Imports et exports

Les paquets éditoriaux et exports statiques sont des opérations sensibles. Validez le manifeste, la portée site/langue et les collisions avant import. Conservez une sauvegarde.

## Assistant IA

L’IA produit des suggestions, pas une vérité éditoriale. Vérifiez les faits, droits, données personnelles, ton et SEO. Les clés fournisseur restent dans l’environnement ou le secret configuré ; elles ne doivent jamais être insérées dans un contenu.

## Limites

La génération dépend du fournisseur activé, de son modèle, du budget et du réseau. Un échec IA ne doit pas empêcher l’édition manuelle.
