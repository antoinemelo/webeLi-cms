---
title: Internationalisation de l’administration
audience:
  - developer
status: active
last_verified: 2026-07-11
source_of_truth: manual
owners:
  - core
document_type: guide
source_paths:
  - frontend/admin-vue/src/i18n
  - tools/python/validation/content/i18n.py
generated: false
---

# Internationalisation de l’administration

L’interface d’administration utilise le catalogue TypeScript `frontend/admin-vue/src/i18n/messages.ts`.

## Principes

- La langue d’interface est distincte de la langue du contenu éditorial.
- Les clés sont organisées par namespace métier : `core.*`, `business.*`, `sale.*`, `maintenance.*`, `search.*`, `support.*`.
- Les identifiants techniques, codes API, SKU, slugs, chemins de fichiers et valeurs contractuelles ne sont pas traduits.
- Les formats de dates, nombres, pourcentages et devises passent par les helpers de `frontend/admin-vue/src/i18n/index.ts`.
- Un fallback français est autorisé, mais il est observable via `window.__AMCMS_I18N_FALLBACKS__`.

## Ajouter une clé

1. Ajouter la clé dans le catalogue `fr`.
2. Ajouter la même clé dans le catalogue `en`.
3. Garder les mêmes paramètres `{name}` dans les deux langues.
4. Utiliser `t('namespace.key', { name: value })` dans le composant.
5. Pour les montants et dates, utiliser `money()`, `dateTime()`, `n()` ou `p()` depuis `useI18n()`.
6. Lancer `python3 tools/cms.py validate --validator I18N_COVERAGE`.

## Zones couvertes

La couche i18n native couvre actuellement le shell admin, la navigation, la recherche globale, les contrôles de langue de Configuration, Vente/POS et Maintenance.

Les nouveaux écrans admin ne doivent pas introduire de chaînes visibles codées en dur dans ces zones couvertes. Les noms dynamiques de contenus, modules, vendors, chemins et valeurs API restent des données et ne sont pas traduits par le catalogue.

## Ajouter une langue

1. Ajouter le code dans `UI_LANGUAGES`.
2. Ajouter un catalogue complet aligné sur `fr`.
3. Étendre `normalizeUiLanguage()`.
4. Tester un parcours admin desktop et mobile avec cette langue.
5. Mettre à jour les tests et le validateur si la nouvelle langue devient obligatoire.

## Contrôles

Le validateur `I18N_COVERAGE` vérifie :

- présence des catalogues français et anglais ;
- clés manquantes entre catalogues ;
- paramètres incohérents ;
- namespaces requis ;
- helpers de fallback et formatage ;
- clés utilisées dans les zones couvertes ;
- chaînes visibles codées en dur sur les écrans Core/Sale/Maintenance déjà internationalisés ;
- présence des pages de documentation française et anglaise.
