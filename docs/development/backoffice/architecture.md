---
title: Architecture du back-office
audience:
  - developer
status: stable
version: 1.1
last_verified: 2026-06-22
source_of_truth: manual
owners:
  - documentation
document_type: guide
source_paths:
  - frontend/admin-vue/src/main.ts
  - frontend/admin-vue/src/router/index.ts
  - frontend/admin-vue/vite.config.ts
  - admin-app/.vite/manifest.json
generated: false
---

# Architecture du back-office

Le back-office est une application Vue compilée par Vite dans `admin-app/assets`. Le shell, le dashboard et leurs dépendances partagées forment le chargement initial. Les autres vues sont déclarées comme imports dynamiques dans le routeur et chargées à la première ouverture de leur espace fonctionnel. Vite extrait automatiquement les dépendances partagées ; aucune matrice `manualChunks` n’est maintenue.

Le manifeste `admin-app/.vite/manifest.json` est la source de contrôle du build distribué : l’entrée `index.html` doit déclarer les vues routées sous `dynamicImports`. Toute modification du routeur ou d’une vue nécessite un nouveau build avec la version de Node déclarée dans `frontend/admin-vue/package.json`.
