---
title: Configurer et vérifier le consentement aux cookies
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
  - backend/src/Modules/Cookies
  - database/modules/cookies.sql

owners:
  - editorial
document_type: procedure
generated: false
---
# Configurer et vérifier le consentement aux cookies

## Résultat attendu

Configurer et vérifier le consentement aux cookies.

## Public et droits

**Profils concernés :** admin, seo ou responsable conformité.  
**Permissions :** `cookies.read`, `cookies.manage`.

## Prérequis

avoir inventorié les services réellement chargés par le site
## Procédure

1. Définissez les catégories et services.
2. Associez les scripts ou intégrations aux catégories.
3. Configurez les textes et choix proposés.
4. Testez acceptation, refus et modification du choix.
5. Vérifiez les journaux et l’absence de chargement avant consentement pour les services concernés.

## Limites

Le CMS fournit un mécanisme technique ; la conformité juridique dépend de la configuration, des services tiers et de la juridiction.
