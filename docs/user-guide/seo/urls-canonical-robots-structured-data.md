---
title: Gérer URL, canonique, hreflang, robots et données structurées
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
  - seo.read
  - seo.manage
source_paths:
  - backend/src/Seo
  - frontend
  - backend/routes/web.php
generated: false
---
# Gérer URL, canonique, hreflang, robots et données structurées

## Résultat attendu

Gérer URL, canonique, hreflang, robots et données structurées. La procédure décrit uniquement les fonctions visibles dans la version `dec_v05-e14n`.

## Public et droits

**Profils concernés :** seo, publication, admin.  
**Permissions :** `seo.read`, `seo.manage`.

## Prérequis

des localisations et routes publiques cohérentes
## URL et canonique

La route publique est portée par la projection publiée et doit être unique dans la portée site/langue. Le canonique doit désigner l’URL de référence réelle.

## Hreflang

Les variantes linguistiques doivent être reliées uniquement lorsqu’elles représentent le même contenu. Une traduction incomplète ou non publiée ne doit pas être annoncée comme disponible.

## Robots

Utilisez `index,follow` pour une page destinée à l’indexation. Ne confondez pas `noindex` avec une protection d’accès : une information sensible doit être protégée par autorisation.

## Données structurées

Le rendu JSON-LD dépend du type de contenu, du template et des champs disponibles. Validez le HTML produit après publication.
