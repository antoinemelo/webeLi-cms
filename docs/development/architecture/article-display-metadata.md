---
title: Politique d’affichage des métadonnées d’article
audience:
  - developer
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: code
source_paths:
  - backend/src
  - frontend/admin-vue/src
  - tools/python

owners:
  - core
document_type: guide
permissions: []
source_paths:
  - backend/src
  - frontend
  - admin-app/src
  - database
generated: false
---

# Politique d’affichage des métadonnées d’article

Cette page décrit le contrat entre la configuration globale des articles, le résolveur public et les thèmes Twig.

## Principe

Les options définies dans **Configuration > Articles** déterminent l’affichage public des métadonnées suivantes :

- date de publication ;
- date de mise à jour ;
- auteur ;
- type de contenu.

Le résolveur public transmet ces options dans le contrat `display` consommé par les thèmes. Les templates ne doivent pas afficher directement une métadonnée sans vérifier l’option correspondante.

The dynamic public **Articles** block also inherits the same site-wide `Configuration > Articles` display policy.

## Contrat attendu

Le bloc Articles reçoit notamment :

- `show_published_date` ;
- `show_updated_date` ;
- `show_author` ;
- `show_type` ;
- `updated_at_label`.

Une valeur absente doit conserver le comportement par défaut défini dans les thèmes. Toute modification de ce contrat doit être répercutée dans `ResolvePublicRoute.php`, dans chaque thème livré et dans le validateur `c58_validate_article_block_display_policy.py`.

## Présentation compacte et responsive

The visual metadata line must stay compact everywhere articles are published. Dans les cartes du bloc **Articles**, la catégorie, date de publication, date de mise à jour et auteur restent sur une même ligne visuelle lorsque l’espace le permet. La ligne peut revenir naturellement à la ligne sur les écrans étroits : elle reste une **one wrapping row**, et ne devient pas une colonne de libellés empilés.

Les éléments sont séparés par un **point médian** produit par CSS. Separators are generated with CSS on following items, afin d’éviter les séparateurs textuels libres dans Twig et les séparateurs orphelins lorsque certaines valeurs sont absentes.

Public metadata must not prepend non-localized words. Les thèmes publics rendent donc une **label-free inline line** : aucune chaîne française telle que `par` ou `mis à jour le` ne doit être codée en dur devant l’auteur ou la date.

## Vérification

```bash
/usr/bin/python3 -m tools.python.validators.c58_validate_article_block_display_policy
```

Le contrôle échoue si le résolveur ne transmet plus le contrat complet, si un thème réintroduit un affichage de date en dur ou si cette règle n’est plus documentée.
