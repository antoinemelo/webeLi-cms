---
title: Utiliser la recherche
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
  - backend/routes
  - backend/src
  - frontend/admin-vue
generated: false
---
# Utiliser la recherche

La recherche dépend du contexte actif. Avant de conclure qu’un contenu est absent, vérifiez le site, la langue, les filtres et son statut.

## Rechercher dans le back-office

1. Ouvrez la liste de contenus concernée.
2. Vérifiez le site et la langue affichés dans l’en-tête.
3. Saisissez un titre, un mot distinctif ou un identifiant.
4. Appliquez les filtres de type et de statut disponibles.
5. Ouvrez le résultat et contrôlez qu’il s’agit bien de la bonne variante linguistique.

Un brouillon peut apparaître dans le back-office sans être visible dans la recherche publique.

## Recherche publique

La recherche publique interroge les projections publiées. Un contenu peut être absent s’il est encore en brouillon, dépublié, archivé, exclu de l’indexation ou non reconstruit dans l’index de recherche.

## En cas de résultat manquant

- contrôlez le statut de publication ;
- vérifiez le site, la langue et la route publique ;
- contrôlez les options d’indexation SEO ;
- demandez une reconstruction de l’index si une opération de maintenance l’exige.

Pour le contrat API, consultez [routes et endpoints générés](../../reference/generated/routes.md).
