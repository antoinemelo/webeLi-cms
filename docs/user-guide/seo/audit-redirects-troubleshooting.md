---
title: Utiliser l’audit SEO et les redirections
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
  - backend/routes/api.php
  - backend/src/Seo
  - `python3 tools/cms.py validate --category content`
generated: false
---
# Utiliser l’audit SEO et les redirections

## Résultat attendu

Utiliser l’audit SEO et les redirections. La procédure décrit uniquement les fonctions visibles dans la version `dec_v05-e14n`.

## Public et droits

**Profils concernés :** seo, publication, admin.  
**Permissions :** `seo.read`, `seo.manage`.

## Prérequis

un site et une langue actifs
## Audit SEO

L’endpoint administratif `/admin/api/seo/audit` fournit les contrôles disponibles. Classez les résultats en erreurs bloquantes, avertissements et recommandations.

## Redirections

Avant de changer une URL publiée, créez une redirection lorsqu’un mécanisme de redirection est disponible dans l’interface de l’instance. L’inventaire runtime de cette version ne montre pas de route administrative dédiée aux redirections ; ne documentez donc pas une gestion complète comme fonction confirmée sans module ou code supplémentaire.

## Dépannage

Une page absente du sitemap peut être non publiée, non indexable, rattachée au mauvais site/langue ou exclue par sa politique de route.
