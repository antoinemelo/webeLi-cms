---
title: Admin internationalization
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

# Admin internationalization

The admin UI uses the TypeScript catalogue in `frontend/admin-vue/src/i18n/messages.ts`.

## Principles

- The interface language is separate from the editorial content language.
- Keys are grouped by domain namespace: `core.*`, `business.*`, `sale.*`, `maintenance.*`, `search.*` and `support.*`.
- Technical identifiers, API codes, SKU values, slugs, file paths and contract values are not translated.
- Dates, numbers, percentages and monetary amounts must use the helpers from `frontend/admin-vue/src/i18n/index.ts`.
- A French fallback is allowed, but each fallback is recorded in `window.__AMCMS_I18N_FALLBACKS__`.

## Adding a key

1. Add the key to the `fr` catalogue.
2. Add the same key to the `en` catalogue.
3. Keep the same `{name}` parameters in both languages.
4. Use `t('namespace.key', { name: value })` from the component.
5. For money, dates and numbers, use `money()`, `dateTime()`, `n()` or `p()` from `useI18n()`.
6. Run `python3 tools/cms.py validate --validator I18N_COVERAGE`.

## Adding a language

1. Add the language code to `UI_LANGUAGES`.
2. Add a complete catalogue aligned with `fr`.
3. Extend `normalizeUiLanguage()`.
4. Test one desktop and one mobile admin journey with the new language.
5. Update tests and the validator if the new language becomes mandatory.

## Covered areas

The native i18n layer currently covers the admin shell, navigation, global search, configuration language controls, Sale/POS and Maintenance.

New admin screens should not introduce visible hard-coded interface strings in these covered areas. Dynamic content names, module names, vendor names, paths and API values may stay as data.

## Checks

The `I18N_COVERAGE` validator checks:

- French and English catalogue presence;
- missing keys between catalogues;
- inconsistent interpolation parameters;
- required namespaces;
- fallback and formatting helpers;
- used keys in covered UI files;
- visible hard-coded French strings in covered Core/Sale/Maintenance screens;
- presence of the French and English developer documentation pages.
