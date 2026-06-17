#!/usr/bin/env python3
"""Audit et maintenance locale du module media.

Par defaut, le script ne modifie rien. Il liste :
- les medias prets sans usage ;
- les fichiers references mais absents du disque ;
- les usages avec alt obligatoire mais alt manquant ;
- les médias invalides, rejetés ou bloqués en quarantaine ;
- les references media cross-site dans SEO/sites/champs ;
- les references de configuration SEO/OpenGraph absentes de media_usages ;
- les references de configuration SEO/OpenGraph absentes de media_usages ;
- les medias marques delete_pending ;
- les presets de variants attendus par site ;
- les variants generes qui ne correspondent pas a un preset actif ;
- les variants attendus manquants pour les images pretes ;
- les evenements media recents.

Actions optionnelles, toutes protegees par --yes :
- --mark-unused-delete-pending --older-than-days N : marque les medias inutilises comme delete_pending ;
- --purge-deleted-files : supprime du disque les fichiers references par des medias deja marques deleted ;
- --sync-config-usages : reconstruit les usages systeme des medias de configuration.

Utilisation :
    python3 tools/python/operations/maintenance/g14_media_maintenance.py
    python3 tools/python/operations/maintenance/g14_media_maintenance.py --json
    python3 tools/python/operations/maintenance/g14_media_maintenance.py --mark-unused-delete-pending --older-than-days 30 --yes
    python3 tools/python/operations/maintenance/g14_media_maintenance.py --purge-deleted-files --yes
    python3 tools/python/operations/maintenance/g14_media_maintenance.py --sync-config-usages --yes
"""
from __future__ import annotations

import argparse
import hashlib
import json
import sqlite3
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any

BASE = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
CORE_DB = BASE / "storage" / "database" / "core.sqlite"
MEDIA_ROOT = BASE / "storage" / "media"


def connect() -> sqlite3.Connection:
    if not CORE_DB.exists():
        raise FileNotFoundError(f"Base core introuvable: {CORE_DB}. Lancez a_db_init.py d'abord.")
    con = sqlite3.connect(CORE_DB)
    con.row_factory = sqlite3.Row
    con.execute("PRAGMA foreign_keys = ON")
    return con


def table_exists(con: sqlite3.Connection, table: str) -> bool:
    row = con.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?", (table,)).fetchone()
    return row is not None


def require_media_schema(con: sqlite3.Connection) -> None:
    required = {"media_folders", "media_assets", "media_variant_presets", "media_asset_localizations", "media_asset_variants", "media_usages", "media_asset_events"}
    missing = sorted(table for table in required if not table_exists(con, table))
    if missing:
        raise RuntimeError("Tables media manquantes: " + ", ".join(missing))


def rows(con: sqlite3.Connection, sql: str, params: tuple[Any, ...] = ()) -> list[dict[str, Any]]:
    return [dict(row) for row in con.execute(sql, params).fetchall()]


def safe_media_path(relative: str | None) -> Path | None:
    if not relative:
        return None
    rel = Path(str(relative).replace("\\", "/"))
    if rel.is_absolute() or ".." in rel.parts:
        return None
    absolute = (MEDIA_ROOT / rel).resolve()
    try:
        absolute.relative_to(MEDIA_ROOT.resolve())
    except ValueError:
        return None
    return absolute


def collect_referenced_paths(con: sqlite3.Connection, media_id: int | None = None) -> list[dict[str, Any]]:
    where = "WHERE id = ?" if media_id is not None else ""
    params: tuple[Any, ...] = (media_id,) if media_id is not None else ()
    assets = rows(
        con,
        f"""
        SELECT id AS media_id, site_id, filename, path, public_path, quarantine_path, lifecycle_status
        FROM media_assets
        {where}
        """,
        params,
    )
    paths: list[dict[str, Any]] = []
    for asset in assets:
        for key in ("path", "public_path", "quarantine_path"):
            absolute = safe_media_path(asset.get(key))
            if asset.get(key):
                paths.append({**asset, "column": key, "path": asset.get(key), "absolute": str(absolute) if absolute else None})

    variant_where = "WHERE media_id = ?" if media_id is not None else ""
    variants = rows(con, f"SELECT media_id, variant_key, path FROM media_asset_variants {variant_where}", params)
    for variant in variants:
        absolute = safe_media_path(variant.get("path"))
        if variant.get("path"):
            paths.append({**variant, "column": "variant:" + str(variant["variant_key"]), "absolute": str(absolute) if absolute else None})
    return paths


def audit(con: sqlite3.Connection) -> dict[str, Any]:
    folders = rows(
        con,
        """
        SELECT mf.id, mf.site_id, mf.folder_key, mf.name, mf.parent_id, mf.sort_order,
               COUNT(ma.id) AS asset_count
        FROM media_folders mf
        LEFT JOIN media_assets ma ON ma.folder_id = mf.id AND ma.lifecycle_status != 'deleted'
        GROUP BY mf.id
        ORDER BY mf.site_id, mf.parent_id IS NOT NULL, mf.parent_id, mf.sort_order, mf.name
        """,
    )

    orphan_folder_assets = rows(
        con,
        """
        SELECT ma.id, ma.site_id, ma.filename, ma.folder_id
        FROM media_assets ma
        LEFT JOIN media_folders mf ON mf.id = ma.folder_id AND mf.site_id = ma.site_id
        WHERE mf.id IS NULL
        ORDER BY ma.site_id, ma.id
        """,
    )

    unused = rows(
        con,
        """
        SELECT ma.id, ma.site_id, ma.filename, ma.path, ma.created_at
        FROM media_assets ma
        LEFT JOIN media_usages mu ON mu.media_id = ma.id
        WHERE ma.lifecycle_status = 'ready' AND mu.id IS NULL
        ORDER BY ma.created_at ASC, ma.id ASC
        """,
    )

    missing_files: list[dict[str, Any]] = []
    unsafe_paths: list[dict[str, Any]] = []
    for item in collect_referenced_paths(con):
        if item.get("absolute") is None:
            unsafe_paths.append({k: v for k, v in item.items() if k != "absolute"})
            continue
        if not Path(str(item["absolute"])).exists():
            missing_files.append({k: v for k, v in item.items() if k != "absolute"})

    alt_missing = rows(
        con,
        """
        SELECT mu.media_id, mu.resource_type, mu.resource_id, mu.field_key, mu.language_code, mu.usage_context
        FROM media_usages mu
        JOIN media_assets ma ON ma.id = mu.media_id
        LEFT JOIN media_asset_localizations mal
            ON mal.media_id = mu.media_id AND mal.language_code = mu.language_code
        WHERE mu.alt_policy = 'required'
          AND ma.media_type = 'image'
          AND COALESCE(TRIM(mal.alt_text), '') = ''
        ORDER BY mu.media_id, mu.resource_type, mu.resource_id
        """,
    )

    invalid_media_states = rows(
        con,
        """
        SELECT id, site_id, filename, lifecycle_status, validation_status, variants_status, validation_errors_json, created_at
        FROM media_assets
        WHERE lifecycle_status IN ('quarantined','rejected')
           OR validation_status != 'valid'
           OR variants_status = 'failed'
        ORDER BY site_id, created_at ASC, id ASC
        """,
    )

    cross_site_references = rows(
        con,
        """
        SELECT 'site_localizations.og_default_image_media_id' AS reference_kind,
               sl.site_id AS owner_site_id,
               sl.id AS owner_id,
               sl.og_default_image_media_id AS media_id,
               ma.site_id AS media_site_id
        FROM site_localizations sl
        JOIN media_assets ma ON ma.id = sl.og_default_image_media_id
        WHERE ma.site_id <> sl.site_id
        UNION ALL
        SELECT 'seo_metadata.og_image_media_id' AS reference_kind,
               sm.site_id AS owner_site_id,
               sm.id AS owner_id,
               sm.og_image_media_id AS media_id,
               ma.site_id AS media_site_id
        FROM seo_metadata sm
        JOIN media_assets ma ON ma.id = sm.og_image_media_id
        WHERE ma.site_id <> sm.site_id
        UNION ALL
        SELECT 'seo_metadata.twitter_image_media_id' AS reference_kind,
               sm.site_id AS owner_site_id,
               sm.id AS owner_id,
               sm.twitter_image_media_id AS media_id,
               ma.site_id AS media_site_id
        FROM seo_metadata sm
        JOIN media_assets ma ON ma.id = sm.twitter_image_media_id
        WHERE ma.site_id <> sm.site_id
        UNION ALL
        SELECT 'content_entry_field_values.value_media_id' AS reference_kind,
               ce.site_id AS owner_site_id,
               fv.id AS owner_id,
               fv.value_media_id AS media_id,
               ma.site_id AS media_site_id
        FROM content_entry_field_values fv
        JOIN content_entries ce ON ce.id = fv.entry_id
        JOIN media_assets ma ON ma.id = fv.value_media_id
        WHERE ma.site_id <> ce.site_id
        ORDER BY reference_kind, owner_site_id, owner_id
        """,
    )

    delete_pending = rows(
        con,
        """
        SELECT ma.id, ma.site_id, ma.filename, ma.delete_requested_at,
               COUNT(mu.id) AS usage_count
        FROM media_assets ma
        LEFT JOIN media_usages mu ON mu.media_id = ma.id
        WHERE ma.lifecycle_status = 'delete_pending'
        GROUP BY ma.id
        ORDER BY ma.delete_requested_at ASC
        """,
    )

    missing_tracked_config_usages = rows(
        con,
        """
        SELECT 'site_localizations.og_default_image_media_id' AS reference_kind,
               sl.site_id,
               sl.language_code,
               sl.og_default_image_media_id AS media_id
        FROM site_localizations sl
        JOIN media_assets ma ON ma.id = sl.og_default_image_media_id AND ma.site_id = sl.site_id
        LEFT JOIN media_usages mu
          ON mu.media_id = sl.og_default_image_media_id
         AND mu.site_id = sl.site_id
         AND mu.resource_type = 'system'
         AND mu.resource_id = 0
         AND mu.field_key = 'localization.og_default_image_media_id'
         AND mu.language_code = sl.language_code
         AND mu.usage_context = 'opengraph'
        WHERE sl.og_default_image_media_id IS NOT NULL
          AND mu.id IS NULL
        ORDER BY sl.site_id, sl.language_code
        """,
    )

    variant_presets = rows(
        con,
        """
        SELECT site_id, preset_key, width, height, format, quality, mode, sort_order, is_active
        FROM media_variant_presets
        ORDER BY site_id, is_active DESC, sort_order, preset_key
        """,
    )

    unexpected_variants = rows(
        con,
        """
        SELECT ma.id AS media_id, ma.site_id, ma.filename, mav.variant_key, mav.path, mav.preset_id
        FROM media_asset_variants mav
        JOIN media_assets ma ON ma.id = mav.media_id
        LEFT JOIN media_variant_presets mvp
            ON mvp.id = mav.preset_id
           AND mvp.site_id = ma.site_id
           AND mvp.preset_key = mav.variant_key
           AND mvp.is_active = 1
        WHERE mav.variant_key != 'original' AND mvp.id IS NULL
        ORDER BY ma.site_id, ma.id, mav.variant_key
        """,
    )

    missing_expected_variants = rows(
        con,
        """
        SELECT ma.id AS media_id, ma.site_id, ma.filename, mvp.preset_key, mvp.width, mvp.height, mvp.format
        FROM media_assets ma
        JOIN media_variant_presets mvp ON mvp.site_id = ma.site_id AND mvp.is_active = 1
        LEFT JOIN media_asset_variants mav
            ON mav.media_id = ma.id
           AND mav.variant_key = mvp.preset_key
           AND mav.generation_status = 'ready'
        WHERE ma.lifecycle_status = 'ready'
          AND ma.media_type = 'image'
          AND mav.id IS NULL
        ORDER BY ma.site_id, ma.id, mvp.sort_order, mvp.preset_key
        """,
    )

    recent_events = rows(
        con,
        """
        SELECT media_id, site_id, event_type, actor_iam_user_id, payload_json, created_at
        FROM media_asset_events
        ORDER BY created_at DESC, id DESC
        LIMIT 25
        """,
    )

    return {
        "folders": folders,
        "orphan_folder_assets": orphan_folder_assets,
        "unused_media": unused,
        "missing_files": missing_files,
        "unsafe_paths": unsafe_paths,
        "missing_required_alt": alt_missing,
        "cross_site_references": cross_site_references,
        "invalid_media_states": invalid_media_states,
        "delete_pending": delete_pending,
        "missing_tracked_config_usages": missing_tracked_config_usages,
        "variant_presets": variant_presets,
        "unexpected_variants": unexpected_variants,
        "missing_expected_variants": missing_expected_variants,
        "recent_events": recent_events,
        "summary": {
            "folders": len(folders),
            "orphan_folder_assets": len(orphan_folder_assets),
            "unused_media": len(unused),
            "missing_files": len(missing_files),
            "unsafe_paths": len(unsafe_paths),
            "missing_required_alt": len(alt_missing),
            "cross_site_references": len(cross_site_references),
            "invalid_media_states": len(invalid_media_states),
            "delete_pending": len(delete_pending),
            "missing_tracked_config_usages": len(missing_tracked_config_usages),
            "variant_presets": len(variant_presets),
            "unexpected_variants": len(unexpected_variants),
            "missing_expected_variants": len(missing_expected_variants),
            "recent_events": len(recent_events),
        },
    }


def record_event(con: sqlite3.Connection, media_id: int, site_id: int, event_type: str, payload: dict[str, Any] | None = None) -> None:
    con.execute(
        """
        INSERT INTO media_asset_events(media_id, site_id, event_type, payload_json, created_at)
        VALUES(?, ?, ?, ?, CURRENT_TIMESTAMP)
        """,
        (media_id, site_id, event_type, json.dumps(payload or {}, ensure_ascii=False) if payload else None),
    )


def mark_unused_delete_pending(con: sqlite3.Connection, older_than_days: int, execute: bool) -> list[dict[str, Any]]:
    cutoff = (datetime.now(timezone.utc) - timedelta(days=max(0, older_than_days))).strftime("%Y-%m-%d %H:%M:%S")
    candidates = rows(
        con,
        """
        SELECT ma.id, ma.site_id, ma.filename, ma.created_at
        FROM media_assets ma
        LEFT JOIN media_usages mu ON mu.media_id = ma.id
        WHERE ma.lifecycle_status = 'ready'
          AND mu.id IS NULL
          AND ma.created_at <= ?
        ORDER BY ma.created_at ASC, ma.id ASC
        """,
        (cutoff,),
    )
    if execute and candidates:
        with con:
            for item in candidates:
                con.execute(
                    "UPDATE media_assets SET lifecycle_status = 'delete_pending', delete_requested_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
                    (item["id"],),
                )
                record_event(con, int(item["id"]), int(item["site_id"]), "delete_pending", {"source": "g14_media_maintenance.py"})
    return candidates


def purge_deleted_files(con: sqlite3.Connection, execute: bool) -> list[dict[str, Any]]:
    deleted_assets = rows(con, "SELECT id FROM media_assets WHERE lifecycle_status = 'deleted'")
    purged: list[dict[str, Any]] = []
    for asset in deleted_assets:
        for item in collect_referenced_paths(con, int(asset["id"])):
            absolute = safe_media_path(item.get("path"))
            if absolute and absolute.is_file():
                purged.append({k: v for k, v in item.items() if k != "absolute"})
                if execute:
                    absolute.unlink()
    return purged


def sync_config_usages(con: sqlite3.Connection, execute: bool) -> list[dict[str, Any]]:
    field_key = "localization.og_default_image_media_id"
    candidates = rows(
        con,
        """
        SELECT sl.site_id,
               sl.language_code,
               sl.og_default_image_media_id AS media_id,
               COALESCE(NULLIF(TRIM(mal.alt_text), ''), NULL) AS alt_text
        FROM site_localizations sl
        JOIN media_assets ma
          ON ma.id = sl.og_default_image_media_id
         AND ma.site_id = sl.site_id
         AND ma.lifecycle_status != 'deleted'
        LEFT JOIN media_asset_localizations mal
          ON mal.media_id = sl.og_default_image_media_id
         AND mal.language_code = sl.language_code
        WHERE sl.og_default_image_media_id IS NOT NULL
        ORDER BY sl.site_id, sl.language_code
        """,
    )
    if execute:
        with con:
            con.execute(
                """
                DELETE FROM media_usages
                WHERE resource_type = 'system'
                  AND resource_id = 0
                  AND field_key = ?
                  AND usage_context = 'opengraph'
                  AND NOT EXISTS (
                      SELECT 1
                      FROM site_localizations sl
                      WHERE sl.site_id = media_usages.site_id
                        AND sl.language_code = media_usages.language_code
                        AND sl.og_default_image_media_id = media_usages.media_id
                  )
                """,
                (field_key,),
            )
            for item in candidates:
                usage_hash = hashlib.sha256(
                    f"{item['site_id']}|{item['media_id']}|system|0|{field_key}|{item['language_code']}|opengraph".encode("utf-8")
                ).hexdigest()
                con.execute(
                    """
                    INSERT INTO media_usages(
                        media_id, site_id, resource_type, resource_id, field_key, language_code, usage_context,
                        source_revision_id, alt_policy, alt_text_snapshot, usage_hash, created_at, updated_at
                    ) VALUES(?, ?, 'system', 0, ?, ?, 'opengraph', NULL, 'required', ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    ON CONFLICT(usage_hash) DO UPDATE SET
                        media_id = excluded.media_id,
                        site_id = excluded.site_id,
                        field_key = excluded.field_key,
                        language_code = excluded.language_code,
                        usage_context = excluded.usage_context,
                        alt_policy = excluded.alt_policy,
                        alt_text_snapshot = excluded.alt_text_snapshot,
                        updated_at = CURRENT_TIMESTAMP
                    """,
                    (item["media_id"], item["site_id"], field_key, item["language_code"], item.get("alt_text"), usage_hash),
                )
    return candidates


def print_section(title: str, items: list[dict[str, Any]]) -> None:
    print(f"\n[{title}] {len(items)}")
    for item in items[:50]:
        print(" - " + ", ".join(f"{k}={v}" for k, v in item.items()))
    if len(items) > 50:
        print(f" ... {len(items) - 50} elements supplementaires")


def main() -> int:
    parser = argparse.ArgumentParser(description="Audite et maintient le workflow media local.")
    parser.add_argument("--json", action="store_true", help="Affiche le rapport en JSON.")
    parser.add_argument("--yes", action="store_true", help="Execute les actions de maintenance demandees.")
    parser.add_argument("--older-than-days", type=int, default=30, help="Age minimal pour les actions sur medias inutilises.")
    parser.add_argument("--mark-unused-delete-pending", action="store_true", help="Marque les medias inutilises comme delete_pending.")
    parser.add_argument("--purge-deleted-files", action="store_true", help="Supprime du disque les fichiers de medias deja marques deleted.")
    parser.add_argument("--sync-config-usages", action="store_true", help="Reconstruit les usages systeme des medias de configuration.")
    args = parser.parse_args()

    con = connect()
    try:
        require_media_schema(con)
        actions: dict[str, Any] = {}
        if args.mark_unused_delete_pending:
            actions["mark_unused_delete_pending"] = mark_unused_delete_pending(con, args.older_than_days, args.yes)
        if args.purge_deleted_files:
            actions["purge_deleted_files"] = purge_deleted_files(con, args.yes)
        if args.sync_config_usages:
            actions["sync_config_usages"] = sync_config_usages(con, args.yes)
        report = audit(con)
        if actions:
            report["actions"] = actions
            report["dry_run"] = not args.yes
    finally:
        con.close()

    if args.json:
        print(json.dumps(report, ensure_ascii=False, indent=2))
    else:
        print("[media maintenance]")
        if report.get("dry_run") is True:
            print("mode: dry-run (ajoutez --yes pour appliquer)")
        for key, value in report["summary"].items():
            print(f"{key}: {value}")
        for key in ["folders", "orphan_folder_assets", "unused_media", "missing_files", "unsafe_paths", "missing_required_alt", "cross_site_references", "missing_tracked_config_usages", "invalid_media_states", "delete_pending", "recent_events"]:
            print_section(key, report[key])
        for key, value in report.get("actions", {}).items():
            print_section("action:" + key, value)

    return 1 if report["orphan_folder_assets"] or report["missing_files"] or report["unsafe_paths"] or report["missing_required_alt"] or report["cross_site_references"] or report["missing_tracked_config_usages"] or report["invalid_media_states"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
