---
title: Réviser et publier un contenu
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
  - backend/src/Publishing
  - backend/src/Content
  - `python3 tools/cms.py test`
generated: false
---
# Réviser et publier un contenu

## Résultat attendu

Réviser et publier un contenu. La procédure décrit uniquement les fonctions visibles dans la version `dec_v05-e14n`.

## Public et droits

**Profils concernés :** publication, admin ou super_admin.  
**Permissions :** `content.approve`, `content.publish` ou `admin.entries.publish` selon le point d’entrée.

## Prérequis

une révision valide, un contexte correct et les éléments obligatoires renseignés
## Contrôle avant publication

1. Ouvrez le brouillon à contrôler.
2. Consultez la révision à publier et vérifiez le site, la langue, le chemin, le contenu, les médias et le SEO.
3. Prévisualisez cette révision exacte.
4. Corrigez les erreurs bloquantes : un champ requis absent, une route en conflit, un média invalide, un schéma incompatible ou une permission insuffisante peut bloquer l’opération.
5. Lancez la publication.
6. Vérifiez la page publique, la route API, les menus concernés, la recherche et l’audit SEO.

## Effets de la publication

Le pipeline matérialise une projection publiée distincte du brouillon. Les routes publiques, l’API de contenu et la recherche utilisent cette projection. Un menu ne se met à jour que si sa destination et sa propre publication/configuration sont valides.

## Limites

La mise en cache externe, les CDN et les moteurs de recherche peuvent retarder la visibilité. Le CMS ne garantit pas l’indexation par un moteur tiers.
