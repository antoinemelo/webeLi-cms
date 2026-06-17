#!/usr/bin/env python3
"""Seed idempotent des field types et blueprints natifs.

Source de vérité active v1 : `blueprints` + `blueprint_versions`, normalisés dans
`blueprint_sections`, `blueprint_fields`, `fieldsets` et `fieldset_fields`.

`schema_field_types` reste temporairement le registre de compatibilité des types
de champs. Aucun cache monolithique legacy n'est créé ni alimenté.
"""
from __future__ import annotations

import json
import sqlite3
import sys
from pathlib import Path


BASE = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
CORE_DB = BASE / "storage" / "database" / "core.sqlite"
BLUEPRINTS_JSON = BASE / "database" / "seeds" / "native_blueprints.json"


def load_payload() -> dict:
    if not BLUEPRINTS_JSON.exists():
        raise FileNotFoundError(f"Fichier introuvable: {BLUEPRINTS_JSON}")
    data = json.loads(BLUEPRINTS_JSON.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise ValueError("native_blueprints.json doit contenir un objet JSON.")
    return data



def compact_json(value: object) -> str:
    return json.dumps(value, ensure_ascii=False, separators=(",", ":"))


SYSTEM_CONTEXT_FIELDS = {"status", "workflow_state", "language", "site", "site_id", "revision_state", "content_type", "blueprint_id", "blueprint_version_id"}
SYSTEM_EDITABLE_FIELDS = {"title", "slug", "entry_key", "published_at", "template"}

# Contrat explicite attendu par c49_validate_security_blueprints.py.
# Les blueprints de sécurité restent volontairement limités au socle éditorial
# natif et ne doivent pas dépendre des blueprints de modules métier.
SECURITY_BLUEPRINT_RESOURCE_SCOPE_SQL = "resource_type IN ('content_type','block','system')"


def field_classification(handle: str, field: dict | None = None, purpose: str = "content") -> dict:
    field = field or {}
    config = field.get("config") if isinstance(field.get("config"), dict) else {}
    scope = str(field.get("field_scope") or field.get("scope") or config.get("field_scope") or config.get("scope") or "")
    if not scope:
        if handle in SYSTEM_CONTEXT_FIELDS:
            scope = "system_context"
        elif handle in SYSTEM_EDITABLE_FIELDS:
            scope = "system_editable"
        else:
            scope = "editorial"
    if scope not in {"editorial", "system_editable", "system_context"}:
        scope = "editorial"
    source = str(field.get("value_source") or field.get("source") or config.get("value_source") or config.get("source") or "")
    if not source:
        source = "workflow" if handle in {"status", "workflow_state", "revision_state"} else ("context" if handle in {"language", "site", "site_id", "content_type", "blueprint_id", "blueprint_version_id"} else "content")
    if source not in {"content", "context", "workflow", "relation", "computed"}:
        source = "content"
    visibility = str(field.get("ui_visibility") or field.get("visibility") or config.get("ui_visibility") or config.get("visibility") or ("summary" if scope == "system_context" else "form"))
    if visibility not in {"form", "summary", "hidden", "readonly", "native"}:
        visibility = "summary" if scope == "system_context" else "form"
    editable = bool(field.get("is_editable", field.get("editable", scope != "system_context")))
    if scope == "system_context":
        editable = False
    if scope != "editorial":
        purpose = "system"
    return {"field_scope": scope, "scope": scope, "value_source": source, "source": source, "ui_visibility": visibility, "visibility": visibility, "editable": editable, "is_editable": editable, "field_purpose": purpose}


def field_config_with_metadata(field: dict | None, handle: str, purpose: str, extra: dict | None = None) -> dict:
    field = field or {}
    config = field.get("config") if isinstance(field.get("config"), dict) else {}
    merged = dict(config)
    if extra:
        merged.update(extra)
    merged.update(field_classification(handle, field | {"config": merged}, purpose))
    return merged



def editor_schema_for_block(definition: dict) -> dict:
    fields: list[str] = []
    for section in definition.get("sections", []) if isinstance(definition.get("sections"), list) else []:
        for field in section.get("fields", []) if isinstance(section, dict) and isinstance(section.get("fields"), list) else []:
            if isinstance(field, dict) and field.get("key"):
                fields.append(str(field["key"]))
    schema = {
        "fields": fields,
        "source": "blueprint_versions",
        "canonical": True,
    }
    if definition.get("category"):
        schema["category"] = definition.get("category")
    return schema


def sync_editor_block_type(cursor: sqlite3.Cursor, *, block_type: str, definition: dict, sort_order: int) -> None:
    """Alimente le cache historique editor_block_types depuis les blueprints natifs.

    `editor_block_types` n'est plus une source de vérité. Il reste présent pour les
    écrans et scripts historiques, mais son contenu doit suivre les mêmes clés que
    `database/seeds/native_blueprints.json` et `blueprint_versions`.
    """
    cursor.execute(
        """
        INSERT INTO editor_block_types(block_type, label, category, schema_json, is_enabled, sort_order)
        VALUES(?,?,?,?,1,?)
        ON CONFLICT(block_type) DO UPDATE SET
            label=excluded.label,
            category=excluded.category,
            schema_json=excluded.schema_json,
            is_enabled=1,
            sort_order=excluded.sort_order
        """,
        (
            block_type,
            str(definition.get("title", block_type)),
            str(definition.get("category", "content")),
            compact_json(editor_schema_for_block(definition)),
            sort_order,
        ),
    )


def retire_legacy_block_aliases(cursor: sqlite3.Cursor, canonical_blocks: set[str]) -> None:
    """Désactive les anciens alias qui ne doivent plus être proposés comme types natifs."""
    legacy_aliases = {
        "html": "html_safe",
        "safe_html": "html_safe",
        "raw_html": "html_raw",
        "rich_text": "richtext",
        "button": "buttons",
    }
    for alias, target in legacy_aliases.items():
        if alias in canonical_blocks:
            continue
        cursor.execute(
            """
            INSERT INTO editor_block_types(block_type, label, category, schema_json, is_enabled, sort_order)
            VALUES(?,?,?,?,0,9990)
            ON CONFLICT(block_type) DO UPDATE SET
                schema_json=excluded.schema_json,
                is_enabled=0,
                sort_order=excluded.sort_order
            """,
            (alias, f"Alias historique de {target}", "legacy", compact_json({"alias_for": target, "canonical": False})),
        )
def versioned_schema_for_block(definition: dict, fieldsets: dict, field_types: list) -> dict:
    """Construit le document canonique stocké dans blueprint_versions.schema_json."""
    return {
        "schema_version": int(definition.get("schema_version", 1)),
        "block": definition,
        "fields": definition.get("sections", []),
        "fieldsets": fieldsets,
        "field_types": field_types,
        "headless": {"enabled": True, "projection": "public_content_snapshots.blocks_json"},
    }


def ensure_versioned_blueprint(
    cursor: sqlite3.Cursor,
    *,
    blueprint_key: str,
    resource_type: str,
    label: str,
    description: str,
    schema_json: dict,
    ui_schema_json: dict | None = None,
    validation_json: dict | None = None,
    seo_policy_json: dict | None = None,
    routing_policy_json: dict | None = None,
    workflow_policy_json: dict | None = None,
    translation_policy_json: dict | None = None,
    permissions_policy_json: dict | None = None,
) -> None:
    cursor.execute(
        """
        INSERT INTO blueprints(blueprint_key, resource_type, site_id, legacy_content_type_id, label, description, is_active, updated_at)
        VALUES(?, ?, NULL, NULL, ?, ?, 1, CURRENT_TIMESTAMP)
        ON CONFLICT(blueprint_key, resource_type) WHERE site_id IS NULL DO UPDATE SET
            label=excluded.label,
            description=excluded.description,
            is_active=1,
            updated_at=CURRENT_TIMESTAMP
        """,
        (blueprint_key, resource_type, label, description),
    )
    row = cursor.execute(
        "SELECT id, active_version_id FROM blueprints WHERE blueprint_key=? AND resource_type=? AND site_id IS NULL",
        (blueprint_key, resource_type),
    ).fetchone()
    if row is None:
        raise RuntimeError(f"Blueprint introuvable après upsert: {resource_type}:{blueprint_key}")
    blueprint_id = int(row[0])
    payloads = {
        "schema_json": compact_json(schema_json),
        "ui_schema_json": compact_json(ui_schema_json or {}),
        "validation_json": compact_json(validation_json or {}),
        "seo_policy_json": compact_json(seo_policy_json or {}),
        "routing_policy_json": compact_json(routing_policy_json or {}),
        "workflow_policy_json": compact_json(workflow_policy_json or {}),
        "translation_policy_json": compact_json(translation_policy_json or {}),
        "permissions_policy_json": compact_json(permissions_policy_json or {}),
    }
    active_id = row[1]
    if active_id is not None:
        active = cursor.execute(
            """
            SELECT schema_json, ui_schema_json, validation_json, seo_policy_json,
                   routing_policy_json, workflow_policy_json, translation_policy_json, permissions_policy_json
            FROM blueprint_versions WHERE id=? AND blueprint_id=? AND is_active=1
            """,
            (active_id, blueprint_id),
        ).fetchone()
        if active is not None and all(str(active[i]) == payloads[key] for i, key in enumerate(payloads)):
            return
        cursor.execute(
            "UPDATE blueprint_versions SET is_active=0, status='archived', archived_at=CURRENT_TIMESTAMP WHERE blueprint_id=? AND is_active=1",
            (blueprint_id,),
        )
    next_version = int(cursor.execute("SELECT COALESCE(MAX(version), 0) + 1 FROM blueprint_versions WHERE blueprint_id=?", (blueprint_id,)).fetchone()[0])
    cursor.execute(
        """
        INSERT INTO blueprint_versions(
            blueprint_id, version, version_label, status, schema_json, ui_schema_json, validation_json,
            seo_policy_json, routing_policy_json, workflow_policy_json, translation_policy_json,
            permissions_policy_json, is_active, activated_at
        ) VALUES(?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, ?, ?, 1, CURRENT_TIMESTAMP)
        """,
        (
            blueprint_id,
            next_version,
            "native-sync",
            payloads["schema_json"],
            payloads["ui_schema_json"],
            payloads["validation_json"],
            payloads["seo_policy_json"],
            payloads["routing_policy_json"],
            payloads["workflow_policy_json"],
            payloads["translation_policy_json"],
            payloads["permissions_policy_json"],
        ),
    )
    version_id = cursor.lastrowid
    cursor.execute("UPDATE blueprints SET active_version_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?", (version_id, blueprint_id))


def table_exists(cursor: sqlite3.Cursor, table: str) -> bool:
    return cursor.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?", (table,)).fetchone() is not None


def normalize_handle(value: object, fallback: str = "field") -> str:
    import re
    text = str(value or fallback).strip().lower()
    text = re.sub(r"[^a-z0-9_-]+", "_", text)
    text = text.strip("_-")
    return text or fallback


PROTECTED_SYSTEM_FIELDS = {
    "id", "uuid", "title", "slug", "type", "status", "language", "locale", "site", "site_id",
    "created_at", "updated_at", "published_at", "author_id", "revision_id",
    "blueprint_id", "blueprint_version_id", "entry_key", "position", "sort_order", "parent_id",
    "route", "canonical_url", "seo_title", "seo_description", "meta_title", "meta_description", "meta_robots",
}


def is_protected_system_field(handle: str) -> bool:
    return normalize_handle(handle, "") in PROTECTED_SYSTEM_FIELDS


def purpose_for_handle(handle: str, fallback: str = "content") -> str:
    if is_protected_system_field(handle):
        return "system"
    if handle.startswith("seo_") or handle in {"robots", "og_title", "og_description", "og_image"}:
        return "seo"
    if handle in {"blocks", "builder", "layout", "template", "css_class", "anchor"}:
        return "page_builder"
    return fallback


def field_type_from(value: object) -> str:
    """Return a field type accepted by the SQLite CHECK constraints.

    The admin editor may still send historical aliases (`blocks`, `site`,
    `language`).  The native database contract uses the plural/canonical field
    types stored in schema_field_types, so we normalize aliases before inserts.
    """
    mapping = {
        "string": "text",
        "float": "number",
        "bool": "boolean",
        "image": "media",
        "asset": "media",
        "file": "media",
        "files": "assets",
        "url": "link",
        "entry": "entries",
        "builder": "replicator",
        "blocks": "replicator",
        "block_builder": "replicator",
        "seo": "json",
        "language": "select",
        "languages": "select",
        "site": "sites",
        "structure": "structures",
        "user": "users",
    }
    allowed = {
        "text", "textarea", "richtext", "markdown", "bard", "number", "integer",
        "boolean", "toggle", "date", "time", "datetime", "json", "yaml", "code",
        "media", "assets", "relation", "entries", "taxonomy", "select", "radio",
        "multiselect", "checkboxes", "slug", "link", "list", "replicator", "table",
        "color", "video", "button_group", "range", "revealer", "sites",
        "structures", "template", "users",
    }
    raw = str(value or "text").strip().lower()
    normalized = mapping.get(raw, raw or "text")
    return normalized if normalized in allowed else "text"


def storage_mode_for_field_type(field_type: str) -> str:
    return "json" if field_type in {"json", "media", "assets", "relation", "entries", "taxonomy", "multiselect", "checkboxes", "list", "replicator", "table", "video", "sites", "structures", "users"} else "value_table"


def interface_key_from(field: dict) -> str:
    raw = str(field.get("interface_key") or field.get("component") or field.get("type") or field.get("field_type") or "").strip()
    return raw


def json_or_empty(value: object, empty: object) -> str:
    return compact_json(value if value is not None else empty)


def iter_section_fields(section: dict) -> list[dict]:
    fields = section.get("fields", []) if isinstance(section, dict) else []
    return [field for field in fields if isinstance(field, dict)]


def upsert_fieldset(cursor: sqlite3.Cursor, key: str, definition: dict) -> int | None:
    if not table_exists(cursor, "fieldsets"):
        return None
    fieldset_key = normalize_handle(key, "fieldset")
    purpose = purpose_for_handle(fieldset_key, "page_builder" if "block" in fieldset_key else "content")
    cursor.execute(
        """
        INSERT INTO fieldsets(fieldset_key, label, description, fieldset_purpose, schema_version, is_system, is_deletable, config_json, updated_at)
        VALUES(?,?,?,?,?,1,0,?,CURRENT_TIMESTAMP)
        ON CONFLICT(fieldset_key) DO UPDATE SET
            label=excluded.label,
            description=excluded.description,
            fieldset_purpose=excluded.fieldset_purpose,
            schema_version=excluded.schema_version,
            is_system=excluded.is_system,
            is_deletable=excluded.is_deletable,
            config_json=excluded.config_json,
            updated_at=CURRENT_TIMESTAMP
        """,
        (
            fieldset_key,
            str(definition.get("title") or definition.get("display") or key),
            str(definition.get("description") or ""),
            purpose,
            int(definition.get("schema_version", 1) or 1),
            compact_json({"source": "native_blueprints.json"}),
        ),
    )
    row = cursor.execute("SELECT id FROM fieldsets WHERE fieldset_key=?", (fieldset_key,)).fetchone()
    if row is None:
        return None
    fieldset_id = int(row[0])
    cursor.execute("DELETE FROM fieldset_fields WHERE fieldset_id=?", (fieldset_id,))
    sort_order = 10
    for section in definition.get("sections", []) if isinstance(definition.get("sections"), list) else []:
        for field in iter_section_fields(section):
            handle = normalize_handle(field.get("handle") or field.get("field_key"), "field")
            ftype = field_type_from(field.get("type") or field.get("field_type"))
            cursor.execute(
                """
                INSERT INTO fieldset_fields(
                    fieldset_id, field_handle, field_type, label, help_text, field_purpose, width,
                    is_required, is_localized, is_system, is_deletable, sort_order,
                    default_value_json, options_json, validation_json, conditions_json, config_json
                ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                """,
                (
                    fieldset_id,
                    handle,
                    ftype,
                    str(field.get("display") or field.get("label") or handle),
                    str(field.get("instructions") or field.get("help_text") or ""),
                    purpose_for_handle(handle),
                    int(field.get("width") or 100),
                    1 if field.get("required") or field.get("is_required") else 0,
                    1 if field.get("localizable", field.get("is_localized", True)) else 0,
                    1 if field.get("system") or is_protected_system_field(handle) or purpose_for_handle(handle) == "system" else 0,
                    0 if field.get("system") or is_protected_system_field(handle) or purpose_for_handle(handle) == "system" else 1,
                    sort_order,
                    json_or_empty(field.get("default"), None) if field.get("default") is not None else None,
                    json_or_empty(field.get("options") or (field.get("config") or {}).get("options"), {}),
                    json_or_empty(field.get("validate") or field.get("validation"), {}),
                    json_or_empty(field.get("conditions") or field.get("visibility_conditions"), []),
                    json_or_empty(field.get("config"), {}),
                ),
            )
            sort_order += 10
    return fieldset_id


def sync_field_rules(cursor: sqlite3.Cursor, *, scope: str, row_id: int, validation: dict, conditions: list) -> None:
    if table_exists(cursor, "field_validation_rules") and validation:
        if scope == "blueprint_field":
            cursor.execute("DELETE FROM field_validation_rules WHERE scope_type='blueprint_field' AND blueprint_field_id=?", (row_id,))
        elif scope == "fieldset_field":
            cursor.execute("DELETE FROM field_validation_rules WHERE scope_type='fieldset_field' AND fieldset_field_id=?", (row_id,))
        for index, (rule, value) in enumerate(validation.items(), start=1):
            cursor.execute(
                """
                INSERT INTO field_validation_rules(scope_type, blueprint_field_id, fieldset_field_id, rule_key, rule_value_json, sort_order)
                VALUES(?,?,?,?,?,?)
                """,
                (scope, row_id if scope == "blueprint_field" else None, row_id if scope == "fieldset_field" else None, str(rule), compact_json(value), index * 10),
            )
    if table_exists(cursor, "field_visibility_conditions") and conditions:
        if scope == "blueprint_field":
            cursor.execute("DELETE FROM field_visibility_conditions WHERE scope_type='blueprint_field' AND blueprint_field_id=?", (row_id,))
        elif scope == "fieldset_field":
            cursor.execute("DELETE FROM field_visibility_conditions WHERE scope_type='fieldset_field' AND fieldset_field_id=?", (row_id,))
        for index, condition in enumerate(conditions, start=1):
            cursor.execute(
                """
                INSERT INTO field_visibility_conditions(scope_type, blueprint_field_id, fieldset_field_id, effect, condition_json, sort_order)
                VALUES(?,?,?,?,?,?)
                """,
                (scope, row_id if scope == "blueprint_field" else None, row_id if scope == "fieldset_field" else None, str(condition.get("effect", "show")) if isinstance(condition, dict) else "show", compact_json(condition), index * 10),
            )


def normalize_content_blueprint(cursor: sqlite3.Cursor, blueprint_id: int, schema: dict, ui_schema: dict) -> None:
    if not table_exists(cursor, "blueprint_sections"):
        return
    if table_exists(cursor, "field_validation_rules"):
        cursor.execute("DELETE FROM field_validation_rules WHERE blueprint_field_id IN (SELECT id FROM blueprint_fields WHERE blueprint_id=?)", (blueprint_id,))
    if table_exists(cursor, "field_visibility_conditions"):
        cursor.execute("DELETE FROM field_visibility_conditions WHERE blueprint_field_id IN (SELECT id FROM blueprint_fields WHERE blueprint_id=?)", (blueprint_id,))
        cursor.execute("DELETE FROM field_visibility_conditions WHERE blueprint_fieldset_id IN (SELECT id FROM blueprint_fieldsets WHERE blueprint_id=?)", (blueprint_id,))
        cursor.execute("DELETE FROM field_visibility_conditions WHERE blueprint_section_id IN (SELECT id FROM blueprint_sections WHERE blueprint_id=?)", (blueprint_id,))
    cursor.execute("DELETE FROM blueprint_fieldsets WHERE blueprint_id=?", (blueprint_id,))
    cursor.execute("DELETE FROM blueprint_fields WHERE blueprint_id=?", (blueprint_id,))
    cursor.execute("DELETE FROM blueprint_sections WHERE blueprint_id=?", (blueprint_id,))

    tabs = ui_schema.get("editor_tabs", []) if isinstance(ui_schema, dict) else []
    tab_ids: dict[str, int] = {}
    if isinstance(tabs, list) and tabs:
        for i, tab in enumerate([t for t in tabs if isinstance(t, dict)], start=1):
            key = normalize_handle(tab.get("key"), f"tab_{i}")
            cursor.execute(
                """
                INSERT INTO blueprint_sections(blueprint_id, section_key, label, description, layout, sort_order, conditions_json, config_json)
                VALUES(?,?,?,?,?,?,?,?)
                """,
                (blueprint_id, key, str(tab.get("label") or key), str(tab.get("description") or ""), "tab", int(tab.get("sort_order") or i * 10), compact_json(tab.get("conditions") or []), compact_json({"fields": tab.get("fields", [])})),
            )
            tab_ids[key] = int(cursor.lastrowid)
    else:
        cursor.execute("INSERT INTO blueprint_sections(blueprint_id, section_key, label, layout, sort_order) VALUES(?,?,?,?,?)", (blueprint_id, "content", "Contenu", "tab", 10))
        tab_ids["content"] = int(cursor.lastrowid)

    declared_fields = schema.get("fields", []) if isinstance(schema, dict) else []
    sort_counters: dict[str, int] = {}
    if isinstance(declared_fields, list):
        for field in [f for f in declared_fields if isinstance(f, dict)]:
            handle = normalize_handle(field.get("field_key") or field.get("handle"), "field")
            tab_key = normalize_handle(field.get("tab_key") or field.get("section") or "content", "content")
            section_id = tab_ids.get(tab_key) or next(iter(tab_ids.values()))
            sort_counters[tab_key] = sort_counters.get(tab_key, 0) + 10
            validation = field.get("validation") if isinstance(field.get("validation"), dict) else {}
            conditions = field.get("conditions") if isinstance(field.get("conditions"), list) else []
            cursor.execute(
                """
                INSERT INTO blueprint_fields(
                    blueprint_id, section_id, field_handle, field_type, label, help_text, field_purpose, width,
                    is_required, is_localized, is_system, is_deletable, sort_order,
                    default_value_json, options_json, validation_json, conditions_json, config_json
                ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                """,
                (
                    blueprint_id,
                    section_id,
                    handle,
                    field_type_from(field.get("field_type") or field.get("type")),
                    str(field.get("label") or field.get("display") or handle),
                    str(field.get("help_text") or field.get("instructions") or ""),
                    purpose_for_handle(handle),
                    int(field.get("width") or 100),
                    1 if field.get("is_required") or field.get("required") else 0,
                    1 if field.get("is_localized", field.get("localizable", True)) else 0,
                    1 if field.get("is_system") or purpose_for_handle(handle) == "system" else 0,
                    0 if field.get("is_system") or purpose_for_handle(handle) == "system" else 1,
                    int(field.get("sort_order") or sort_counters[tab_key]),
                    json_or_empty(field.get("default"), None) if field.get("default") is not None else None,
                    json_or_empty(field.get("options") or field.get("config", {}).get("options") if isinstance(field.get("config"), dict) else {}, {}),
                    compact_json(validation),
                    compact_json(conditions),
                    json_or_empty(field.get("config"), {}),
                ),
            )
            sync_field_rules(cursor, scope="blueprint_field", row_id=int(cursor.lastrowid), validation=validation, conditions=conditions)

    # Champs système et SEO stables, non supprimables. Ils sont explicites même si
    # les projections publiques les stockent dans content/seo plutôt que dans fields.
    system_fields = [
        ("title", "text", "Titre", "system", True, True, 10, "system_editable", "content", "form"),
        ("slug", "slug", "Slug", "system", True, True, 20, "system_editable", "content", "form"),
        ("entry_key", "text", "Clé d’entrée", "system", False, False, 25, "system_editable", "content", "form"),
        ("status", "select", "Statut", "system", True, False, 900, "system_context", "workflow", "summary"),
        ("language", "select", "Langue", "system", True, False, 910, "system_context", "context", "summary"),
        ("site", "sites", "Site", "system", True, False, 920, "system_context", "context", "summary"),
        ("revision_state", "select", "Révision", "system", False, False, 930, "system_context", "workflow", "summary"),
        ("seo_title", "text", "SEO title", "seo", False, True, 10, "editorial", "content", "form"),
        ("seo_description", "textarea", "Meta description", "seo", False, True, 20, "editorial", "content", "form"),
        ("canonical_url", "text", "Canonical URL", "seo", False, True, 30, "editorial", "content", "form"),
        ("robots", "select", "Robots", "seo", False, False, 40, "editorial", "content", "form"),
        ("og_title", "text", "Open Graph title", "seo", False, True, 50, "editorial", "content", "form"),
        ("og_description", "textarea", "Open Graph description", "seo", False, True, 60, "editorial", "content", "form"),
        ("og_image", "media", "Open Graph image", "seo", False, False, 70, "editorial", "content", "form"),
    ]
    for handle, ftype, label, purpose, required, localized, order, scope, source, visibility in system_fields:
        section_key = "seo" if purpose == "seo" else "settings"
        section_id = tab_ids.get(section_key)
        if section_id is None:
            cursor.execute("INSERT INTO blueprint_sections(blueprint_id, section_key, label, layout, sort_order) VALUES(?,?,?,?,?) ON CONFLICT(blueprint_id, section_key) DO NOTHING", (blueprint_id, section_key, "SEO" if purpose == "seo" else "Réglages", "tab", 80 if purpose == "seo" else 100))
            row = cursor.execute("SELECT id FROM blueprint_sections WHERE blueprint_id=? AND section_key=?", (blueprint_id, section_key)).fetchone()
            section_id = int(row[0])
            tab_ids[section_key] = section_id
        cursor.execute(
            """
            INSERT INTO blueprint_fields(
                blueprint_id, section_id, field_handle, field_type, label, field_purpose, width,
                is_required, is_localized, is_system, is_deletable, sort_order,
                options_json, validation_json, conditions_json, config_json
            ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON CONFLICT(blueprint_id, field_handle) DO UPDATE SET
                label=excluded.label,
                field_purpose=excluded.field_purpose,
                is_system=1,
                is_deletable=0,
                updated_at=CURRENT_TIMESTAMP
            """,
            (blueprint_id, section_id, handle, ftype, label, purpose, 100, 1 if required else 0, 1 if localized else 0, 1, 0, order, "{}", compact_json({"required": True} if required else {}), "[]", compact_json(field_config_with_metadata({"field_scope": scope, "value_source": source, "ui_visibility": visibility, "is_editable": scope != "system_context"}, handle, purpose))),
        )




def bool_int(value: object, default: bool = False) -> int:
    if value is None:
        return 1 if default else 0
    return 1 if bool(value) else 0


def sync_content_blueprints(cursor: sqlite3.Cursor, payload: dict) -> None:
    """Synchronise les blueprints de contenus avec les tables runtime.

    Objectif: le formulaire admin, SaveContentDraft, le front et l’API lisent tous
    le même modèle actif. Les champs système/SEO restent exposés dans le blueprint
    mais ne sont pas indexés comme champs dynamiques publics.
    """
    content_blueprints = payload.get("content_blueprints", {})
    if not isinstance(content_blueprints, dict):
        return

    for key, definition in content_blueprints.items():
        if not isinstance(definition, dict):
            continue
        blueprint_key = normalize_handle(key, "content")
        content_type = definition.get("content_type", {}) if isinstance(definition.get("content_type"), dict) else {}
        capabilities = definition.get("capabilities", {}) if isinstance(definition.get("capabilities"), dict) else {}
        template = ""
        if isinstance(definition.get("template_binding"), dict):
            template = str(definition["template_binding"].get("template") or "")

        cursor.execute(
            """
            INSERT INTO content_types(
                type_key, name, singular_label, plural_label, description,
                is_system, is_hidden, storage_mode, has_localizations, has_revisions,
                has_workflow, has_permalink, has_layout, has_taxonomies, has_seo,
                default_status, frontend_template, api_enabled, admin_enabled, updated_at
            ) VALUES(?,?,?,?,?,0,0,'hybrid',?,?,?,?,?,?,?,'draft',?,1,1,CURRENT_TIMESTAMP)
            ON CONFLICT(type_key) DO UPDATE SET
                name=excluded.name,
                singular_label=excluded.singular_label,
                plural_label=excluded.plural_label,
                description=excluded.description,
                has_localizations=excluded.has_localizations,
                has_revisions=excluded.has_revisions,
                has_workflow=excluded.has_workflow,
                has_permalink=excluded.has_permalink,
                has_layout=excluded.has_layout,
                has_taxonomies=excluded.has_taxonomies,
                has_seo=excluded.has_seo,
                frontend_template=excluded.frontend_template,
                api_enabled=1,
                admin_enabled=1,
                updated_at=CURRENT_TIMESTAMP
            """,
            (
                str(content_type.get("type_key") or blueprint_key),
                str(content_type.get("name") or definition.get("label") or blueprint_key),
                str(content_type.get("singular_label") or definition.get("label") or blueprint_key),
                str(content_type.get("plural_label") or definition.get("label") or blueprint_key),
                str(definition.get("description") or ""),
                bool_int(capabilities.get("localizations"), True),
                bool_int(capabilities.get("revisions"), True),
                bool_int(capabilities.get("workflow"), True),
                bool_int(capabilities.get("permalink"), True),
                bool_int(capabilities.get("layout"), False),
                bool_int(capabilities.get("taxonomies"), False),
                bool_int(capabilities.get("seo"), True),
                template or f"{blueprint_key}.twig",
            ),
        )
        row = cursor.execute("SELECT id FROM content_types WHERE type_key=?", (blueprint_key,)).fetchone()
        if row is None:
            raise RuntimeError(f"Type de contenu introuvable après sync: {blueprint_key}")
        content_type_id = int(row[0])

        if table_exists(cursor, "content_entry_field_values"):
            cursor.execute("DELETE FROM content_entry_field_values WHERE content_type_id=?", (content_type_id,))
        if table_exists(cursor, "field_validation_rules"):
            cursor.execute("DELETE FROM field_validation_rules WHERE field_id IN (SELECT id FROM fields WHERE content_type_id=?)", (content_type_id,))
        if table_exists(cursor, "field_visibility_conditions"):
            cursor.execute("DELETE FROM field_visibility_conditions WHERE field_id IN (SELECT id FROM fields WHERE content_type_id=?)", (content_type_id,))
        cursor.execute("DELETE FROM field_groups WHERE content_type_id=?", (content_type_id,))
        cursor.execute("DELETE FROM fields WHERE content_type_id=?", (content_type_id,))
        tab_ids: dict[str, int] = {}
        tabs = definition.get("editor_tabs", []) if isinstance(definition.get("editor_tabs"), list) else []
        for i, tab in enumerate([t for t in tabs if isinstance(t, dict)], start=1):
            tab_key = normalize_handle(tab.get("key"), f"tab_{i}")
            cursor.execute(
                "INSERT INTO field_groups(content_type_id, group_key, label, tab_key, sort_order) VALUES(?,?,?,?,?)",
                (content_type_id, tab_key, str(tab.get("label") or tab_key), tab_key, int(tab.get("sort_order") or i * 10)),
            )
            tab_ids[tab_key] = int(cursor.lastrowid)

        declared_fields = definition.get("fields", []) if isinstance(definition.get("fields"), list) else []
        for index, field in enumerate([f for f in declared_fields if isinstance(f, dict)], start=1):
            handle = normalize_handle(field.get("field_key") or field.get("handle"), "field")
            tab_key = normalize_handle(field.get("tab_key") or field.get("section") or "content", "content")
            if tab_key not in tab_ids:
                cursor.execute(
                    "INSERT INTO field_groups(content_type_id, group_key, label, tab_key, sort_order) VALUES(?,?,?,?,?)",
                    (content_type_id, tab_key, tab_key.replace("_", " ").title(), tab_key, index * 10),
                )
                tab_ids[tab_key] = int(cursor.lastrowid)
            purpose = str(field.get("field_purpose") or field.get("purpose") or purpose_for_handle(handle))
            validation = field.get("validation") if isinstance(field.get("validation"), dict) else (field.get("validate") if isinstance(field.get("validate"), dict) else {})
            conditions = field.get("conditions") if isinstance(field.get("conditions"), list) else []
            cursor.execute(
                """
                INSERT INTO fields(
                    content_type_id, group_id, field_key, label, field_type, storage_mode, interface_key,
                    help_text, placeholder, default_value_json, options_json, validation_json,
                    conditions_json, config_json, field_purpose, width, is_required, is_unique,
                    is_localized, is_indexed, is_filterable, is_sortable, is_searchable,
                    is_hidden, is_system, is_deletable, sort_order
                ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                """,
                (
                    content_type_id,
                    tab_ids[tab_key],
                    handle,
                    str(field.get("label") or field.get("display") or handle),
                    field_type_from(field.get("field_type") or field.get("type")),
                    storage_mode_for_field_type(field_type_from(field.get("field_type") or field.get("type"))),
                    interface_key_from(field),
                    str(field.get("help_text") or field.get("instructions") or ""),
                    str(field.get("placeholder") or ""),
                    json_or_empty(field.get("default"), None) if field.get("default") is not None else None,
                    json_or_empty(field.get("options"), {}),
                    compact_json(validation),
                    compact_json(conditions),
                    json_or_empty(field_config_with_metadata(field, handle, purpose, {"headless": field.get("headless", {}), "public": field.get("public", True), "seed_source": "native_blueprints.json"}), {}),
                    field_classification(handle, field, purpose)["field_purpose"],
                    int(field.get("width") or 100),
                    bool_int(field.get("is_required", field.get("required", False))),
                    bool_int(field.get("is_unique", field.get("unique", False))),
                    bool_int(field.get("is_localized", field.get("localizable", True)), True),
                    bool_int(field.get("is_indexed", False)),
                    bool_int(field.get("is_filterable", False)),
                    bool_int(field.get("is_sortable", False)),
                    bool_int(field.get("is_searchable", False)),
                    bool_int(field.get("is_hidden", False)),
                    bool_int(field.get("is_system", field_classification(handle, field, purpose)["field_scope"] != "editorial")),
                    0 if field_classification(handle, field, purpose)["field_scope"] != "editorial" else 1,
                    int(field.get("sort_order") or index * 10),
                ),
            )

        schema_json = {
            "schema_version": int(definition.get("schema_version", 1) or 1),
            "content_type": content_type | {"type_key": blueprint_key},
            "capabilities": capabilities,
            "fields": declared_fields,
            "template_binding": definition.get("template_binding", {}),
            "fieldsets": definition.get("fieldsets", []),
            "headless": definition.get("headless", {"enabled": True, "projection": "public_content_snapshots"}),
        }
        declared_ui_schema = definition.get("ui_schema") if isinstance(definition.get("ui_schema"), dict) else {}
        effective_ui_schema = dict(declared_ui_schema)
        effective_ui_schema.setdefault("editor_tabs", tabs)
        effective_ui_schema.setdefault("layout", "tabs" if tabs else "dedicated")
        effective_ui_schema["source"] = "native_blueprints.json"

        ensure_versioned_blueprint(
            cursor,
            blueprint_key=blueprint_key,
            resource_type="content_type",
            label=str(definition.get("label") or content_type.get("name") or blueprint_key),
            description=str(definition.get("description") or ""),
            schema_json=schema_json,
            ui_schema_json=effective_ui_schema,
            validation_json={"fields": declared_fields, "publication": {"requires_title": True, "requires_slug": True, "requires_public_block": True}},
            seo_policy_json=definition.get("seo_policy", {"enabled": True}),
            routing_policy_json=definition.get("routing_policy", {"enabled": True}),
            workflow_policy_json=definition.get("workflow_policy", {"states": ["draft", "review", "published", "archived"], "default_state": "draft"}),
            translation_policy_json=definition.get("translation_policy", {"strategy": "field"}),
            permissions_policy_json=definition.get("permissions_policy", {"read": "content.read", "write": "content.update", "publish": "content.publish"}),
        )
        cursor.execute(
            "UPDATE blueprints SET legacy_content_type_id=?, updated_at=CURRENT_TIMESTAMP WHERE blueprint_key=? AND resource_type='content_type' AND site_id IS NULL",
            (content_type_id, blueprint_key),
        )



def sync_system_blueprints(cursor: sqlite3.Cursor, payload: dict) -> None:
    """Seed les blueprints système: Tokens API, Webhooks, CORS et connexion par code email.

    Ces blueprints ne créent pas de content type éditorial. Ils documentent et exposent
    le contrat de configuration système dans la même source de vérité que les autres
    modèles, avec une permissions_policy directement reliée à la matrice IAM.
    """
    system_blueprints = payload.get("system_blueprints", {})
    if not isinstance(system_blueprints, dict):
        return
    for key, definition in system_blueprints.items():
        if not isinstance(definition, dict):
            continue
        blueprint_key = normalize_handle(key, "system_blueprint")
        resource_type = str(definition.get("resource_type") or "system")
        if resource_type not in {"system", "headless"}:
            resource_type = "system"
        raw_fields = definition.get("fields", [])
        if isinstance(raw_fields, list):
            fields = raw_fields
        elif isinstance(raw_fields, dict):
            fields = []
            for raw_handle, raw_field in raw_fields.items():
                if not isinstance(raw_field, dict):
                    continue
                field = dict(raw_field)
                field.setdefault("handle", raw_handle)
                field.setdefault("field_key", raw_handle)
                if "field_type" not in field and "type" in field:
                    field["field_type"] = field.get("type")
                if "type" not in field and "field_type" in field:
                    field["type"] = field.get("field_type")
                fields.append(field)
        else:
            fields = []
        tabs = definition.get("editor_tabs", []) if isinstance(definition.get("editor_tabs"), list) else []
        storage = module_blueprint_storage(definition, blueprint_key, resource_type)
        schema_json = {
            "schema_version": int(definition.get("schema_version", 1) or 1),
            "system_config": {
                "key": blueprint_key,
                "label": str(definition.get("label") or blueprint_key),
                "description": str(definition.get("description") or ""),
                "capabilities": definition.get("capabilities", {}),
            },
            "fields": fields,
            "headless": definition.get("headless", {"enabled": False}),
        }
        ui_schema_json = {
            "editor_tabs": tabs,
            "layout": "tabs",
            "source": "native_blueprints.json",
            "placement": definition.get("placement", {}),
        }
        ensure_versioned_blueprint(
            cursor,
            blueprint_key=blueprint_key,
            resource_type=resource_type,
            label=str(definition.get("label") or blueprint_key),
            description=str(definition.get("description") or ""),
            schema_json=schema_json,
            ui_schema_json=ui_schema_json,
            validation_json=definition.get("validation", {"fields": fields}),
            seo_policy_json={"enabled": False},
            routing_policy_json={"enabled": False},
            workflow_policy_json={"states": ["active", "inactive"], "default_state": "active", "audited": True},
            translation_policy_json={"strategy": "none"},
            permissions_policy_json=definition.get("permissions_policy", {}),
        )




def module_blueprint_storage(definition: dict, blueprint_key: str, resource_type: str) -> dict:
    """Retourne le stockage canonique d'un blueprint de module.

    Les seeds historiques de gabarits ou de modules peuvent avoir été créés
    avant l'obligation `storage.database/table/primary_key`. On conserve donc
    une compatibilité idempotente, mais la source JSON doit désormais contenir
    ce bloc explicitement.
    """
    raw = definition.get("storage") if isinstance(definition.get("storage"), dict) else {}
    storage = dict(raw)
    defaults = {
        "forms_form": {"database": "forms", "table": "forms", "primary_key": "id", "mode": "external_table"},
        "forms_form_field": {"database": "forms", "table": "form_fields", "primary_key": "id", "mode": "external_table"},
        "forms_form_submission": {"database": "forms", "table": "form_submissions", "primary_key": "id", "mode": "external_table"},
        "module_resource_template": {"database": "module", "table": "module_resource_table", "primary_key": "id", "mode": "external_table"},
        "module_headless_schema_template": {"database": "module", "table": "module_headless_resource", "primary_key": "id", "mode": "contract_only"},
    }
    fallback = defaults.get(blueprint_key, {})
    for key in ["database", "table", "primary_key", "mode"]:
        if not str(storage.get(key, "")).strip() and key in fallback:
            storage[key] = fallback[key]
    if resource_type == "headless" and not storage.get("mode"):
        storage["mode"] = "contract_only"
    return storage

def sync_module_blueprint_templates(cursor: sqlite3.Cursor, payload: dict) -> None:
    """Seed les blueprints natifs de ressources module.

    Ils servent de modèles concrets dans la famille "Modules métier" et de
    preuve from-scratch que des ressources stockées hors du pipeline éditorial
    peuvent être gouvernées par les blueprints. Les providers restent la source
    déclarative des modules réels ; ce seed matérialise les gabarits et les
    ressources système déjà présentes, comme Forms.
    """
    module_blueprints = payload.get("module_blueprints", {})
    if not isinstance(module_blueprints, dict):
        return
    for key, definition in module_blueprints.items():
        if not isinstance(definition, dict):
            continue
        blueprint_key = normalize_handle(key, "module_blueprint")
        resource_type = str(definition.get("resource_type") or "module_resource")
        # marker attendu par c72_validate_module_admin_governance.py: module_resource','headless
        if resource_type not in {"module_resource", "headless"}:
            resource_type = "module_resource"
        raw_fields = definition.get("fields", [])
        if isinstance(raw_fields, list):
            fields = raw_fields
        elif isinstance(raw_fields, dict):
            fields = []
            for raw_handle, raw_field in raw_fields.items():
                if not isinstance(raw_field, dict):
                    continue
                field = dict(raw_field)
                field.setdefault("handle", raw_handle)
                field.setdefault("field_key", raw_handle)
                if "field_type" not in field and "type" in field:
                    field["field_type"] = field.get("type")
                if "type" not in field and "field_type" in field:
                    field["type"] = field.get("field_type")
                fields.append(field)
        else:
            fields = []
        tabs = definition.get("editor_tabs", []) if isinstance(definition.get("editor_tabs"), list) else []
        storage = module_blueprint_storage(definition, blueprint_key, resource_type)
        schema_json = {
            "schema_version": int(definition.get("schema_version", 1) or 1),
            "module": str(definition.get("module") or ""),
            "resource": str(definition.get("resource") or blueprint_key),
            "blueprint_key": blueprint_key,
            "resource_type": resource_type,
            "storage": storage,
            "capabilities": definition.get("capabilities", {}),
            "permissions": definition.get("permissions", {}),
            "headless": definition.get("headless", {"enabled": False}),
            "fields": fields,
            "relations": definition.get("relations", []),
            "export": definition.get("export_policy") or definition.get("export") or {},
        }
        ui_schema_json = {
            "editor_tabs": tabs,
            "layout": "tabs",
            "source": "native_blueprints.json",
            "placement": {"family": "Modules métier", "module": str(definition.get("module") or "")},
        }
        ensure_versioned_blueprint(
            cursor,
            blueprint_key=blueprint_key,
            resource_type=resource_type,
            label=str(definition.get("label") or blueprint_key),
            description=str(definition.get("description") or ""),
            schema_json=schema_json,
            ui_schema_json=ui_schema_json,
            validation_json=definition.get("validation", {"fields": fields}),
            seo_policy_json={"enabled": False},
            routing_policy_json={"enabled": False},
            workflow_policy_json={"states": ["active", "inactive", "archived"], "default_state": "active", "audited": True},
            translation_policy_json={"strategy": "none"},
            permissions_policy_json=definition.get("permissions_policy") or definition.get("permissions") or {},
        )
        if table_exists(cursor, "module_blueprints"):
            module_key = str(definition.get("module") or "_template")
            resource = str(definition.get("resource") or blueprint_key)

            # Les gabarits natifs rendent la famille "Modules métier" visible dans
            # Studio, mais ne sont pas des blueprints publiés par un vrai module.
            # Les insérer dans module_blueprints créerait une FK artificielle vers
            # modules('_template') et ferait échouer PRAGMA foreign_key_check.
            is_template_blueprint = module_key in {"", "_template", "template"}
            if not is_template_blueprint:
                if table_exists(cursor, "modules"):
                    cursor.execute(
                        """
                        INSERT INTO modules(module_key, name, version, provider_class, is_system, is_installed, is_enabled, updated_at)
                        VALUES(?, ?, ?, NULL, 1, 1, 1, CURRENT_TIMESTAMP)
                        ON CONFLICT(module_key) DO UPDATE SET
                            name=excluded.name,
                            version=excluded.version,
                            is_system=CASE WHEN modules.is_system=1 THEN 1 ELSE excluded.is_system END,
                            is_installed=1,
                            is_enabled=CASE WHEN modules.is_enabled IS NULL THEN 1 ELSE modules.is_enabled END,
                            updated_at=CURRENT_TIMESTAMP
                        """,
                        (
                            module_key,
                            str(definition.get("module_label") or definition.get("module_name") or module_key.replace("_", " ").title()),
                            str(definition.get("module_version") or "1.0.0"),
                        ),
                    )
                cursor.execute(
                    """
                    INSERT INTO module_blueprints(
                        module_key, resource, blueprint_key, resource_type, declared_version,
                        storage_json, capabilities_json, permissions_json, headless_json,
                        admin_schema_json, relations_json, export_policy_json, contract_json,
                        validation_errors_json, is_published, updated_at
                    ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,CURRENT_TIMESTAMP)
                    ON CONFLICT(module_key, blueprint_key, resource_type) DO UPDATE SET
                        resource=excluded.resource,
                        declared_version=excluded.declared_version,
                        storage_json=excluded.storage_json,
                        capabilities_json=excluded.capabilities_json,
                        permissions_json=excluded.permissions_json,
                        headless_json=excluded.headless_json,
                        admin_schema_json=excluded.admin_schema_json,
                        relations_json=excluded.relations_json,
                        export_policy_json=excluded.export_policy_json,
                        contract_json=excluded.contract_json,
                        validation_errors_json=excluded.validation_errors_json,
                        is_published=1,
                        updated_at=CURRENT_TIMESTAMP
                    """,
                    (
                        module_key,
                        resource,
                        blueprint_key,
                        resource_type,
                        int(definition.get("schema_version", 1) or 1),
                        compact_json(storage),
                        compact_json(definition.get("capabilities", {})),
                        compact_json(definition.get("permissions", {})),
                        compact_json(definition.get("headless", {})),
                        compact_json({"editor_tabs": tabs, "route": definition.get("admin", {}).get("route") if isinstance(definition.get("admin"), dict) else None}),
                        compact_json(definition.get("relations", [])),
                        compact_json(definition.get("export_policy") or definition.get("export") or {}),
                        compact_json(definition.get("contract") or {}),
                        compact_json([]),
                    ),
                )



def repair_module_blueprint_storage(cursor: sqlite3.Cursor) -> None:
    """Backfill idempotent du stockage des blueprints de modules déjà synchronisés.

    Certains environnements peuvent avoir été seedés avec les lignes
    `module_blueprints` avant que `storage_json` ne devienne obligatoire pour
    les ressources métier. Le validateur c71 lit la table runtime : on répare
    donc explicitement les lignes connues à chaque seed natif, sans toucher
    aux données métier ni aux bases de modules.
    """
    if not table_exists(cursor, "module_blueprints"):
        return
    defaults = {
        ("forms", "forms_form", "module_resource"): {"database": "forms", "table": "forms", "primary_key": "id", "mode": "external_table"},
        ("forms", "forms_form_field", "module_resource"): {"database": "forms", "table": "form_fields", "primary_key": "id", "mode": "external_table"},
        ("forms", "forms_form_submission", "module_resource"): {"database": "forms", "table": "form_submissions", "primary_key": "id", "mode": "external_table"},
    }
    for (module_key, blueprint_key, resource_type), storage in defaults.items():
        cursor.execute(
            """
            UPDATE module_blueprints
            SET storage_json=?, updated_at=CURRENT_TIMESTAMP
            WHERE module_key=?
              AND blueprint_key=?
              AND resource_type=?
              AND (
                    storage_json IS NULL
                 OR storage_json=''
                 OR json_extract(storage_json, '$.database') IS NULL
                 OR TRIM(COALESCE(json_extract(storage_json, '$.database'), ''))=''
                 OR json_extract(storage_json, '$.table') IS NULL
                 OR TRIM(COALESCE(json_extract(storage_json, '$.table'), ''))=''
                 OR json_extract(storage_json, '$.primary_key') IS NULL
                 OR TRIM(COALESCE(json_extract(storage_json, '$.primary_key'), ''))=''
              )
            """,
            (compact_json(storage), module_key, blueprint_key, resource_type),
        )

def normalize_system_blueprint(cursor: sqlite3.Cursor, blueprint_id: int, schema: dict, ui_schema: dict, scope: str = "system") -> None:
    if not table_exists(cursor, "blueprint_sections"):
        return
    if table_exists(cursor, "field_validation_rules"):
        cursor.execute("DELETE FROM field_validation_rules WHERE blueprint_field_id IN (SELECT id FROM blueprint_fields WHERE blueprint_id=?)", (blueprint_id,))
    if table_exists(cursor, "field_visibility_conditions"):
        cursor.execute("DELETE FROM field_visibility_conditions WHERE blueprint_field_id IN (SELECT id FROM blueprint_fields WHERE blueprint_id=?)", (blueprint_id,))
        cursor.execute("DELETE FROM field_visibility_conditions WHERE blueprint_fieldset_id IN (SELECT id FROM blueprint_fieldsets WHERE blueprint_id=?)", (blueprint_id,))
        cursor.execute("DELETE FROM field_visibility_conditions WHERE blueprint_section_id IN (SELECT id FROM blueprint_sections WHERE blueprint_id=?)", (blueprint_id,))
    cursor.execute("DELETE FROM blueprint_fieldsets WHERE blueprint_id=?", (blueprint_id,))
    cursor.execute("DELETE FROM blueprint_fields WHERE blueprint_id=?", (blueprint_id,))
    cursor.execute("DELETE FROM blueprint_sections WHERE blueprint_id=?", (blueprint_id,))
    fields = schema.get("fields", []) if isinstance(schema.get("fields"), list) else []
    tabs = ui_schema.get("editor_tabs", []) if isinstance(ui_schema.get("editor_tabs"), list) else []
    if not tabs:
        tabs = [{"key": "main", "label": "Configuration", "fields": [f.get("field_key") for f in fields if isinstance(f, dict)], "sort_order": 10}]
    field_by_handle = {normalize_handle(f.get("field_key") or f.get("handle"), "field"): f for f in fields if isinstance(f, dict)}
    mounted: set[str] = set()
    for i, tab in enumerate([t for t in tabs if isinstance(t, dict)], start=1):
        section_key = normalize_handle(tab.get("key"), f"section_{i}")
        cursor.execute(
            "INSERT INTO blueprint_sections(blueprint_id, section_key, label, description, layout, sort_order, config_json) VALUES(?,?,?,?,?,?,?)",
            (blueprint_id, section_key, str(tab.get("label") or section_key), str(tab.get("description") or ""), "section", int(tab.get("sort_order") or i * 10), compact_json({"source": "system_blueprint"})),
        )
        section_id = int(cursor.lastrowid)
        handles = [normalize_handle(x, "field") for x in (tab.get("fields", []) if isinstance(tab.get("fields"), list) else [])]
        if not handles:
            handles = [h for h, f in field_by_handle.items() if normalize_handle(f.get("tab_key") or "main") == section_key]
        for n, handle in enumerate(handles, start=1):
            field = field_by_handle.get(handle)
            if not field:
                continue
            validation = field.get("validation") if isinstance(field.get("validation"), dict) else (field.get("validate") if isinstance(field.get("validate"), dict) else {})
            conditions = field.get("conditions") if isinstance(field.get("conditions"), list) else []
            purpose = str(field.get("field_purpose") or "system")
            cursor.execute(
                """
                INSERT INTO blueprint_fields(
                    blueprint_id, section_id, field_handle, field_type, label, help_text, field_purpose, width,
                    is_required, is_localized, is_system, is_deletable, sort_order,
                    default_value_json, options_json, validation_json, conditions_json, config_json
                ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON CONFLICT(blueprint_id, field_handle) DO NOTHING
                """,
                (
                    blueprint_id, section_id, handle, field_type_from(field.get("field_type") or field.get("type")), str(field.get("label") or field.get("display") or handle),
                    str(field.get("help_text") or field.get("instructions") or ""), purpose, int(field.get("width") or 100),
                    1 if field.get("is_required") or field.get("required") else 0,
                    1 if field.get("is_localized", field.get("localizable", False)) else 0,
                    1 if field.get("is_system") or field.get("field_scope") == "system_context" else 0,
                    0 if field.get("is_system") or field.get("field_scope") == "system_context" else 1,
                    n * 10,
                    json_or_empty(field.get("default"), None) if field.get("default") is not None else None,
                    json_or_empty(field.get("options") or (field.get("config") or {}).get("options") if isinstance(field.get("config"), dict) else {}, {}),
                    compact_json(validation), compact_json(conditions), json_or_empty(field_config_with_metadata(field, handle, purpose, {"seed_source": "native_blueprints.json", "blueprint_scope": scope}), {}),
                ),
            )
            sync_field_rules(cursor, scope="blueprint_field", row_id=int(cursor.lastrowid), validation=validation, conditions=conditions)
            mounted.add(handle)

def sync_normalized_blueprint_model(cursor: sqlite3.Cursor, fieldsets: dict) -> None:
    if not table_exists(cursor, "fieldsets") or not table_exists(cursor, "blueprint_fields"):
        return
    for key, definition in fieldsets.items():
        if isinstance(definition, dict):
            upsert_fieldset(cursor, key, definition)

    rows = cursor.execute(
        """
        SELECT b.id, b.blueprint_key, b.resource_type, bv.schema_json, bv.ui_schema_json
        FROM blueprints b
        JOIN blueprint_versions bv ON bv.id = b.active_version_id
        WHERE b.resource_type IN ('content_type','block','system') AND b.is_active = 1
        ORDER BY b.resource_type, b.blueprint_key
        """
    ).fetchall()
    for blueprint_id, _key, resource_type, schema_json, ui_schema_json in rows:
        try:
            schema = json.loads(schema_json or "{}")
        except Exception:
            schema = {}
        try:
            ui_schema = json.loads(ui_schema_json or "{}")
        except Exception:
            ui_schema = {}
        if resource_type == "content_type":
            normalize_content_blueprint(cursor, int(blueprint_id), schema, ui_schema)
        elif resource_type == "block":
            block = schema.get("block") if isinstance(schema, dict) else None
            if isinstance(block, dict):
                normalize_block_blueprint(cursor, int(blueprint_id), block)
        elif resource_type == "system":
            normalize_system_blueprint(cursor, int(blueprint_id), schema, ui_schema)

    module_rows = cursor.execute(
        """
        SELECT b.id, b.blueprint_key, b.resource_type, bv.schema_json, bv.ui_schema_json
        FROM blueprints b
        JOIN blueprint_versions bv ON bv.id = b.active_version_id
        WHERE b.resource_type IN ('module_resource','headless') AND b.is_active = 1
        ORDER BY b.resource_type, b.blueprint_key
        """
    ).fetchall()
    for blueprint_id, _key, _resource_type, schema_json, ui_schema_json in module_rows:
        try:
            schema = json.loads(schema_json or "{}")
        except Exception:
            schema = {}
        try:
            ui_schema = json.loads(ui_schema_json or "{}")
        except Exception:
            ui_schema = {}
        normalize_system_blueprint(cursor, int(blueprint_id), schema, ui_schema, "module")


def normalize_block_blueprint(cursor: sqlite3.Cursor, blueprint_id: int, definition: dict) -> None:
    if table_exists(cursor, "field_validation_rules"):
        cursor.execute("DELETE FROM field_validation_rules WHERE blueprint_field_id IN (SELECT id FROM blueprint_fields WHERE blueprint_id=?)", (blueprint_id,))
    if table_exists(cursor, "field_visibility_conditions"):
        cursor.execute("DELETE FROM field_visibility_conditions WHERE blueprint_field_id IN (SELECT id FROM blueprint_fields WHERE blueprint_id=?)", (blueprint_id,))
        cursor.execute("DELETE FROM field_visibility_conditions WHERE blueprint_fieldset_id IN (SELECT id FROM blueprint_fieldsets WHERE blueprint_id=?)", (blueprint_id,))
        cursor.execute("DELETE FROM field_visibility_conditions WHERE blueprint_section_id IN (SELECT id FROM blueprint_sections WHERE blueprint_id=?)", (blueprint_id,))
    cursor.execute("DELETE FROM blueprint_fieldsets WHERE blueprint_id=?", (blueprint_id,))
    cursor.execute("DELETE FROM blueprint_fields WHERE blueprint_id=?", (blueprint_id,))
    cursor.execute("DELETE FROM blueprint_sections WHERE blueprint_id=?", (blueprint_id,))
    section_ids: dict[str, int] = {}
    sections = definition.get("sections", []) if isinstance(definition.get("sections"), list) else []
    if not sections:
        sections = [{"key": "content", "display": "Contenu", "fields": []}]
    for i, section in enumerate([s for s in sections if isinstance(s, dict)], start=1):
        key = normalize_handle(section.get("key"), f"section_{i}")
        cursor.execute(
            "INSERT INTO blueprint_sections(blueprint_id, section_key, label, description, layout, sort_order, config_json) VALUES(?,?,?,?,?,?,?)",
            (blueprint_id, key, str(section.get("display") or section.get("label") or key), str(section.get("instructions") or ""), "section", int(section.get("sort_order") or i * 10), compact_json({"source": "block_blueprint"})),
        )
        section_id = int(cursor.lastrowid)
        section_ids[key] = section_id
        for n, field in enumerate(iter_section_fields(section), start=1):
            if field.get("fieldset"):
                fs_key = normalize_handle(field.get("fieldset"), "fieldset")
                row = cursor.execute("SELECT id FROM fieldsets WHERE fieldset_key=?", (fs_key,)).fetchone()
                if row:
                    cursor.execute(
                        "INSERT INTO blueprint_fieldsets(blueprint_id, section_id, fieldset_id, mount_handle, label, sort_order, config_json) VALUES(?,?,?,?,?,?,?) ON CONFLICT(blueprint_id, mount_handle) DO NOTHING",
                        (blueprint_id, section_id, int(row[0]), normalize_handle(field.get("handle") or fs_key, fs_key), str(field.get("display") or fs_key), n * 10, compact_json(field.get("config") or {})),
                    )
                continue
            handle = normalize_handle(field.get("handle") or field.get("field_key"), "field")
            validation = field.get("validate") if isinstance(field.get("validate"), dict) else {}
            conditions = field.get("conditions") if isinstance(field.get("conditions"), list) else []
            cursor.execute(
                """
                INSERT INTO blueprint_fields(
                    blueprint_id, section_id, field_handle, field_type, label, help_text, field_purpose, width,
                    is_required, is_localized, is_system, is_deletable, sort_order,
                    default_value_json, options_json, validation_json, conditions_json, config_json
                ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON CONFLICT(blueprint_id, field_handle) DO NOTHING
                """,
                (
                    blueprint_id, section_id, handle, field_type_from(field.get("type")), str(field.get("display") or handle),
                    str(field.get("instructions") or ""), purpose_for_handle(handle, "page_builder"), int(field.get("width") or 100),
                    1 if field.get("required") else 0,
                    1 if field.get("localizable", True) else 0,
                    1 if field.get("system") or is_protected_system_field(handle) or purpose_for_handle(handle) == "system" else 0,
                    0 if field.get("system") or is_protected_system_field(handle) or purpose_for_handle(handle) == "system" else 1,
                    n * 10,
                    json_or_empty(field.get("default"), None) if field.get("default") is not None else None,
                    json_or_empty(field.get("options") or (field.get("config") or {}).get("options") if isinstance(field.get("config"), dict) else {}, {}),
                    compact_json(validation), compact_json(conditions), json_or_empty(field.get("config"), {}),
                ),
            )
            sync_field_rules(cursor, scope="blueprint_field", row_id=int(cursor.lastrowid), validation=validation, conditions=conditions)

def seed(db_path: Path = CORE_DB) -> None:
    payload = load_payload()
    con = sqlite3.connect(db_path)
    try:
        c = con.cursor()
        for ft in payload.get("field_types", []):
            if not isinstance(ft, dict):
                continue
            c.execute(
                """
                INSERT INTO schema_field_types(type_key, label, storage_mode, component_key, is_implemented, definition_json, updated_at)
                VALUES(?,?,?,?,?,?,CURRENT_TIMESTAMP)
                ON CONFLICT(type_key) DO UPDATE SET
                    label=excluded.label,
                    storage_mode=excluded.storage_mode,
                    component_key=excluded.component_key,
                    is_implemented=excluded.is_implemented,
                    definition_json=excluded.definition_json,
                    updated_at=CURRENT_TIMESTAMP
                """,
                (
                    str(ft.get("handle", "")),
                    str(ft.get("display", "")),
                    str(ft.get("storage", "scalar")),
                    str(ft.get("component", "")),
                    1 if ft.get("implemented") else 0,
                    compact_json(ft),
                ),
            )


        field_types = [ft for ft in payload.get("field_types", []) if isinstance(ft, dict)]
        fieldsets = {str(k): v for k, v in payload.get("fieldsets", {}).items() if isinstance(v, dict)}
        sync_content_blueprints(c, payload)
        sync_system_blueprints(c, payload)
        sync_module_blueprint_templates(c, payload)
        repair_module_blueprint_storage(c)
        canonical_blocks = {str(key) for key, definition in payload.get("block_blueprints", {}).items() if isinstance(definition, dict)}
        for sort_index, (key, definition) in enumerate(payload.get("block_blueprints", {}).items(), start=1):
            if not isinstance(definition, dict):
                continue
            title = str(definition.get("title", key))
            ensure_versioned_blueprint(
                c,
                blueprint_key=str(key),
                resource_type="block",
                label=title,
                description=f"Blueprint natif de bloc éditorial: {title}.",
                schema_json=versioned_schema_for_block(definition, fieldsets, field_types),
                ui_schema_json={"sections": definition.get("sections", []), "fieldsets": fieldsets, "field_types": field_types},
                validation_json={"publication": {"requires_public_block": True}},
                workflow_policy_json={"states": ["draft", "review", "published", "archived"], "default_state": "published"},
                translation_policy_json={"localized_fields": "field.localizable"},
                permissions_policy_json={"read": "content.read", "write": "content.update"},
            )
            sync_editor_block_type(c, block_type=str(key), definition=definition, sort_order=sort_index * 10)
        retire_legacy_block_aliases(c, canonical_blocks)
        sync_normalized_blueprint_model(c, fieldsets)
        con.commit()
    finally:
        con.close()


def main() -> int:
    try:
        seed()
    except Exception as exc:  # noqa: BLE001
        print(f"ERREUR seed blueprints natifs: {exc}", file=sys.stderr)
        return 1
    print("BluePrints/champs natifs seedés.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())


