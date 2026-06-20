#!/usr/bin/env python3
"""Inventaire centralisé des bases SQLite natives AM-CMS / webeLi.

Ce module est la source de vérité simple pour les scripts Python locaux:
initialisation, backup, restore, packaging de release, migrations et
validation. Une base nouvellement native doit être ajoutée ici avant d'être
utilisée par les opérations locales.
"""
from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path


@dataclass(frozen=True)
class DatabaseSpec:
    """Décrit une base SQLite native contrôlée par les outils locaux."""

    key: str
    name: str
    path: str
    kind: str
    schema_path: str | None = None
    migration_scope: str | None = None
    required: bool = True
    required_in_release: bool = True
    required_in_backup: bool = True
    migratable: bool = True
    description: str = ""

    def absolute_path(self, root: Path) -> Path:
        return root / self.path

    def absolute_schema_path(self, root: Path) -> Path | None:
        return root / self.schema_path if self.schema_path else None

    @property
    def filename(self) -> str:
        return self.name


NATIVE_SQLITE_DATABASES: tuple[DatabaseSpec, ...] = (
    DatabaseSpec(
        key="core",
        name="core.sqlite",
        path="storage/database/core.sqlite",
        kind="core",
        schema_path="database/schema/core.sql",
        migration_scope="core",
        description="Contenus, routes, SEO, médias, menus, projections publiques.",
    ),
    DatabaseSpec(
        key="iam",
        name="iam.sqlite",
        path="storage/database/iam.sqlite",
        kind="core",
        schema_path="database/iam.sql",
        migration_scope="iam",
        description="Identités, rôles, permissions, sessions et audit IAM.",
    ),
    DatabaseSpec(
        key="forms",
        name="forms.sqlite",
        path="storage/database/forms.sqlite",
        kind="system_module",
        schema_path="database/modules/forms.sql",
        migration_scope="forms",
        description="Module formulaires, champs, soumissions et notifications.",
    ),
    DatabaseSpec(
        key="cookies",
        name="cookies.sqlite",
        path="storage/database/cookies.sqlite",
        kind="system_module",
        schema_path="database/modules/cookies.sql",
        migration_scope="cookies",
        description="Consentement cookies, catégories, services et journaux.",
    ),
    DatabaseSpec(
        key="ai",
        name="ai.sqlite",
        path="storage/database/ai.sqlite",
        kind="system_module",
        schema_path="database/modules/ai.sql",
        migration_scope="ai",
        description="Assistant IA natif: fournisseurs, modèles, prompts, tâches, suggestions et usages.",
    ),
)


def native_database_specs(
    *,
    release: bool = False,
    backup: bool = False,
    migratable: bool = False,
    required: bool | None = None,
) -> tuple[DatabaseSpec, ...]:
    specs: tuple[DatabaseSpec, ...] = NATIVE_SQLITE_DATABASES
    if release:
        specs = tuple(spec for spec in specs if spec.required_in_release)
    if backup:
        specs = tuple(spec for spec in specs if spec.required_in_backup)
    if migratable:
        specs = tuple(spec for spec in specs if spec.migratable and spec.migration_scope)
    if required is not None:
        specs = tuple(spec for spec in specs if spec.required is required)
    return specs


def native_database_names(*, release: bool = False, backup: bool = False, migratable: bool = False) -> tuple[str, ...]:
    return tuple(spec.name for spec in native_database_specs(release=release, backup=backup, migratable=migratable))


def native_database_keys(*, release: bool = False, backup: bool = False, migratable: bool = False) -> tuple[str, ...]:
    return tuple(spec.key for spec in native_database_specs(release=release, backup=backup, migratable=migratable))


def native_database_paths(*, release: bool = False, backup: bool = False, migratable: bool = False) -> tuple[str, ...]:
    return tuple(spec.path for spec in native_database_specs(release=release, backup=backup, migratable=migratable))


def native_database_path(root: Path, name: str) -> Path:
    spec = database_spec(name)
    return spec.absolute_path(root)


def database_spec(identifier: str) -> DatabaseSpec:
    """Retourne une spécification par clé, nom de fichier ou chemin relatif."""
    normalized = identifier.strip()
    for spec in NATIVE_SQLITE_DATABASES:
        if normalized in {spec.key, spec.name, spec.path, spec.migration_scope}:
            return spec
    raise KeyError(f"Base SQLite native inconnue: {identifier}")


def migration_scopes() -> tuple[str, ...]:
    return tuple(spec.migration_scope or spec.key for spec in native_database_specs(migratable=True))
