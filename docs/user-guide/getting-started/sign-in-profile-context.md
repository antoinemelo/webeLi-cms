---
title: Se connecter et choisir son contexte
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
  - profile.read
  - profile.update
source_paths:
  - backend/routes/admin.php
  - backend/routes/api.php
  - admin-app/src
  - database/seeds/iam_seed.sql
generated: false
---
# Se connecter et choisir son contexte

## Résultat attendu

Se connecter et choisir son contexte. La procédure décrit uniquement les fonctions visibles dans la version `dec_v05-e14n`.

## Public et droits

**Profils concernés :** editor, translator, publication, seo, admin ou super_admin.  
**Permissions :** `profile.read`; `profile.update` pour modifier le profil.

## Prérequis

une URL de back-office, un compte actif et, si applicable, un code de double authentification
## Procédure

1. Ouvrez `/admin/login` et saisissez l’adresse et le mot de passe du compte.
2. Si une vérification supplémentaire est demandée, terminez l’étape TOTP ou e-mail configurée pour le compte.
3. Après connexion, vérifiez le **site actif**, la **langue active** et le **type de contenu** avant toute modification. Les listes et actions sont filtrées par ce contexte.
4. Ouvrez le profil pour vérifier nom, langue d’interface et paramètres personnels.
5. Utilisez la commande de déconnexion du back-office ; ne fermez pas seulement l’onglet sur un poste partagé.

## Résultat observable

Le tableau de bord et les cartes autorisées apparaissent. Les appels du back-office obtiennent leur contexte via `/admin/api/context`.

## Erreurs fréquentes

- **Boucle de connexion :** vérifiez les cookies, l’URL de base et le démarrage de session côté administration.
- **Menu manquant :** la capacité ou la permission n’est pas accordée dans la portée active.
- **Mauvais contenu visible :** changez le site ou la langue avant de conclure à une absence de données.

## Limites

Le CMS ne déduit pas automatiquement l’intention éditoriale : une modification dans le mauvais contexte peut créer une variante sur un autre site ou une autre langue.
