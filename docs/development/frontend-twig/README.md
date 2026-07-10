---
title: Développer le front SSR, Twig et les thèmes
audience:
  - developer
status: stable
last_verified: 2026-06-14
source_of_truth: code
owners:
  - core
document_type: guide
source_paths:
  - frontend
  - backend/src/Rendering
  - backend/src/Seo
generated: false
---
# Développer le front SSR, Twig et les thèmes

Le runtime résout une projection publiée et un template lié par blueprint. Les thèmes se trouvent sous le front et doivent conserver échappement, accessibilité, canonical, hreflang, métadonnées sociales et JSON-LD.

N’utilisez le HTML brut qu’avec une politique explicite et une permission dédiée. Testez desktop, mobile, sous-répertoire et langues.

## Champs éditables dans l’éditeur visuel

Lorsqu’un template doit être compatible avec l’éditeur visuel, exposez les zones réellement éditables avec des attributs stables, indépendants de la classe CSS de présentation.

Attributs principaux :

- `data-amcms-editable` identifie une valeur éditable ;
- `data-amcms-field-path` relie l’élément au chemin du champ dans la révision ;
- `data-amcms-block="1"` identifie le conteneur d’un bloc ;
- `data-amcms-editorial-status` expose l’état éditorial du bloc ;
- les attributs média doivent cibler l’élément média concerné, pas tout le bloc.

Placez les attributs au plus près de la valeur rendue. Un attribut posé sur un conteneur trop large rend la sélection ambiguë et oblige l’éditeur à revenir dans l’éditeur structuré. Après modification, vérifiez la résolution de la cible, l’enregistrement du champ, le rechargement de l’iframe, les langues et les trois viewports.

Voir aussi : [Éditeur visuel intégré](../backoffice/visual-editor.md).
