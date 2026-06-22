---
title: Administrer l’assistant IA
audience:
  - superadministrator
  - administrator
status: stable
last_verified: 2026-06-14
source_of_truth: manual
owners:
  - security
document_type: guide
source_paths:
  - backend/src
  - admin-app/src
  - database
generated: false
---

# Administrer l’assistant IA

## Objectif

Configurer les fournisseurs, les modèles, les usages, les budgets, les suggestions, les tâches et la conservation des journaux de l’assistant IA.

## Permissions principales

Les opérations sont séparées par permissions, notamment `ai.provider.manage`, `ai.logs.read`, `ai.suggestions.manage`, `ai.actions.apply` et `ai.use`.

## Sécurité des secrets

Un fournisseur référence son secret avec `api_key_ref`. La clé en clair ne doit jamais être renvoyée au frontend, enregistrée dans les journaux ou stockée dans les blueprints.

Le chiffrement des secrets repose sur une **clé maître hors base**. Cette clé doit être fournie par la configuration locale ou l’environnement d’exploitation et ne doit pas être enregistrée dans SQLite, exportée avec les données ni incluse dans une release.

Un `override` local peut remplacer une valeur de configuration uniquement lorsqu’il est explicitement prévu par le runtime. Il ne doit pas servir à contourner les permissions ou la séparation des sites.

## Fournisseurs, modèles et usages

1. Ouvrez **Modules > Assistant IA > Configuration**.
2. Déclarez un type de fournisseur, puis une instance de fournisseur.
3. Associez un ou plusieurs modèles et leurs usages.
4. Définissez la devise utilisée pour les budgets et les coûts du modèle.
5. Testez d’abord la connexion, puis un prompt et, lorsque disponible, le streaming.

Les paramètres administrables sont exposés par des blueprints système. Les secrets restent en dehors des valeurs de blueprint publiées au navigateur.

## Tests IA unifiés

La section **Tests** regroupe les contrôles de connexion, de prompt et de streaming. Elle permet de choisir la nature du test, l’usage à tester et d’examiner le diagnostic produit sans multiplier les écrans indépendants.

## Budgets, tâches et journaux

Les budgets peuvent concerner un site ou l’ensemble du CMS selon la permission et le contexte. Les tâches longues sont exécutées par le worker IA. Les prompts, réponses, coûts, erreurs et identifiants de fournisseur peuvent être journalisés selon la politique de conservation configurée.

## Risques et limites

- Une réponse générée doit toujours être validée humainement avant application ou publication.
- Les journaux peuvent contenir des données éditoriales sensibles.
- Le fonctionnement réel dépend du fournisseur, de son quota, de sa disponibilité et de la configuration réseau.
- Une suppression ou rotation de clé doit être suivie d’un nouveau test de connexion.
