#!/usr/bin/env python3
"""Ajoute un site multisite local dans la base SQLite core.

Ce script est volontairement conservateur : il crée l'identité du site, son
premier domaine canonique, ses langues actives et les réglages natifs minimaux
pour que le runtime public et le backoffice puissent le résoudre.

Exemple :
  python3 tools/python/operations/database/g7_add_site.py \
    --site-key blog \
    --name "Blog" \
    --host blog.example.test \
    --base-path "" \
    --languages fr,en \
    --default-language fr
"""
from __future__ import annotations

import argparse
import json
import re
import sqlite3
from pathlib import Path

BASE = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
CORE_DB = BASE / "storage" / "database" / "core.sqlite"

SITE_KEY_RE = re.compile(r"^[a-z0-9][a-z0-9_-]{1,62}$")
HOST_RE = re.compile(r"^[a-z0-9][a-z0-9.-]*[a-z0-9]$", re.IGNORECASE)


def normalize_base_path(value: str) -> str:
    path = value.strip().rstrip("/")
    if path in ("", "/"):
        return ""
    if not path.startswith("/"):
        path = f"/{path}"
    return path


def parse_languages(raw: str) -> list[str]:
    languages = [item.strip().lower() for item in raw.split(",") if item.strip()]
    return list(dict.fromkeys(languages))


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Ajoute un site multisite dans storage/database/core.sqlite.")
    parser.add_argument("--site-key", required=True, help="Cle stable du site, ex. blog ou shop_fr.")
    parser.add_argument("--name", required=True, help="Nom lisible du site.")
    parser.add_argument("--host", required=True, help="Domaine sans protocole, ex. example.test ou www.example.com.")
    parser.add_argument("--base-path", default="", help="Chemin public optionnel, ex. /blog. Vide pour la racine.")
    parser.add_argument("--scheme", choices=["http", "https"], default="https")
    parser.add_argument("--languages", default="fr", help="Langues actives séparées par des virgules, ex. fr,en,de.")
    parser.add_argument("--default-language", default="fr", help="Langue par défaut du site.")
    parser.add_argument("--force-https", action=argparse.BooleanOptionalAction, default=True)
    parser.add_argument("--inactive", action="store_true", help="Crée le site comme inactif.")
    return parser.parse_args()


def validate_args(args: argparse.Namespace) -> tuple[str, str, str, list[str]]:
    site_key = args.site_key.strip().lower()
    host = args.host.strip().lower().removeprefix("https://").removeprefix("http://").strip("/")
    base_path = normalize_base_path(args.base_path)
    languages = parse_languages(args.languages)
    default_language = args.default_language.strip().lower()

    if not SITE_KEY_RE.match(site_key):
        raise ValueError("--site-key doit contenir uniquement lettres minuscules, chiffres, _ ou -, et commencer par une lettre/chiffre.")
    if not HOST_RE.match(host):
        raise ValueError("--host doit être un domaine sans protocole ni chemin.")
    if default_language not in languages:
        languages.insert(0, default_language)
    return site_key, host, base_path, languages


def ensure_language_exists(con: sqlite3.Connection, code: str) -> None:
    row = con.execute("SELECT 1 FROM languages WHERE code = ? AND is_active = 1", (code,)).fetchone()
    if row is None:
        raise ValueError(f"Langue inconnue ou inactive: {code}. Ajoutez-la d'abord dans la table languages.")


def language_locale(con: sqlite3.Connection, code: str) -> str:
    row = con.execute("SELECT locale FROM languages WHERE code = ?", (code,)).fetchone()
    return str(row[0]) if row and row[0] else code


def url_prefix_for(index: int, code: str, default_language: str) -> str:
    return "" if code == default_language else f"/{code}"


def insert_site(con: sqlite3.Connection, args: argparse.Namespace) -> int:
    site_key, host, base_path, languages = validate_args(args)
    default_language = args.default_language.strip().lower()
    for code in languages:
        ensure_language_exists(con, code)

    con.execute(
        "INSERT INTO sites(site_key, name, default_language_code, is_active) VALUES(?, ?, ?, ?)",
        (site_key, args.name.strip(), default_language, 0 if args.inactive else 1),
    )
    site_id = int(con.execute("SELECT last_insert_rowid()").fetchone()[0])

    for sort_order, code in enumerate(languages, start=1):
        con.execute(
            """
            INSERT INTO site_languages(site_id, language_code, locale, url_prefix, hreflang_code, fallback_language_code, is_default, is_active, sort_order)
            VALUES(?, ?, ?, ?, ?, ?, ?, 1, ?)
            """,
            (
                site_id,
                code,
                language_locale(con, code),
                url_prefix_for(sort_order, code, default_language),
                language_locale(con, code),
                None if code == default_language else default_language,
                1 if code == default_language else 0,
                sort_order,
            ),
        )
        con.execute(
            """
            INSERT INTO site_localizations(site_id, language_code, site_title, baseline, footer_text, default_meta_title_suffix, default_meta_description)
            VALUES(?, ?, ?, ?, ?, ?, ?)
            """,
            (
                site_id,
                code,
                args.name.strip(),
                "CMS éditorial SEO-first" if code == "fr" else "SEO-first editorial CMS",
                "Publication propre, runtime léger." if code == "fr" else "Clean publishing, lightweight runtime.",
                f" · {args.name.strip()}",
                "Un site éditorial multisite et multilingue." if code == "fr" else "A multisite and multilingual editorial website.",
            ),
        )

    con.execute(
        """
        INSERT INTO site_domains(site_id, host, base_path, scheme, is_primary, is_active, enforce_https, canonical_host_strategy)
        VALUES(?, ?, ?, ?, 1, 1, ?, 'primary')
        """,
        (site_id, host, base_path, args.scheme, 1 if args.force_https else 0),
    )

    settings = {
        "seo": {
            "robots": "index,follow",
            "sitemap_enabled": True,
            "canonical_enabled": True,
            "redirect_to_primary_host": True,
            "force_https": bool(args.force_https),
            "apple_touch_icon_required": True,
            "social_share_enabled": True,
            "single_h1_policy": True,
            "title_max_length": 60,
            "description_max_length": 160,
        },
        "backoffice": {"admin_ui_language_code": "fr"},
        "public_ui": {"show_login_shortcut": False, "show_site_title_in_header": True, "active_theme_key": "default", "body_font_family": "system", "heading_font_family": "system", "main_heading_font_family": "heading", "main_heading_letter_spacing": "normal", "color_background": "#fbfcfe", "color_surface": "#ffffff", "color_text": "#102033", "color_muted": "#627086", "color_primary": "#1b5fc1", "color_accent": "#ffb21e"},
        "media": {
            "max_upload_mb": 12,
            "allowed_mime_types": ["image/jpeg", "image/png", "image/webp", "image/gif", "video/mp4", "video/webm", "audio/mpeg", "audio/mp4", "audio/ogg", "application/pdf"],
            "auto_generate_variants": True,
            "variant_sets": {"content": [480, 768, 1024, 1280, 1600], "hero": [640, 960, 1280, 1920], "open_graph": ["1200x630"]},
            "require_alt_text": True,
            "default_folder_key": "general",
            "logo_media_id": None,
            "favicon_media_id": None,
            "default_social_image_media_id": None,
            "social_image_policy": {"required_width": 1200, "required_height": 630, "variant_key": "og_1200x630"},
        },
        "articles": {
            "detail_show_published_date": True,
            "detail_show_author": True,
            "detail_show_updated_date": False,
            "detail_show_type": True,
            "detail_include_author_in_schema": True,
            "detail_date_format": "medium",
        },
        "relations": {
            "allow_cross_type_relations": True,
            "max_related_items": 24,
            "enable_bidirectional_hints": True,
            "allowed_relation_types": ["related", "parent", "child", "featured_media"],
            "media_storage": {
                "driver": "local",
                "s3": {
                    "endpoint": "",
                    "bucket": "",
                    "region": "us-east-1",
                    "access_key": "",
                    "secret_key": "",
                    "public_base_url": "",
                    "path_prefix": "",
                },
            },
        },
    }
    for namespace, value in settings.items():
        con.execute(
            "INSERT INTO site_settings(site_id, namespace, setting_key, value_json, is_public) VALUES(?, ?, 'defaults', ?, ?)",
            (site_id, namespace, json.dumps(value, ensure_ascii=False, separators=(",", ":")), 1 if namespace in {"seo", "public_ui", "articles"} else 0),
        )

    media_presets = [
        ("content_480", 480, None, "webp", 82, "fit", 10),
        ("content_768", 768, None, "webp", 82, "fit", 20),
        ("content_1024", 1024, None, "webp", 82, "fit", 30),
        ("content_1280", 1280, None, "webp", 82, "fit", 40),
        ("content_1600", 1600, None, "webp", 82, "fit", 50),
        ("hero_640", 640, None, "webp", 82, "fit", 110),
        ("hero_960", 960, None, "webp", 82, "fit", 120),
        ("hero_1280", 1280, None, "webp", 82, "fit", 130),
        ("hero_1920", 1920, None, "webp", 82, "fit", 140),
        ("og_1200x630", 1200, 630, "webp", 82, "crop", 210),
    ]
    con.executemany(
        """
        INSERT INTO media_variant_presets(site_id, preset_key, width, height, format, quality, mode, sort_order, is_active)
        VALUES(?, ?, ?, ?, ?, ?, ?, ?, 1)
        ON CONFLICT(site_id, preset_key) DO UPDATE SET
            width=excluded.width,
            height=excluded.height,
            format=excluded.format,
            quality=excluded.quality,
            mode=excluded.mode,
            sort_order=excluded.sort_order,
            is_active=1,
            updated_at=CURRENT_TIMESTAMP
        """,
        [(site_id, *preset) for preset in media_presets],
    )

    return site_id


def main() -> int:
    args = parse_args()
    if not CORE_DB.exists():
        raise SystemExit(f"Base introuvable: {CORE_DB}. Lancez d'abord tools/python/operations/database/a_db_init.py.")

    con = sqlite3.connect(CORE_DB)
    con.execute("PRAGMA foreign_keys = ON")
    try:
        with con:
            site_id = insert_site(con, args)
    finally:
        con.close()

    print(f"Site créé: id={site_id}, key={args.site_key.strip().lower()}, host={args.host.strip().lower()}, base_path={normalize_base_path(args.base_path) or '/'}")
    print("Étape suivante: créer/publier les pages du nouveau site depuis le backoffice, puis reconstruire les projections si nécessaire.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
