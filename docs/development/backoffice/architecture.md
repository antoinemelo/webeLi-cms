---
title: Architecture du back-office
audience:
  - developer
status: stable
last_verified: 2026-06-23
source_of_truth: manual
owners:
  - documentation
document_type: guide
source_paths:
  - frontend/admin-vue/src/main.ts
  - frontend/admin-vue/src/router/index.ts
  - frontend/admin-vue/vite.config.ts
  - frontend/admin-vue/index.html
  - frontend/admin-vue/src/styles.css
  - admin-app/.vite/manifest.json
generated: false
---

# Architecture du back-office

Le back-office est une application Vue compilée par Vite dans `admin-app/assets`. Le shell, le dashboard et leurs dépendances partagées forment le chargement initial. Les autres vues sont déclarées comme imports dynamiques dans le routeur et chargées à la première ouverture de leur espace fonctionnel. Vite extrait automatiquement les dépendances partagées ; aucune matrice `manualChunks` n’est maintenue.

Le manifeste `admin-app/.vite/manifest.json` est la source de contrôle du build distribué : l’entrée `index.html` doit déclarer les vues routées sous `dynamicImports`. Toute modification du routeur ou d’une vue nécessite un nouveau build avec la version de Node déclarée dans `frontend/admin-vue/package.json`.

## CSS critique du shell admin

Le shell du back-office utilise quelques composants visibles dès le premier rendu, notamment les icônes d’aide `InfoHint` dans les titres de dashboard et de pages. Ces styles sont dupliqués dans `frontend/admin-vue/index.html` et dans `frontend/admin-vue/src/styles.css` pour éviter qu’un chunk CSS partagé ne soit appliqué après le montage Vue. Le build distribué doit donc conserver, dans `admin-app/index.html`, le bloc `amcms-admin-critical-ui` et charger les feuilles de style avant le script module principal.
