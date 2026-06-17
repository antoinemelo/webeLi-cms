#!/usr/bin/env python3
"""Normalise les projections post-seed sans casser le contrat atomique.

Ce script supprimait historiquement les lignes `search_documents` associées à des
pages `noindex`. Depuis le pipeline public atomique, ce serait une erreur:
`search_documents` est une projection critique et doit conserver une ligne par
publication. Les résultats publics restent filtrés à la lecture via
`seo_metadata.meta_robots`.

Le script effectue aussi un balayage idempotent des snapshots publics pour
supprimer les chemins médias absolus issus d'anciens seeds SQL. Après b0/b1/b2,
les médias publiés doivent toujours être des URLs publiques, par exemple
`/mod/storage/media/...`, jamais `/home/.../storage/media/...`.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import sqlite3
import sys
from pathlib import Path
from typing import Any

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
CORE_DB = ROOT / "storage" / "database" / "core.sqlite"

SELECT_NOINDEX_SQL = """
SELECT sd.id, sd.resource_type, sd.resource_id, sd.language_code, sd.path, sm.meta_robots
FROM search_documents sd
JOIN seo_metadata sm
  ON sm.site_id = sd.site_id
 AND sm.resource_type = sd.resource_type
 AND sm.resource_id = sd.resource_id
 AND sm.language_code = sd.language_code
WHERE LOWER(COALESCE(sm.meta_robots, 'index,follow')) LIKE '%noindex%'
ORDER BY sd.id
"""

URL_KEYS = {"src", "image_src", "poster", "og_image_src", "twitter_image_src", "url"}
SRCSET_KEYS = {"srcset", "image_srcset", "poster_srcset"}


def expected_app_base_path(value: str | None) -> str:
    raw = (value or os.getenv("APP_BASE_PATH") or "/mod").strip()
    if raw in {"", "/"}:
        return ""
    return "/" + raw.strip("/")


def is_internal_storage_media_path(value: str) -> bool:
    path = str(value or "").strip().replace("\\", "/")
    if path == "" or re.match(r"^https?://", path, re.I) or path.startswith("//") or path.startswith("data:"):
        return False
    return "/storage/" in path or "storage/media/" in path or path.startswith("storage/")


def public_media_url(value: str, app_base_path: str) -> str:
    path = str(value or "").strip().replace("\\", "/")
    if path == "" or re.match(r"^https?://", path, re.I) or path.startswith("//") or path.startswith("data:"):
        return path
    base = expected_app_base_path(app_base_path)
    if base and (path == base or path.startswith(base + "/")):
        return path
    for needle in ("/storage/media/", "/storage/"):
        pos = path.rfind(needle)
        if pos >= 0:
            suffix = path[pos:]
            return (base + suffix) if base else suffix
    for needle in ("storage/media/", "storage/"):
        pos = path.rfind(needle)
        if pos >= 0:
            suffix = "/" + path[pos:].lstrip("/")
            return (base + suffix) if base else suffix
    suffix = "/storage/media/" + path.lstrip("/")
    return (base + suffix) if base else suffix


def sanitize_srcset(value: str, app_base_path: str) -> str:
    items: list[str] = []
    for item in value.split(","):
        item = item.strip()
        if not item:
            continue
        parts = item.split(None, 1)
        url = parts[0]
        descriptor = parts[1] if len(parts) > 1 else ""
        if is_internal_storage_media_path(url):
            url = public_media_url(url, app_base_path)
        items.append((url + (" " + descriptor if descriptor else "")).strip())
    return ", ".join(items)


def sanitize_media_urls(value: Any, key: str = "", app_base_path: str = "/mod") -> Any:
    if isinstance(value, dict):
        return {str(k): sanitize_media_urls(v, str(k), app_base_path) for k, v in value.items()}
    if isinstance(value, list):
        return [sanitize_media_urls(v, key, app_base_path) for v in value]
    if not isinstance(value, str) or value == "":
        return value
    if key in SRCSET_KEYS:
        return sanitize_srcset(value, app_base_path)
    if key in URL_KEYS and is_internal_storage_media_path(value):
        return public_media_url(value, app_base_path)
    return value


def parse_json_object(raw: str | None) -> dict[str, Any]:
    try:
        data = json.loads(raw or "{}")
    except json.JSONDecodeError:
        return {}
    return data if isinstance(data, dict) else {}


def parse_json_list(raw: str | None) -> list[Any]:
    try:
        data = json.loads(raw or "[]")
    except json.JSONDecodeError:
        return []
    return data if isinstance(data, list) else []


def dump_json(value: Any) -> str:
    return json.dumps(value, ensure_ascii=False, separators=(",", ":"))


def sanitize_public_snapshots(con: sqlite3.Connection, app_base_path: str, dry_run: bool) -> int:
    fixed = 0
    con.row_factory = sqlite3.Row
    rows = con.execute(
        "SELECT id, document_json, blocks_json FROM public_content_snapshots ORDER BY id"
    ).fetchall()
    for row in rows:
        document = parse_json_object(row["document_json"])
        blocks = parse_json_list(row["blocks_json"])
        sanitized_document = sanitize_media_urls(document, app_base_path=app_base_path)
        sanitized_blocks = sanitize_media_urls(blocks, app_base_path=app_base_path)
        if sanitized_document == document and sanitized_blocks == blocks:
            continue
        fixed += 1
        if not dry_run:
            con.execute(
                """
                UPDATE public_content_snapshots
                SET document_json=?, blocks_json=?, projected_at=CURRENT_TIMESTAMP
                WHERE id=?
                """,
                (dump_json(sanitized_document), dump_json(sanitized_blocks), int(row["id"])),
            )
    if fixed and not dry_run:
        con.commit()
    return fixed


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Normalise les projections publiques post-seed.")
    parser.add_argument("--dry-run", action="store_true", help="Affiche ce qui serait corrigé sans modifier la base.")
    parser.add_argument("--app-base-path", default=os.getenv("APP_BASE_PATH", "/mod"), help="Préfixe applicatif public attendu, par défaut: /mod.")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    if not CORE_DB.exists():
        print(f"ERREUR: base introuvable: {CORE_DB}", file=sys.stderr)
        return 2

    con = sqlite3.connect(str(CORE_DB))
    con.row_factory = sqlite3.Row
    try:
        fixed = sanitize_public_snapshots(con, expected_app_base_path(args.app_base_path), args.dry_run)
        rows = con.execute(SELECT_NOINDEX_SQL).fetchall()
        if fixed:
            action = "seraient normalisés" if args.dry_run else "normalisés"
            print(f"OK: {fixed} snapshot(s) public(s) avec URL média {action}.")
        else:
            print("OK: aucune URL média publiée absolue à normaliser dans les snapshots.")
        print(
            "OK: contrat atomique respecté; search_documents conserve les pages noindex "
            "et le runtime public les filtre via seo_metadata.meta_robots."
        )
        if rows:
            print(f"Documents noindex conservés dans search_documents: {len(rows)}")
            for row in rows:
                print(
                    f"- search_documents.{row['id']}: "
                    f"{row['resource_type']}#{row['resource_id']} "
                    f"{row['language_code']} {row['path']} ({row['meta_robots']})"
                )
        return 0
    except sqlite3.Error as exc:
        print(f"ERREUR: diagnostic impossible: {exc}", file=sys.stderr)
        return 1
    finally:
        con.close()


if __name__ == "__main__":
    raise SystemExit(main())
