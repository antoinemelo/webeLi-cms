---
title: Administrer champs, fieldsets et blocs
audience:
  - administrator
  - superadministrator
status: stable
last_verified: 2026-06-14
source_of_truth: code
source_paths:
  - backend/src
  - frontend/admin-vue/src
  - database

owners:
  - operations
  - core
document_type: guide
source_paths:
  - backend/routes/api.php
  - backend/src/Blueprints
  - database/schema/core.sql
generated: false
---
# Administrer champs, fieldsets et blocs

Les types de champs sont exposés par `/admin/api/field-types`. Les fieldsets sont des schémas réutilisables versionnés par clé. Les block blueprints décrivent les blocs disponibles.

## Règles

- Ne réutilisez pas une clé avec une sémantique différente.
- Déclarez validation, localisation et aide dans le schéma.
- Testez les données existantes avant de rendre un champ obligatoire.
- Protégez les types capables de HTML brut.
- Ajoutez un rendu public et un contrat API avant activation.

## Blueprints système de sécurité

Les réglages de sécurité administrables reposent aussi sur des blueprints système. Ils ne créent pas des types de contenus publics et ne sont pas exposés par l’API headless.

Le blueprint `security_api_token` décrit les tokens d’API. Il porte notamment les champs `title`, `name`, `scopes`, `expires_at`, `is_active` et `site_id`. Sa `permissions_policy` s’appuie sur `security.tokens.read` pour la consultation et `security.tokens.manage` pour les opérations de gestion.

Les blueprints `security_webhook`, `security_cors` et `iam_email_2fa` suivent le même principe. Ils restent rattachés à la matrice IAM et doivent être modifiés avec prudence, car une incohérence entre leurs champs, leurs permissions et l’interface d’administration peut rendre un réglage de sécurité inaccessible ou incorrectement exposé.
