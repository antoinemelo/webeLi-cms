#!/usr/bin/env python3
"""Inventaire centralisé des bases SQLite natives DEC CMS.

Ce module évite les listes divergentes entre les scripts de création,
packaging, backup, restore et validation. Une base nouvellement native doit
être ajoutée ici, puis reconstruite avec `a_db_init.py`.
"""
from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path


@dataclass(frozen=True)
class DatabaseSpec:
    name: str
    path: str
    required_in_release: bool = True
    required_in_backup: bool = True
    description: str = ""


NATIVE_SQLITE_DATABASES: tuple[DatabaseSpec, ...] = (
    DatabaseSpec("core.sqlite", "storage/database/core.sqlite", description="Contenus, routes, SEO, médias, menus, projections publiques."),
    DatabaseSpec("iam.sqlite", "storage/database/iam.sqlite", description="Identités, rôles, permissions, sessions et audit IAM."),
    DatabaseSpec("forms.sqlite", "storage/database/forms.sqlite", description="Module formulaires, champs, soumissions et notifications."),
    DatabaseSpec("cookies.sqlite", "storage/database/cookies.sqlite", description="Consentement cookies, catégories, services et journaux."),
    DatabaseSpec("ai.sqlite", "storage/database/ai.sqlite", description="Assistant IA natif: fournisseurs, modèles, prompts, tâches, suggestions et usages."),
)


def native_database_names(*, release: bool = False, backup: bool = False) -> tuple[str, ...]:
    specs = NATIVE_SQLITE_DATABASES
    if release:
        specs = tuple(spec for spec in specs if spec.required_in_release)
    if backup:
        specs = tuple(spec for spec in specs if spec.required_in_backup)
    return tuple(spec.name for spec in specs)


def native_database_paths(*, release: bool = False, backup: bool = False) -> tuple[str, ...]:
    specs = NATIVE_SQLITE_DATABASES
    if release:
        specs = tuple(spec for spec in specs if spec.required_in_release)
    if backup:
        specs = tuple(spec for spec in specs if spec.required_in_backup)
    return tuple(spec.path for spec in specs)


def native_database_path(root: Path, name: str) -> Path:
    return root / "storage" / "database" / name
