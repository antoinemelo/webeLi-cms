---
title: Utiliser l’assistant IA
audience:
  - editor
  - seo-manager
status: stable
last_verified: 2026-06-14
source_of_truth: manual
owners:
  - documentation
document_type: guide
source_paths:
  - backend/src
  - admin-app/src
  - database
generated: false
---

# Utiliser l’assistant IA

## À qui s’adresse cette page

Utilisateur autorisé à demander des suggestions.

## Permissions nécessaires

`ai.use` et permissions spécialisées telles que `ai.content.suggest`, `ai.seo.suggest` ou `ai.translation.suggest`.

## Prérequis

Module IA activé, fournisseur configuré et politique d’usage acceptée.

## Procédure

1. Ouvrez l’action IA depuis le contenu autorisé.
2. Formulez une demande sans secret ni donnée personnelle inutile.
3. Examinez la proposition.
4. Modifiez-la.
5. Appliquez-la uniquement après validation humaine.

## Résultat attendu

Une suggestion est créée puis acceptée, modifiée ou rejetée par l’utilisateur.

## Risques et erreurs fréquentes

Une suggestion peut être incorrecte. Les journaux peuvent contenir prompts et réponses selon la configuration.

## Limites observées

L’assistant ne publie pas de façon autonome; son fonctionnement dépend d’un fournisseur et, pour certaines tâches, d’un worker.
