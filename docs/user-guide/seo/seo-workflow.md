---
title: Optimiser le SEO d’un contenu
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

owners:
  - editorial
document_type: procedure
source_paths:
  - backend/routes/api.php
  - backend/src/Seo
  - frontend
  - database/schema/core.sql
generated: false
---
# Optimiser le SEO d’un contenu

## Résultat attendu

Optimiser le SEO d’un contenu.

## Public et droits

**Profils concernés :** editor, publication ou seo.  
**Permissions :** `seo.read`; `seo.manage` pour les réglages avancés.

## Prérequis

un contenu avec capacité SEO activée
## Procédure

1. Rédigez un titre éditorial clair.
2. Renseignez le titre SEO et la description sans dupliquer mécaniquement le contenu.
3. Vérifiez le slug ou chemin, la langue et le site.
4. Contrôlez l’indexation, le canonique, l’image sociale et les données structurées proposées.
5. Lancez l’audit SEO et corrigez les erreurs bloquantes.
6. Après publication, vérifiez le HTML public, le sitemap et la route canonique.

## Bonnes pratiques

Le CMS produit des signaux techniques ; la qualité sémantique, la pertinence et les liens éditoriaux restent une responsabilité humaine.

## Sitemap XML public

Le CMS propose deux accès complémentaires au plan du site :

- `/sitemap` affiche une version lisible par une personne, construite à partir des routes publiques disponibles pour le site et la langue actifs ;
- `/sitemap.xml` fournit le sitemap XML destiné aux moteurs de recherche. La feuille `sitemap.xsl`, référencée relativement, améliore sa lecture dans un navigateur sans modifier son contenu technique.

Le sitemap XML ne reprend que les routes publiques canoniques et indexables. Les chemins techniques ou privés liés notamment à l’administration, à l’API, à la prévisualisation et à la recherche en sont exclus. Les contenus marqués `noindex` ne doivent pas y apparaître.

Pour les contenus traduits, les variantes sont déclarées avec `hreflang`. L’entrée `x-default` désigne l’URL de repli lorsqu’aucune langue déclarée ne correspond au visiteur. Après une publication, une dépublication ou une modification de route, vérifiez que l’URL canonique et ses variantes figurent comme prévu.

Le contrat technique est contrôlé avec :

```bash
python3 tools/cms.py validate
```

Ce validateur vérifie notamment la source des routes publiques, l’exclusion des chemins privés, les alternates `hreflang`, la présence de `x-default`, l’échappement XML, la page humaine `/sitemap` et la présentation par `sitemap.xsl`.
