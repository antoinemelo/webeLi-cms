#!/usr/bin/env python3
"""Inventaire centralisé des bases SQLite AM-CMS / webeLi.

Ce module reste la source de vérité simple pour les scripts Python locaux.
Il expose l'inventaire natif historique et un inventaire unifié qui ajoute les
bases déclarées par les manifestes de modules sans exécuter de code PHP.
"""
from __future__ import annotations

import json
from dataclasses import dataclass, replace
from pathlib import Path
from typing import Any, Iterable


@dataclass(frozen=True)
class DatabaseSpec:
    """Décrit une base SQLite contrôlée par les outils locaux."""

    key: str
    name: str
    path: str
    kind: str
    schema_path: str | None = None
    migration_scope: str | None = None
    migrations_path: str | None = None
    module_key: str | None = None
    required: bool = True
    required_in_release: bool = True
    required_in_backup: bool = True
    migratable: bool = True
    description: str = ""

    def absolute_path(self, root: Path) -> Path:
        return root / self.path

    def absolute_schema_path(self, root: Path) -> Path | None:
        return root / self.schema_path if self.schema_path else None

    def absolute_migrations_path(self, root: Path) -> Path | None:
        if self.migrations_path:
            return root / self.migrations_path
        if self.migration_scope:
            return root / "database" / "migrations" / self.migration_scope
        return None

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
        module_key="forms",
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
        module_key="ai-assistant",
        description="Assistant IA natif: fournisseurs, modèles, prompts, tâches, suggestions et usages.",
    ),
)


def _project_root() -> Path:
    return next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())


def _normalize_rel(value: str | None) -> str | None:
    if not value:
        return None
    normalized = value.replace("\\", "/").strip().lstrip("/")
    return normalized or None


def _is_under_local_modules(value: str | None) -> bool:
    normalized = _normalize_rel(value)
    return bool(normalized and (normalized == "local/modules" or normalized.startswith("local/modules/")))


def _load_json_file(path: Path) -> dict[str, Any] | None:
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return None
    return data if isinstance(data, dict) else None


def _declared_local_module_manifests(root: Path) -> set[str]:
    manifests: set[str] = set()
    declarations = root / "ops" / "modules.local.json"
    if not declarations.is_file():
        return manifests
    data = _load_json_file(declarations)
    if not data:
        return manifests
    modules = data.get("modules", [])
    if not isinstance(modules, list):
        return manifests
    for entry in modules:
        if not isinstance(entry, dict):
            continue
        manifest = _normalize_rel(str(entry.get("manifest", "")))
        enabled = bool(entry.get("enabled", False))
        if enabled and _is_under_local_modules(manifest):
            manifests.add(str(manifest))
    return manifests


def _system_module_manifest_paths(root: Path) -> list[Path]:
    modules_root = root / "backend" / "src" / "Modules"
    if not modules_root.is_dir():
        return []
    return sorted(modules_root.glob("*/module.json"))


def _local_module_manifest_paths(root: Path) -> list[Path]:
    local_root = root / "local" / "modules"
    paths: set[Path] = set()
    if local_root.is_dir():
        paths.update(path for path in local_root.glob("*/module.json") if path.is_file())
    for rel in _declared_local_module_manifests(root):
        path = root / rel
        if path.is_file():
            paths.add(path)
    return sorted(paths)


def _manifest_paths(root: Path) -> list[Path]:
    return [*_system_module_manifest_paths(root), *_local_module_manifest_paths(root)]


def _is_local_module_enabled(root: Path, manifest_path: Path, manifest: dict[str, Any]) -> bool:
    # Les modules clients doivent être activés explicitement par instance.
    rel = manifest_path.relative_to(root).as_posix()
    if rel in _declared_local_module_manifests(root):
        return True
    return bool(manifest.get("enabled_by_default", False))


def _module_database_specs(root: Path) -> tuple[DatabaseSpec, ...]:
    specs: list[DatabaseSpec] = []
    for manifest_path in _manifest_paths(root):
        manifest = _load_json_file(manifest_path)
        if not manifest:
            continue
        module_key = str(manifest.get("key", "")).strip()
        module_type = str(manifest.get("type", "")).strip()
        if module_type not in {"system", "client"} or not module_key:
            continue
        if module_type == "client" and not _is_under_local_modules(manifest_path.relative_to(root).as_posix()):
            continue
        active = True if module_type == "system" else _is_local_module_enabled(root, manifest_path, manifest)
        databases = manifest.get("databases", [])
        if not isinstance(databases, list):
            continue
        for database in databases:
            if not isinstance(database, dict):
                continue
            key = str(database.get("key", "")).strip()
            path = _normalize_rel(str(database.get("path", "")))
            if not key or not path:
                continue
            if module_type == "client" and not path.startswith("storage/database/"):
                # Les outils locaux ne restaurent que les bases placées dans storage/database.
                continue
            migration_scope = str(database.get("migration_scope") or key).strip()
            migrations_path = _normalize_rel(str(database.get("migrations", "")))
            schema_path = _normalize_rel(str(database.get("schema", "")))
            if module_type == "client" and not _is_under_local_modules(migrations_path):
                migrations_path = None
            spec = DatabaseSpec(
                key=key,
                name=Path(path).name,
                path=path,
                kind="system_module" if module_type == "system" else "client_module",
                schema_path=schema_path,
                migration_scope=migration_scope or key,
                migrations_path=migrations_path,
                module_key=module_key,
                required=active,
                required_in_release=False if module_type == "client" else True,
                required_in_backup=active,
                migratable=active and bool(migrations_path),
                description=str(manifest.get("name", module_key)),
            )
            specs.append(spec)
    return tuple(specs)


def _merge_specs(base: Iterable[DatabaseSpec], extra: Iterable[DatabaseSpec]) -> tuple[DatabaseSpec, ...]:
    by_key: dict[str, DatabaseSpec] = {spec.key: spec for spec in base}
    by_path: dict[str, str] = {spec.path: spec.key for spec in base}
    order = [spec.key for spec in base]
    for spec in extra:
        existing_key = spec.key if spec.key in by_key else by_path.get(spec.path)
        if existing_key:
            current = by_key[existing_key]
            by_key[existing_key] = replace(
                current,
                module_key=current.module_key or spec.module_key,
                schema_path=current.schema_path or spec.schema_path,
                migration_scope=current.migration_scope or spec.migration_scope,
                migrations_path=current.migrations_path or spec.migrations_path,
                description=current.description or spec.description,
            )
            continue
        by_key[spec.key] = spec
        by_path[spec.path] = spec.key
        order.append(spec.key)
    return tuple(by_key[key] for key in order)


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


def database_specs(
    *,
    root: Path | None = None,
    release: bool = False,
    backup: bool = False,
    migratable: bool = False,
    required: bool | None = None,
) -> tuple[DatabaseSpec, ...]:
    """Retourne l'inventaire natif enrichi des bases de modules déclarées.

    Les fonctions historiques ``native_*`` restent limitées aux bases natives.
    Les opérations locales nouvelles doivent utiliser cette fonction lorsqu'elles
    doivent prendre en compte les modules clients activés localement.
    """
    root = root or _project_root()
    specs = _merge_specs(NATIVE_SQLITE_DATABASES, _module_database_specs(root))
    if release:
        specs = tuple(spec for spec in specs if spec.required_in_release)
    if backup:
        specs = tuple(spec for spec in specs if spec.required_in_backup)
    if migratable:
        specs = tuple(spec for spec in specs if spec.migratable and spec.migration_scope)
    if required is not None:
        specs = tuple(spec for spec in specs if spec.required is required)
    return specs


def module_database_specs(root: Path | None = None, *, module_key: str | None = None) -> tuple[DatabaseSpec, ...]:
    root = root or _project_root()
    specs = tuple(spec for spec in database_specs(root=root) if spec.module_key)
    if module_key:
        specs = tuple(spec for spec in specs if spec.module_key == module_key)
    return specs


def native_database_names(*, release: bool = False, backup: bool = False, migratable: bool = False) -> tuple[str, ...]:
    return tuple(spec.name for spec in native_database_specs(release=release, backup=backup, migratable=migratable))


def database_names(*, root: Path | None = None, release: bool = False, backup: bool = False, migratable: bool = False) -> tuple[str, ...]:
    return tuple(spec.name for spec in database_specs(root=root, release=release, backup=backup, migratable=migratable))


def native_database_keys(*, release: bool = False, backup: bool = False, migratable: bool = False) -> tuple[str, ...]:
    return tuple(spec.key for spec in native_database_specs(release=release, backup=backup, migratable=migratable))


def database_keys(*, root: Path | None = None, release: bool = False, backup: bool = False, migratable: bool = False) -> tuple[str, ...]:
    return tuple(spec.key for spec in database_specs(root=root, release=release, backup=backup, migratable=migratable))


def native_database_paths(*, release: bool = False, backup: bool = False, migratable: bool = False) -> tuple[str, ...]:
    return tuple(spec.path for spec in native_database_specs(release=release, backup=backup, migratable=migratable))


def database_paths(*, root: Path | None = None, release: bool = False, backup: bool = False, migratable: bool = False) -> tuple[str, ...]:
    return tuple(spec.path for spec in database_specs(root=root, release=release, backup=backup, migratable=migratable))


def native_database_path(root: Path, name: str) -> Path:
    spec = database_spec(name, root=root, native_only=True)
    return spec.absolute_path(root)


def database_spec(identifier: str, *, root: Path | None = None, native_only: bool = False) -> DatabaseSpec:
    """Retourne une spécification par clé, nom de fichier, chemin relatif ou scope."""
    normalized = identifier.strip()
    specs = native_database_specs() if native_only else database_specs(root=root)
    for spec in specs:
        if normalized in {spec.key, spec.name, spec.path, spec.migration_scope or ""}:
            return spec
    kind = "native" if native_only else "connue"
    raise KeyError(f"Base SQLite {kind} inconnue: {identifier}")


def migration_scopes(root: Path | None = None) -> tuple[str, ...]:
    return tuple(spec.migration_scope or spec.key for spec in database_specs(root=root, migratable=True))


def module_keys(root: Path | None = None) -> tuple[str, ...]:
    keys = sorted({spec.module_key for spec in database_specs(root=root) if spec.module_key})
    return tuple(key for key in keys if key)
