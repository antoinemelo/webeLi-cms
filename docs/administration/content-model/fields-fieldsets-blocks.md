---
title: Administrer champs, fieldsets et blocs
audience:
  - administrator
  - superadministrator
status: stable
last_verified: 2026-06-23
source_of_truth: code
source_paths:
  - backend/src
  - frontend/admin-vue/src
  - database
  - backend/routes/api.php
  - backend/src/Blueprints
  - database/schema/core.sql

owners:
  - operations
  - core
document_type: guide
generated: false
---
# Administrer champs, fieldsets et blocs

Les types de champs sont exposés par `/admin/api/field-types`. Les fieldsets sont des groupes de champs réutilisables, identifiés par une clé stable. Les block blueprints décrivent les blocs disponibles dans les éditeurs de contenu.

## Champs

Un champ porte une clé, un libellé, un type, des règles de validation et une configuration. La clé doit rester stable lorsqu’elle correspond déjà à des données existantes. Changer le type d’un champ ou le rendre obligatoire doit être traité comme un changement de contrat : vérifiez les contenus historiques avant activation.

L’interface conserve les configurations JSON inconnues afin de ne pas perdre les options historiques ou propres à un projet. Un administrateur peut donc corriger un champ sans effacer des clés que l’interface ne sait pas encore interpréter.

## Fieldsets

Un fieldset regroupe des champs réutilisables. Il peut être monté dans plusieurs structures. Sa fiche affiche les usages exacts : libellé de structure, clé, type, site et portée.

Modifier un fieldset prépare un changement partagé. Les contenus n’utilisent ce changement que lorsque la structure qui monte le fieldset a enregistré puis activé un nouveau brouillon. Si un seul site ou une seule structure doit diverger, utilisez une variante plutôt qu’une modification du groupe commun.

Les fieldsets système sont protégés. Ils peuvent être consultés pour comprendre le modèle natif, mais ne doivent pas être modifiés ou supprimés depuis l’interface.

## Blocs

Les block blueprints déclarent les blocs proposés dans les éditeurs. Ils doivent rester cohérents avec le rendu public, la validation et les contrats API qui exposent les contenus publiés.

## Règles

- Ne réutilisez pas une clé avec une sémantique différente.
- Déclarez validation, localisation et aide dans le schéma.
- Testez les données existantes avant de rendre un champ obligatoire.
- Protégez les types capables de HTML brut.
- Ajoutez un rendu public et un contrat API avant activation.
- Activez explicitement le brouillon qui porte le changement de structure.

## Blueprints système de sécurité

Les réglages de sécurité administrables reposent aussi sur des blueprints système. Ils ne créent pas des types de contenus publics et ne sont pas exposés par l’API headless.

Le blueprint `security_api_token` décrit les tokens d’API. Il porte notamment les champs `title`, `name`, `scopes`, `expires_at`, `is_active` et `site_id`. Sa `permissions_policy` s’appuie sur `security.tokens.read` pour la consultation et `security.tokens.manage` pour les opérations de gestion.

Les blueprints `security_webhook`, `security_cors` et `iam_login_mode` suivent le même principe. Ils restent rattachés à la matrice IAM et doivent être modifiés avec prudence, car une incohérence entre leurs champs, leurs permissions et l’interface d’administration peut rendre un réglage de sécurité inaccessible ou incorrectement exposé.
