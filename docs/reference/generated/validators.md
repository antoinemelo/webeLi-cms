---
title: Registre des validateurs
audience:
  - developer
  - installer
  - evaluator
status: stable
source_of_truth: generated
generator: tools/python/generators/generate_documentation.py
owners:
  - core
document_type: reference
generated: true
---
# Registre des validateurs

> Fichier généré. Ne pas modifier directement.

| Identifiant | Domaine | Modes | Module |
|---|---|---|---|
| `REPOSITORY_LAYOUT` | `configuration` | `fast`, `full`, `slow` | `tools.python.validation.configuration.repository_layout` |
| `CONFIG_CONSISTENCY` | `configuration` | `fast`, `full`, `slow` | `tools.python.validation.configuration.config_consistency` |
| `DB_SCHEMA` | `database` | `fast`, `full`, `slow` | `tools.python.validation.database.schema` |
| `DB_INVENTORY` | `database` | `fast`, `full`, `slow` | `tools.python.validation.database.inventory` |
| `PROJECTION_DEFINITIONS` | `database` | `fast`, `full`, `slow` | `tools.python.validation.database.projections` |
| `PRODUCT_CONTENT_LINKS` | `database` | `fast`, `full`, `slow` | `tools.python.validation.database.product_content_links` |
| `PRICING_OFFERS_BUNDLES` | `database` | `fast`, `full`, `slow` | `tools.python.validation.database.pricing_offers_bundles` |
| `PIM_QUALITY_IMPORT_CHANNELS` | `database` | `fast`, `full`, `slow` | `tools.python.validation.database.pim_quality_import_channels` |
| `BLUEPRINT_SCHEMA` | `content` | `fast`, `full`, `slow` | `tools.python.validation.content.blueprints` |
| `CONTENT_CONTRACTS` | `content` | `fast`, `full`, `slow` | `tools.python.validation.content.contracts` |
| `MULTISITE_LOCALE_MODEL` | `content` | `fast`, `full`, `slow` | `tools.python.validation.content.site_locale` |
| `I18N_COVERAGE` | `content` | `fast`, `full`, `slow` | `tools.python.validation.content.i18n` |
| `PERMISSION_MODEL` | `permissions` | `fast`, `full`, `slow` | `tools.python.validation.permissions.model` |
| `API_SPEC` | `api` | `fast`, `full`, `slow` | `tools.python.validation.api.specification` |
| `SECURITY_BASELINE` | `security` | `fast`, `full`, `slow` | `tools.python.validation.security.baseline` |
| `PII_EMAIL_GUARD` | `security` | `fast`, `full`, `slow` | `tools.python.validation.security.pii` |
| `MEDIA_STORAGE_MODEL` | `operations` | `fast`, `full`, `slow` | `tools.python.validation.operations.media` |
| `OPERATIONS_MANIFESTS` | `operations` | `fast`, `full`, `slow` | `tools.python.validation.operations.manifests` |
| `MODULE_MANIFESTS` | `operations` | `fast`, `full`, `slow` | `tools.python.validation.operations.module_manifests` |
| `MIGRATION_SAFETY` | `operations` | `fast`, `full`, `slow` | `tools.python.validation.operations.migration_safety` |
| `RELEASE_STRUCTURE` | `operations` | `fast`, `full`, `slow` | `tools.python.validation.operations.release_structure` |
| `DOCUMENTATION_CONTRACTS` | `documentation` | `fast`, `full`, `slow` | `tools.python.validation.documentation.contracts` |
| `CAPABILITY_REGISTRY` | `shared` | `fast`, `full`, `slow` | `tools.python.validation.shared.capabilities` |
| `DEPENDENCY_BOUNDARIES` | `shared` | `fast`, `full`, `slow` | `tools.python.validation.shared.dependency_boundaries` |
| `RUNTIME_INTEGRITY` | `qualification` | `full`, `slow` | `tools.python.validation.qualification.runtime_integrity` |
| `STATIC_EXPORT_DRY_RUN` | `qualification` | `slow` | `tools.python.validation.qualification.static_export_dry_run` |
| `BACKUP_RESTORE_ROUNDTRIP` | `qualification` | `slow` | `tools.python.validation.qualification.backup_restore_roundtrip` |
