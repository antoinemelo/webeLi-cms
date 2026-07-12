from __future__ import annotations
from dataclasses import dataclass
from importlib import import_module

@dataclass(frozen=True)
class Validator:
    name: str
    module: str
    domain: str
    modes: tuple[str,...] = ("fast","full","slow")

VALIDATORS=(
    Validator('REPOSITORY_LAYOUT', "tools.python.validation.configuration.repository_layout", 'configuration'),
    Validator('CONFIG_CONSISTENCY', "tools.python.validation.configuration.config_consistency", 'configuration'),
    Validator('DB_SCHEMA', "tools.python.validation.database.schema", 'database'),
    Validator('DB_INVENTORY', "tools.python.validation.database.inventory", 'database'),
    Validator('PROJECTION_DEFINITIONS', "tools.python.validation.database.projections", 'database'),
    Validator('BLUEPRINT_SCHEMA', "tools.python.validation.content.blueprints", 'content'),
    Validator('CONTENT_CONTRACTS', "tools.python.validation.content.contracts", 'content'),
    Validator('MULTISITE_LOCALE_MODEL', "tools.python.validation.content.site_locale", 'content'),
    Validator('PERMISSION_MODEL', "tools.python.validation.permissions.model", 'permissions'),
    Validator('API_SPEC', "tools.python.validation.api.specification", 'api'),
    Validator('SECURITY_BASELINE', "tools.python.validation.security.baseline", 'security'),
    Validator('PII_EMAIL_GUARD', "tools.python.validation.security.pii", 'security'),
    Validator('MEDIA_STORAGE_MODEL', "tools.python.validation.operations.media", 'operations'),
    Validator('OPERATIONS_MANIFESTS', "tools.python.validation.operations.manifests", 'operations'),
    Validator('MODULE_MANIFESTS', "tools.python.validation.operations.module_manifests", 'operations'),
    Validator('MIGRATION_SAFETY', "tools.python.validation.operations.migration_safety", 'operations'),
    Validator('RELEASE_STRUCTURE', "tools.python.validation.operations.release_structure", 'operations'),
    Validator('DOCUMENTATION_CONTRACTS', "tools.python.validation.documentation.contracts", 'documentation'),
    Validator('CAPABILITY_REGISTRY', "tools.python.validation.shared.capabilities", 'shared'),
    Validator('DEPENDENCY_BOUNDARIES', "tools.python.validation.shared.dependency_boundaries", 'shared'),
    Validator('RUNTIME_INTEGRITY', "tools.python.validation.qualification.runtime_integrity", 'qualification', ('full','slow')),
    Validator('STATIC_EXPORT_DRY_RUN', "tools.python.validation.qualification.static_export_dry_run", 'qualification', ('slow',)),
    Validator('BACKUP_RESTORE_ROUNDTRIP', "tools.python.validation.qualification.backup_restore_roundtrip", 'qualification', ('slow',)),
)
BY_NAME={v.name:v for v in VALIDATORS}

def select(*, categories=(), names=(), mode='fast'):
    selected=[]
    for validator in VALIDATORS:
        if names and validator.name not in names: continue
        if categories and validator.domain not in set(categories): continue
        if mode not in validator.modes: continue
        selected.append(validator)
    return selected

def load(v: Validator): return import_module(v.module)
