---
title: Routes runtime et endpoints API
audience:
  - developer
  - installer
  - evaluator
status: stable
version: 1.0
source_of_truth: generated
generator: tools/python/generators/generate_documentation.py
owners:
  - core
document_type: reference
generated: true
---
# Routes runtime et endpoints API

> Fichier généré. Ne pas modifier directement.

| Méthode | Chemin | Surface | Déclaration |
|---|---|---|---|
| `DELETE` | `/admin/api/ai/models/{providerKey}/{modelKey}` | API administrative | `backend/src/Modules/AiAssistant/AiAssistantModuleProvider.php` |
| `DELETE` | `/admin/api/ai/provider-types/{key}` | API administrative | `backend/src/Modules/AiAssistant/AiAssistantModuleProvider.php` |
| `DELETE` | `/admin/api/ai/providers/{key}` | API administrative | `backend/src/Modules/AiAssistant/AiAssistantModuleProvider.php` |
| `DELETE` | `/admin/api/ai/usage` | API administrative | `backend/src/Modules/AiAssistant/AiAssistantModuleProvider.php` |
| `DELETE` | `/admin/api/blueprints/{key}` | API administrative | `backend/routes/api.php` |
| `DELETE` | `/admin/api/cookies/bindings/{id}` | API administrative | `backend/routes/api.php` |
| `DELETE` | `/admin/api/cookies/categories/{id}` | API administrative | `backend/routes/api.php` |
| `DELETE` | `/admin/api/cookies/logs` | API administrative | `backend/routes/api.php` |
| `DELETE` | `/admin/api/cookies/services/{id}` | API administrative | `backend/routes/api.php` |
| `DELETE` | `/admin/api/entries/{id}` | API administrative | `backend/routes/api.php` |
| `DELETE` | `/admin/api/entries/{id}/revisions/prune-before-published` | API administrative | `backend/routes/api.php` |
| `DELETE` | `/admin/api/fieldsets/{key}` | API administrative | `backend/routes/api.php` |
| `DELETE` | `/admin/api/forms/{id}` | API administrative | `backend/routes/api.php` |
| `DELETE` | `/admin/api/iam/sessions/{id}` | API administrative | `backend/routes/api.php` |
| `DELETE` | `/admin/api/iam/users/{id}` | API administrative | `backend/routes/api.php` |
| `DELETE` | `/admin/api/imports-exports/imports/{importId:[A-Za-z0-9._-]+}` | API administrative | `backend/routes/api.php` |
| `DELETE` | `/admin/api/imports-exports/static/{releaseId:[A-Za-z0-9._-]+}` | API administrative | `backend/routes/api.php` |
| `DELETE` | `/admin/api/maintenance/audit-logs` | API administrative | `backend/routes/api.php` |
| `DELETE` | `/admin/api/maintenance/runtime-logs` | API administrative | `backend/routes/api.php` |
| `DELETE` | `/admin/api/media/{id}` | API administrative | `backend/routes/api.php` |
| `DELETE` | `/admin/api/menus/{key}` | API administrative | `backend/routes/api.php` |
| `DELETE` | `/admin/api/multisite/sites/{id}` | API administrative | `backend/routes/api.php` |
| `DELETE` | `/admin/api/security/tokens/{id}` | API administrative | `backend/routes/api.php` |
| `DELETE` | `/admin/api/security/webhooks/deliveries/cleanup` | API administrative | `backend/routes/api.php` |
| `DELETE` | `/admin/api/security/webhooks/{id}` | API administrative | `backend/routes/api.php` |
| `DELETE` | `/admin/api/taxonomies/{key}` | API administrative | `backend/routes/api.php` |
| `DELETE` | `/admin/api/taxonomies/{key}/terms/{id}` | API administrative | `backend/routes/api.php` |
| `DELETE` | `/admin/api/visual/entries/{id}/block-lock` | API administrative | `backend/routes/api.php` |
| `GET` | `/` | HTML/runtime | `backend/routes/web.php` |
| `GET` | `/admin` | API administrative | `backend/routes/admin.php` |
| `GET` | `/admin/` | API administrative | `backend/routes/admin.php` |
| `GET` | `/admin/api/ai/actions` | API administrative | `backend/src/Modules/AiAssistant/AiAssistantModuleProvider.php` |
| `GET` | `/admin/api/ai/budget` | API administrative | `backend/src/Modules/AiAssistant/AiAssistantModuleProvider.php` |
| `GET` | `/admin/api/ai/prompts` | API administrative | `backend/src/Modules/AiAssistant/AiAssistantModuleProvider.php` |
| `GET` | `/admin/api/ai/providers` | API administrative | `backend/src/Modules/AiAssistant/AiAssistantModuleProvider.php` |
| `GET` | `/admin/api/ai/providers/test/stream` | API administrative | `backend/src/Modules/AiAssistant/AiAssistantModuleProvider.php` |
| `GET` | `/admin/api/ai/settings` | API administrative | `backend/src/Modules/AiAssistant/AiAssistantModuleProvider.php` |
| `GET` | `/admin/api/ai/site-settings` | API administrative | `backend/src/Modules/AiAssistant/AiAssistantModuleProvider.php` |
| `GET` | `/admin/api/ai/status` | API administrative | `backend/src/Modules/AiAssistant/AiAssistantModuleProvider.php` |
| `GET` | `/admin/api/ai/suggestions` | API administrative | `backend/src/Modules/AiAssistant/AiAssistantModuleProvider.php` |
| `GET` | `/admin/api/ai/tasks/{id}` | API administrative | `backend/src/Modules/AiAssistant/AiAssistantModuleProvider.php` |
| `GET` | `/admin/api/ai/usage` | API administrative | `backend/src/Modules/AiAssistant/AiAssistantModuleProvider.php` |
| `GET` | `/admin/api/block-blueprints` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/block-blueprints/{type}` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/blueprints` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/blueprints/model` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/blueprints/{key}` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/blueprints/{key}/design` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/blueprints/{key}/editor-schema` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/blueprints/{key}/versions` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/capabilities` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/configuration` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/content-types` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/content-types/{type}/editor-schema` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/context` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/cookies` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/docs` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/docs/resolve` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/docs/{id:.+}` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/entries` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/entries/{id}` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/entries/{id}/preview` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/entries/{id}/revisions` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/entries/{id}/revisions/{revisionId}` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/entries/{id}/revisions/{revisionId}/preview` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/field-types` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/fieldsets` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/fieldsets/{key}` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/forms` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/forms/{id}` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/forms/{id}/export.csv` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/forms/{id}/submissions` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/iam/audit-logs` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/iam/permissions` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/iam/roles` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/iam/sessions` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/iam/sites` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/iam/users` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/iam/users/{id}` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/imports-exports` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/imports-exports/editorial/{releaseId:[A-Za-z0-9._-]+}/zip` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/imports-exports/static/{releaseId:[A-Za-z0-9._-]+}/manifest` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/imports-exports/static/{releaseId:[A-Za-z0-9._-]+}/report` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/imports-exports/static/{releaseId:[A-Za-z0-9._-]+}/zip` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/maintenance` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/media` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/media/folders` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/media/hygiene` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/media/settings` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/media/unused` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/media/variant-presets` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/media/{id}` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/menus` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/menus/{key}` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/modules` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/modules/{key}` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/modules/{key}/blueprints` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/modules/{key}/health` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/modules/{key}/resources/{resource}/schema` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/multisite` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/profile` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/security` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/security/webhooks/{id}/deliveries` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/seo/audit` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/taxonomies` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/taxonomy-terms` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/visual/entries/{id}/block-locks` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/visual/entries/{id}/map` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/visual/entries/{id}/preview` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/visual/entries/{id}/translation-status` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/api/visual/resolve` | API administrative | `backend/routes/api.php` |
| `GET` | `/admin/app` | API administrative | `backend/routes/admin.php` |
| `GET` | `/admin/app/` | API administrative | `backend/routes/admin.php` |
| `GET` | `/admin/app/{path:.+}` | API administrative | `backend/routes/admin.php` |
| `GET` | `/admin/forgot-password` | API administrative | `backend/routes/admin.php` |
| `GET` | `/admin/login` | API administrative | `backend/routes/admin.php` |
| `GET` | `/admin/reset-password` | API administrative | `backend/routes/admin.php` |
| `GET` | `/api/v1/content` | API publique | `backend/routes/api.php` |
| `GET` | `/api/v1/content-by-path` | API publique | `backend/routes/api.php` |
| `GET` | `/api/v1/content/{type}` | API publique | `backend/routes/api.php` |
| `GET` | `/api/v1/content/{type}/{slug}` | API publique | `backend/routes/api.php` |
| `GET` | `/api/v1/cookies/config` | API publique | `backend/routes/api.php` |
| `GET` | `/api/v1/forms/{key}` | API publique | `backend/routes/api.php` |
| `GET` | `/api/v1/health` | API publique | `backend/routes/api.php` |
| `GET` | `/api/v1/languages` | API publique | `backend/routes/api.php` |
| `GET` | `/api/v1/media` | API publique | `backend/routes/api.php` |
| `GET` | `/api/v1/media/{id}` | API publique | `backend/routes/api.php` |
| `GET` | `/api/v1/menus` | API publique | `backend/routes/api.php` |
| `GET` | `/api/v1/menus/{key}` | API publique | `backend/routes/api.php` |
| `GET` | `/api/v1/modules/forms/forms/schema` | API publique | `backend/src/Modules/Forms/FormsModuleProvider.php` |
| `GET` | `/api/v1/modules/{module}/{resource}/schema` | API publique | `backend/routes/api.php` |
| `GET` | `/api/v1/route` | API publique | `backend/routes/api.php` |
| `GET` | `/api/v1/routes` | API publique | `backend/routes/api.php` |
| `GET` | `/api/v1/search` | API publique | `backend/routes/api.php` |
| `GET` | `/api/v1/taxonomies` | API publique | `backend/routes/api.php` |
| `GET` | `/api/v1/taxonomies/{taxonomy}` | API publique | `backend/routes/api.php` |
| `GET` | `/docs/public-api` | HTML/runtime | `backend/routes/web.php` |
| `GET` | `/docs/public-api/{file:index\\.html|openapi\\.v1\\.json|openapi\\.v1\\.yaml|quickstart\\.md|authentication\\.md|errors\\.md|examples\\.md}` | HTML/runtime | `backend/routes/web.php` |
| `GET` | `/examples/{example:headless-next|headless-nuxt|headless-astro|headless-vanilla}/README.md` | HTML/runtime | `backend/routes/web.php` |
| `GET` | `/{path:.+}` | HTML/runtime | `backend/routes/web.php` |
| `PATCH` | `/admin/api/configuration` | API administrative | `backend/routes/api.php` |
| `PATCH` | `/admin/api/cookies/bindings/{id}` | API administrative | `backend/routes/api.php` |
| `PATCH` | `/admin/api/cookies/categories/{id}` | API administrative | `backend/routes/api.php` |
| `PATCH` | `/admin/api/cookies/services/{id}` | API administrative | `backend/routes/api.php` |
| `PATCH` | `/admin/api/cookies/settings` | API administrative | `backend/routes/api.php` |
| `PATCH` | `/admin/api/entries/{id}` | API administrative | `backend/routes/api.php` |
| `PATCH` | `/admin/api/forms/{id}` | API administrative | `backend/routes/api.php` |
| `PATCH` | `/admin/api/iam/roles/{id}` | API administrative | `backend/routes/api.php` |
| `PATCH` | `/admin/api/iam/users/{id}` | API administrative | `backend/routes/api.php` |
| `PATCH` | `/admin/api/media/folders/{id}` | API administrative | `backend/routes/api.php` |
| `PATCH` | `/admin/api/media/{id}` | API administrative | `backend/routes/api.php` |
| `PATCH` | `/admin/api/menus/{key}` | API administrative | `backend/routes/api.php` |
| `PATCH` | `/admin/api/profile` | API administrative | `backend/routes/api.php` |
| `PATCH` | `/admin/api/profile/password` | API administrative | `backend/routes/api.php` |
| `PATCH` | `/admin/api/security/cors/{siteId}` | API administrative | `backend/routes/api.php` |
| `PATCH` | `/admin/api/security/tokens/{id}` | API administrative | `backend/routes/api.php` |
| `PATCH` | `/admin/api/security/webhooks/{id}` | API administrative | `backend/routes/api.php` |
| `PATCH` | `/admin/api/taxonomies/{key}` | API administrative | `backend/routes/api.php` |
| `PATCH` | `/admin/api/taxonomies/{key}/terms/{id}` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/ai/editorial/test-generation` | API administrative | `backend/src/Modules/AiAssistant/AiAssistantModuleProvider.php` |
| `POST` | `/admin/api/ai/models` | API administrative | `backend/src/Modules/AiAssistant/AiAssistantModuleProvider.php` |
| `POST` | `/admin/api/ai/provider-types` | API administrative | `backend/src/Modules/AiAssistant/AiAssistantModuleProvider.php` |
| `POST` | `/admin/api/ai/providers` | API administrative | `backend/src/Modules/AiAssistant/AiAssistantModuleProvider.php` |
| `POST` | `/admin/api/ai/providers/test` | API administrative | `backend/src/Modules/AiAssistant/AiAssistantModuleProvider.php` |
| `POST` | `/admin/api/ai/settings` | API administrative | `backend/src/Modules/AiAssistant/AiAssistantModuleProvider.php` |
| `POST` | `/admin/api/ai/site-settings` | API administrative | `backend/src/Modules/AiAssistant/AiAssistantModuleProvider.php` |
| `POST` | `/admin/api/ai/suggestions/propose` | API administrative | `backend/src/Modules/AiAssistant/AiAssistantModuleProvider.php` |
| `POST` | `/admin/api/ai/suggestions/{id}/accept` | API administrative | `backend/src/Modules/AiAssistant/AiAssistantModuleProvider.php` |
| `POST` | `/admin/api/ai/suggestions/{id}/apply` | API administrative | `backend/src/Modules/AiAssistant/AiAssistantModuleProvider.php` |
| `POST` | `/admin/api/ai/suggestions/{id}/expire` | API administrative | `backend/src/Modules/AiAssistant/AiAssistantModuleProvider.php` |
| `POST` | `/admin/api/ai/suggestions/{id}/propose` | API administrative | `backend/src/Modules/AiAssistant/AiAssistantModuleProvider.php` |
| `POST` | `/admin/api/ai/suggestions/{id}/reject` | API administrative | `backend/src/Modules/AiAssistant/AiAssistantModuleProvider.php` |
| `POST` | `/admin/api/ai/tasks` | API administrative | `backend/src/Modules/AiAssistant/AiAssistantModuleProvider.php` |
| `POST` | `/admin/api/ai/tasks/{id}/cancel` | API administrative | `backend/src/Modules/AiAssistant/AiAssistantModuleProvider.php` |
| `POST` | `/admin/api/blueprints` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/blueprints/{key}/activate` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/blueprints/{key}/versions` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/capabilities/{key}/apply` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/capabilities/{key}/dry-run` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/cookies/bindings` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/cookies/categories` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/cookies/services` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/entries` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/entries/{id}/archive` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/entries/{id}/publish` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/entries/{id}/revisions/{revisionId}/restore` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/entries/{id}/unpublish` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/fieldsets` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/forms` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/iam/roles` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/iam/users` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/iam/users/{id}/activate` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/iam/users/{id}/deactivate` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/iam/users/{id}/reset-password` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/iam/users/{id}/totp/disable` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/iam/users/{id}/totp/enable` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/iam/users/{id}/totp/prepare` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/iam/users/{id}/totp/recovery-codes` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/imports-exports/imports/execute` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/imports-exports/imports/inspect` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/imports-exports/static/actions/all-languages` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/imports-exports/static/actions/language` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/imports-exports/static/actions/page` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/imports-exports/static/actions/rerun` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/imports-exports/static/actions/site` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/maintenance/cache/clear` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/maintenance/search/reindex` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/media` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/media/folders` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/media/{id}/attach` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/media/{id}/variants` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/menus` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/modules/{key}/disable` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/modules/{key}/enable` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/modules/{key}/install` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/modules/{key}/migrate` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/multisite/sites` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/security/tokens` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/security/tokens/test` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/security/webhooks` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/security/webhooks/{id}/ping` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/taxonomies` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/taxonomies/{key}/terms` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/visual/entries/{id}/block-lock` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/api/visual/entries/{id}/field` | API administrative | `backend/routes/api.php` |
| `POST` | `/admin/forgot-password` | API administrative | `backend/routes/admin.php` |
| `POST` | `/admin/login` | API administrative | `backend/routes/admin.php` |
| `POST` | `/admin/logout` | API administrative | `backend/routes/admin.php` |
| `POST` | `/admin/reset-password` | API administrative | `backend/routes/admin.php` |
| `POST` | `/api/v1/cookies/consent` | API publique | `backend/routes/api.php` |
| `POST` | `/api/v1/forms/{key}/submit` | API publique | `backend/routes/api.php` |
| `PUT` | `/admin/api/blueprints/{key}/design` | API administrative | `backend/routes/api.php` |
| `PUT` | `/admin/api/fieldsets/{key}` | API administrative | `backend/routes/api.php` |
| `PUT` | `/admin/api/menus/{key}/items` | API administrative | `backend/routes/api.php` |
