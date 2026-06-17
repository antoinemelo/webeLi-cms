---
title: Correctif des exécutables frontend
audience:
  - developers
  - operators
status: current
source_of_truth: frontend/admin-vue/package.json
owners:
  - core-team
document_type: changelog
---

# Correctif des exécutables frontend

## Problème

Après copie ou extraction d'une archive, les wrappers de `node_modules/.bin/`
peuvent perdre leur bit d'exécution. `npm run build` échouait alors avec :

```text
sh: 1: vue-tsc: Permission denied
```

## Correction

Les scripts npm invoquent désormais les points d'entrée JavaScript avec `node` :

- `vue-tsc/bin/vue-tsc.js` ;
- `vite/bin/vite.js` ;
- `@playwright/test/cli.js`.

La qualification vérifie ces fichiers réels au lieu des wrappers `.bin`.

## Effet

Le build ne dépend plus des permissions Unix des liens ou wrappers générés dans
`node_modules/.bin`. Une dépendance absente reste signalée comme qualification
incomplète et n'est jamais considérée comme réussie.
