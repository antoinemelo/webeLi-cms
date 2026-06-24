#!/usr/bin/env python3
"""Seed local de developpement.

Responsabilite stricte : injecter les donnees par defaut, sans creer les
structures. Le jeu par defaut est stocke dans database/seeds/default/ et
correspond exactement aux bases SQLite livrees dans database.zip, hors tables internes FTS regenerees par SQLite.

Les schemas restent la responsabilite de a_db_init.py et des fichiers SQL de
database/. Le seed ne doit donc jamais porter de DDL structurel durable.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import shutil
import sqlite3
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

BASE = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
if str(BASE) not in sys.path:
    sys.path.insert(0, str(BASE))

from tools.python.lib.processes import cms_subprocess_env
CORE_DB = BASE / "storage" / "database" / "core.sqlite"
IAM_DB = BASE / "storage" / "database" / "iam.sqlite"
FORMS_DB = BASE / "storage" / "database" / "forms.sqlite"
COOKIES_DB = BASE / "storage" / "database" / "cookies.sqlite"
AI_DB = BASE / "storage" / "database" / "ai.sqlite"
CONSOLE = BASE / "backend" / "bin" / "console"
DEFAULT_SEED_DIR = BASE / "database" / "seeds" / "default"
CORE_DEFAULT_SEED = DEFAULT_SEED_DIR / "core_default_seed.sql"
IAM_DEFAULT_SEED = DEFAULT_SEED_DIR / "iam_default_seed.sql"
CORE_MULTISITE_CONTRACT_SEED = DEFAULT_SEED_DIR / "core_multisite_contract_seed.sql"
IAM_MULTISITE_CONTRACT_SEED = DEFAULT_SEED_DIR / "iam_multisite_contract_seed.sql"
FORMS_DEFAULT_SEED = DEFAULT_SEED_DIR / "forms_default_seed.sql"
COOKIES_DEFAULT_SEED = DEFAULT_SEED_DIR / "cookies_default_seed.sql"
AI_DEFAULT_SEED = DEFAULT_SEED_DIR / "ai_default_seed.sql"
CORE_REFERENCE_SEED = BASE / "database" / "seeds" / "core_seed.sql"
IAM_REFERENCE_SEED = BASE / "database" / "seeds" / "iam_seed.sql"
COOKIES_REFERENCE_SEED = BASE / "database" / "seeds" / "cookies_seed.sql"
NATIVE_BLUEPRINTS_SCRIPT = BASE / "tools" / "python" / "operations" / "database" / "g1_seed_native_blueprints.py"
CORE_MIGRATION_DIR = BASE / "database" / "migrations" / "core"
SQLITE_BUSY_TIMEOUT_MS = 5000


def configure_sqlite(connection: sqlite3.Connection) -> None:
    connection.execute(f"PRAGMA busy_timeout = {SQLITE_BUSY_TIMEOUT_MS}")
    connection.execute("PRAGMA foreign_keys = ON")
    connection.execute("PRAGMA journal_mode = WAL")


def connect_sqlite(db_path: Path) -> sqlite3.Connection:
    connection = sqlite3.connect(db_path)
    configure_sqlite(connection)
    return connection


def now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")


def hash_pw(_password: str) -> str:
    # Hash PHP PASSWORD_DEFAULT pour 'admin123'.
    return "$2y$12$IQ1A5lwkoWrPfypHOTGlT.aPMvbImII5mHeLb/wC4b/DaCmUyvLba"


def ensure_databases_exist() -> None:
    missing = [str(path) for path in (CORE_DB, IAM_DB, FORMS_DB, COOKIES_DB, AI_DB) if not path.exists()]
    if missing:
        raise RuntimeError(
            "Base(s) SQLite introuvable(s): "
            + ", ".join(missing)
            + ". Lancez d'abord: python3 tools/python/operations/database/a_db_init.py"
        )


def run_seed_file(connection: sqlite3.Connection, sql_path: Path) -> None:
    if not sql_path.exists():
        raise FileNotFoundError(f"Seed SQL introuvable: {sql_path}")
    sql = sql_path.read_text(encoding="utf-8").strip()
    if sql:
        connection.executescript(sql)
        connection.commit()

def enforce_modules_permissions_policy(iam: sqlite3.Connection) -> None:
    """Synchronise la politique IAM native des modules.

    Les droits de gouvernance `modules.*` sont réservés au rôle super_admin.
    Le rôle admin conserve uniquement l'exploitation du module Forms
    (`forms.read` / `forms.manage`) afin de gérer les formulaires.
    """
    i = iam.cursor()
    for key, name, description in [
        ("modules.read", "Lire les modules", "Consulter le catalogue, l’état, les dépendances et le diagnostic des modules."),
        ("modules.manage", "Gérer les modules", "Installer, activer, désactiver, migrer et diagnostiquer les modules."),
        ("forms.read", "Lire les formulaires", "Lire formulaires et soumissions."),
        ("forms.manage", "Gérer les formulaires", "Créer et configurer les formulaires."),
    ]:
        i.execute(
            """
            INSERT INTO iam_permissions(permission_key, name, description) VALUES(?, ?, ?)
            ON CONFLICT(permission_key) DO UPDATE SET name=excluded.name, description=excluded.description
            """,
            (key, name, description),
        )

    i.execute(
        """
        DELETE FROM iam_role_permissions
        WHERE role_id IN (SELECT id FROM iam_roles WHERE role_key='admin')
          AND permission_id IN (SELECT id FROM iam_permissions WHERE permission_key IN ('modules.read','modules.manage'))
        """
    )
    i.execute(
        """
        INSERT OR IGNORE INTO iam_role_permissions(role_id, permission_id)
        SELECT r.id, p.id
        FROM iam_roles r
        JOIN iam_permissions p ON p.permission_key IN ('forms.read','forms.manage')
        WHERE r.role_key='admin'
        """
    )
    i.execute(
        """
        INSERT OR IGNORE INTO iam_role_permissions(role_id, permission_id)
        SELECT r.id, p.id
        FROM iam_roles r
        JOIN iam_permissions p ON p.permission_key IN ('modules.read','modules.manage','forms.read','forms.manage')
        WHERE r.role_key='super_admin'
        """
    )
    iam.commit()


def default_seed_files_available() -> bool:
    return all(
        path.exists()
        for path in (CORE_DEFAULT_SEED, IAM_DEFAULT_SEED, FORMS_DEFAULT_SEED, COOKIES_DEFAULT_SEED, AI_DEFAULT_SEED)
    )


def ensure_seed_support_tables(connection: sqlite3.Connection, seed_path: Path) -> None:
    """Garantit les tables techniques attendues par les snapshots SQL.

    Certains jeux de donnees exportes contiennent des DELETE/INSERT sur
    schema_migrations. Cette table peut etre absente si le seed est execute
    sur une base creee avec un schema plus ancien ou module par module. Le seed
    doit rester robuste et ne pas echouer avant meme d'injecter les donnees.
    """
    if not seed_path.exists():
        return
    try:
        sql = seed_path.read_text(encoding="utf-8")
    except OSError:
        return
    if "schema_migrations" not in sql:
        return
    connection.execute(
        """
        CREATE TABLE IF NOT EXISTS schema_migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            migration TEXT NOT NULL UNIQUE,
            migrated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
        """
    )
    connection.commit()


def seed_from_default_sql_files() -> bool:
    """Injecte le jeu par defaut versionne dans database/seeds/default/.

    Retourne True si ces fichiers existent et ont ete appliques. Les fichiers
    default sont le seed natif de depart: ils doivent rester alignes sur
    database.zip. Le code de seed programmatique historique reste conserve en
    repli pour les branches qui n'auraient pas encore les snapshots SQL de seed.
    """
    if not default_seed_files_available():
        return False

    for db_path, seed_path in [
        (CORE_DB, CORE_DEFAULT_SEED),
        (IAM_DB, IAM_DEFAULT_SEED),
        (FORMS_DB, FORMS_DEFAULT_SEED),
        (COOKIES_DB, COOKIES_DEFAULT_SEED),
        (AI_DB, AI_DEFAULT_SEED),
    ]:
        con = connect_sqlite(db_path)
        try:
            ensure_seed_support_tables(con, seed_path)
            run_seed_file(con, seed_path)
        finally:
            con.close()

    # Scenario contractuel multisite/multilingue. Il est applique apres le
    # snapshot database.zip pour prouver l'isolation site/language/media/menu
    # sans devoir regenerer manuellement le gros seed par defaut.
    for db_path, seed_path in [
        (CORE_DB, CORE_MULTISITE_CONTRACT_SEED),
        (IAM_DB, IAM_MULTISITE_CONTRACT_SEED),
    ]:
        if not seed_path.exists():
            continue
        con = connect_sqlite(db_path)
        try:
            run_seed_file(con, seed_path)
        finally:
            con.close()

    with connect_sqlite(IAM_DB) as iam:
        enforce_modules_permissions_policy(iam)

    repair_multisite_primary_domains_for_webe_li_deployment()
    repair_published_revision_documents_for_projection_inputs()
    with connect_sqlite(CORE_DB) as con:
        cur = con.cursor()
        rebuild_seed_public_projections(cur)
        rebuild_seed_taxonomy_projections(cur)
        con.commit()
    sanitize_existing_public_media_urls()
    return True



def fetch_required_id(cur: sqlite3.Cursor, query: str, params: tuple[object, ...], label: str) -> int:
    row = cur.execute(query, params).fetchone()
    if row is None:
        raise RuntimeError(
            f"Donnee de reference manquante: {label}. "
            "Relancez: python3 tools/python/operations/database/a_db_init.py --with-seed --seed-skip-projections"
        )
    return int(row[0])



def migration_log_columns(connection: sqlite3.Connection) -> set[str]:
    return {row[1] for row in connection.execute("PRAGMA table_info(schema_migrations)").fetchall()}


def ensure_migration_log(connection: sqlite3.Connection) -> None:
    connection.execute(
        """
        CREATE TABLE IF NOT EXISTS schema_migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            migration TEXT NOT NULL UNIQUE,
            migrated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
        """
    )
    columns = migration_log_columns(connection)
    if "migration" not in columns:
        connection.execute("ALTER TABLE schema_migrations ADD COLUMN migration TEXT")
        columns.add("migration")
    if "migrated_at" not in columns:
        connection.execute("ALTER TABLE schema_migrations ADD COLUMN migrated_at TEXT")
        columns.add("migrated_at")
    if "migration_name" in columns:
        connection.execute("UPDATE schema_migrations SET migration = migration_name WHERE migration IS NULL OR migration = ''")
    if "applied_at" in columns:
        connection.execute("UPDATE schema_migrations SET migrated_at = applied_at WHERE migrated_at IS NULL OR migrated_at = ''")
    connection.execute("UPDATE schema_migrations SET migrated_at = CURRENT_TIMESTAMP WHERE migrated_at IS NULL OR migrated_at = ''")
    connection.commit()


def applied_migrations(connection: sqlite3.Connection, scope: str) -> set[str]:
    columns = migration_log_columns(connection)
    names = set()
    if "migration" in columns:
        names.update(
            row[0]
            for row in connection.execute("SELECT migration FROM schema_migrations WHERE migration IS NOT NULL AND migration != ''").fetchall()
        )
    if {"scope", "migration_name"}.issubset(columns):
        names.update(
            row[0]
            for row in connection.execute(
                "SELECT migration_name FROM schema_migrations WHERE scope=? AND migration_name IS NOT NULL AND migration_name != ''",
                (scope,),
            ).fetchall()
        )
    return names


def mark_migration_applied(connection: sqlite3.Connection, scope: str, name: str) -> None:
    columns = migration_log_columns(connection)
    if {"scope", "migration_name"}.issubset(columns):
        connection.execute(
            """
            INSERT OR IGNORE INTO schema_migrations(scope, migration_name, migration, applied_at, migrated_at)
            VALUES(?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            """,
            (scope, name, name),
        )
    else:
        connection.execute(
            "INSERT OR IGNORE INTO schema_migrations(migration, migrated_at) VALUES(?, CURRENT_TIMESTAMP)",
            (name,),
        )


def apply_core_migrations(connection: sqlite3.Connection) -> None:
    """Applique les migrations core avant le seed si la base existe deja.

    Le journal reste compatible avec le migrateur PHP (`migration`) et avec
    l'ancien format Python intermediaire (`scope`, `migration_name`) si celui-ci
    existe deja dans une base locale.
    """
    if not CORE_MIGRATION_DIR.exists():
        return
    ensure_migration_log(connection)
    files = sorted(CORE_MIGRATION_DIR.glob("*.sql"))
    applied = applied_migrations(connection, "core")
    if not applied:
        existing_schema_tables = {
            row[0] for row in connection.execute("SELECT name FROM sqlite_master WHERE type='table'").fetchall()
        }
        if {"languages", "routes", "search_documents"}.issubset(existing_schema_tables):
            for path in files:
                if path.name.startswith(("0001_", "0002_", "0003_")):
                    mark_migration_applied(connection, "core", path.name)
                    applied.add(path.name)
            connection.commit()

    for path in files:
        if path.name in applied:
            continue
        sql = path.read_text(encoding="utf-8").strip()
        if sql:
            connection.executescript(sql)
        mark_migration_applied(connection, "core", path.name)
    connection.commit()


def ensure_native_content_fields(connection: sqlite3.Connection) -> None:
    """Ajoute les champs natifs utilisés par l’index d’articles et l’éditeur.

    Le seed peut être exécuté sur une base créée avec un ancien core_seed.sql :
    ces champs doivent alors être présents dans le schéma éditorial avant toute
    sauvegarde de brouillon afin que la validation payload reste stricte.
    """
    c = connection.cursor()
    page_type = c.execute("SELECT id FROM content_types WHERE type_key='page'").fetchone()
    article_type = c.execute("SELECT id FROM content_types WHERE type_key='article'").fetchone()
    if not page_type or not article_type:
        return
    page_type_id = int(page_type[0])
    article_type_id = int(article_type[0])

    groups = [
        (article_type_id, "native_article_publication", "Publication publique", "content", 20),
        (page_type_id, "native_article_index", "Liste d’articles", "content", 20),
    ]
    for content_type_id, group_key, label, tab_key, sort_order in groups:
        c.execute(
            "INSERT OR IGNORE INTO field_groups(content_type_id, group_key, label, tab_key, sort_order) VALUES(?,?,?,?,?)",
            (content_type_id, group_key, label, tab_key, sort_order),
        )

    article_group_id = c.execute(
        "SELECT id FROM field_groups WHERE content_type_id=? AND group_key=?",
        (article_type_id, "native_article_publication"),
    ).fetchone()[0]
    page_group_id = c.execute(
        "SELECT id FROM field_groups WHERE content_type_id=? AND group_key=?",
        (page_type_id, "native_article_index"),
    ).fetchone()[0]

    fields = [
        (article_type_id, article_group_id, "display_published_at", "Date affichée", "datetime", "datetime", "Date publique utilisée pour le tri et les métadonnées des articles. Rendu dans le panneau Identité éditoriale.", None, None, 1, 1, 10),
        (article_type_id, article_group_id, "author_name", "Auteur", "text", "text", "Nom public de l’auteur ou de l’équipe éditoriale. Rendu dans le panneau Identité éditoriale.", None, None, 1, 1, 20),
        (article_type_id, article_group_id, "article_detail_use_custom_display_settings", "Personnaliser l’affichage de cet article", "boolean", "toggle", "Champ système masqué : la politique visible se configure dans Configuration > Articles.", "false", None, 1, 1, 30),
        (article_type_id, article_group_id, "article_detail_show_published_date", "Afficher la date de publication", "boolean", "toggle", "Champ système masqué : la politique visible se configure dans Configuration > Articles.", "true", None, 1, 1, 40),
        (article_type_id, article_group_id, "article_detail_show_author", "Afficher l’auteur", "boolean", "toggle", "Champ système masqué : la politique visible se configure dans Configuration > Articles.", "true", None, 1, 1, 50),
        (article_type_id, article_group_id, "article_detail_show_updated_date", "Afficher la date de mise à jour", "boolean", "toggle", "Champ système masqué : la politique visible se configure dans Configuration > Articles.", "false", None, 1, 1, 60),
        (article_type_id, article_group_id, "article_detail_show_type", "Afficher le type de contenu", "boolean", "toggle", "Champ système masqué : la politique visible se configure dans Configuration > Articles.", "true", None, 1, 1, 70),
        (article_type_id, article_group_id, "article_detail_date_format", "Format de date personnalisé", "select", "select", "Champ système masqué : la politique visible se configure dans Configuration > Articles.", '"medium"', '{"in":["short","medium","long","iso"]}', 1, 1, 80),
        (article_type_id, article_group_id, "article_detail_include_author_in_schema", "Conserver l’auteur dans le JSON-LD", "boolean", "toggle", "Champ système masqué : option B SEO, pilotée par Configuration > Articles.", "true", None, 1, 1, 90),
        (page_type_id, page_group_id, "article_limit", "Articles par page", "number", "number", "Nombre d’articles affichés sur la page d’index.", "6", '{"integer":true,"min":1,"max":48}', 1, 1, 10),
        (page_type_id, page_group_id, "tag_limit", "Tags affichés", "number", "number", "Nombre maximal de tags proposés dans les filtres.", "12", '{"integer":true,"min":1,"max":50}', 1, 1, 20),
        (page_type_id, page_group_id, "article_show_published_date", "Afficher la date de publication", "boolean", "toggle", "Contrôle l’affichage de la date sur les cartes d’articles.", "true", None, 1, 1, 30),
        (page_type_id, page_group_id, "article_show_author", "Afficher l’auteur", "boolean", "toggle", "Contrôle l’affichage de l’auteur sur les cartes d’articles.", "false", None, 1, 1, 40),
    ]
    for row in fields:
        c.execute(
            """
            INSERT INTO fields(content_type_id, group_id, field_key, label, field_type, storage_mode, interface_key, help_text, default_value_json, validation_json, is_localized, is_hidden, sort_order)
            VALUES(?,?,?,?,?,'json',?,?,?,?,?,?,?)
            ON CONFLICT(content_type_id, field_key) DO UPDATE SET
                group_id=excluded.group_id,
                label=excluded.label,
                field_type=excluded.field_type,
                storage_mode='json',
                interface_key=excluded.interface_key,
                help_text=excluded.help_text,
                default_value_json=excluded.default_value_json,
                validation_json=excluded.validation_json,
                is_localized=excluded.is_localized,
                is_hidden=excluded.is_hidden,
                sort_order=excluded.sort_order
            """,
            row,
        )
    connection.commit()


def ensure_article_block_layout(connection: sqlite3.Connection) -> None:
    """Garantit que les articles utilisent aussi la composition par blocs.

    Les articles éditoriaux ont besoin du même éditeur Structure que les pages :
    titre/slug + champs de publication + blocs natifs. Ce correctif rend le
    seed from scratch cohérent avec le front article.twig, qui lit déjà
    document.blocks / content.blocks.
    """
    c = connection.cursor()
    c.execute("UPDATE content_types SET has_layout=1 WHERE type_key='article'")
    rows = c.execute(
        """
        SELECT bv.id, bv.schema_json, bv.ui_schema_json
        FROM blueprint_versions bv
        JOIN blueprints b ON b.id = bv.blueprint_id
        WHERE b.blueprint_key='article' AND bv.status='active'
        """
    ).fetchall()
    for version_id, schema_json, ui_schema_json in rows:
        try:
            schema = json.loads(schema_json or '{}')
        except Exception:
            schema = {}
        try:
            ui_schema = json.loads(ui_schema_json or '{}')
        except Exception:
            ui_schema = {}
        capabilities = schema.setdefault('capabilities', {})
        if isinstance(capabilities, dict):
            capabilities['layout'] = True
        ui_schema['blocks_enabled'] = True
        c.execute(
            "UPDATE blueprint_versions SET schema_json=?, ui_schema_json=? WHERE id=?",
            (_seed_dump_json(schema), _seed_dump_json(ui_schema), int(version_id)),
        )
    connection.commit()


def ensure_reference_data(core: sqlite3.Connection, iam: sqlite3.Connection) -> None:
    """Garantit les donnees structurelles necessaires au seed demo.

    b_db_seed.py reste responsable des donnees de demonstration, mais il doit
    etre robuste si l'utilisateur a cree les schemas avec --no-reference-seed ou
    a importe une base incomplete. Les seeds SQL de reference restent la source
    unique pour les langues, sites, modules, content types, roles et permissions.
    """
    apply_core_migrations(core)

    c = core.cursor()
    i = iam.cursor()

    has_main_site = c.execute("SELECT 1 FROM sites WHERE site_key='main'").fetchone() is not None
    has_page_type = c.execute("SELECT 1 FROM content_types WHERE type_key='page'").fetchone() is not None
    has_article_type = c.execute("SELECT 1 FROM content_types WHERE type_key='article'").fetchone() is not None
    if not (has_main_site and has_page_type and has_article_type):
        run_seed_file(core, CORE_REFERENCE_SEED)
    ensure_native_content_fields(core)
    ensure_article_block_layout(core)

    main_site_id_row = c.execute("SELECT id FROM sites WHERE site_key='main'").fetchone()
    if main_site_id_row and c.execute("SELECT 1 FROM site_domains WHERE site_id=? AND host=? AND base_path=?", (main_site_id_row[0], "webe.li", "")).fetchone() is None:
        c.execute("INSERT INTO site_domains(site_id, host, base_path, scheme, is_primary, is_active, enforce_https) VALUES(?, ?, ?, ?, 1, 1, 1)", (main_site_id_row[0], "webe.li", "", "https"))
        core.commit()

    has_super_admin = i.execute("SELECT 1 FROM iam_roles WHERE role_key='super_admin'").fetchone() is not None
    required_permissions = {
        'content.read', 'content.create', 'content.update', 'content.revisions.save', 'content.revisions.restore', 'content.revisions.prune', 'content.publish', 'content.unpublish', 'content.archive', 'content.delete', 'content.preview',
        'media.read', 'media.upload', 'media.update', 'media.delete',
        'taxonomy.read', 'taxonomy.manage',
        'menu.read', 'menu.manage',
        'settings.read', 'settings.manage',
        'fields.read', 'fields.manage',
        'blueprints.read', 'blueprints.manage',
        'modules.read', 'modules.manage',
        'forms.read', 'forms.manage',
        'users.read', 'users.manage',
        'roles.read', 'roles.manage',
        'sessions.read', 'sessions.manage',
        'audit.read',
        'seo.read', 'seo.manage', 'seo.simple', 'seo.advanced', 'seo.redirects', 'seo.audit',
        'profile.read', 'profile.update',
        'deployments.read', 'deployments.manage',
    }
    existing_permissions = {
        row[0] for row in i.execute("SELECT permission_key FROM iam_permissions").fetchall()
    }
    if not has_super_admin or not required_permissions.issubset(existing_permissions):
        run_seed_file(iam, IAM_REFERENCE_SEED)



def ensure_cookies_reference_data() -> None:
    """Synchronise la configuration cookies de référence dans la base module."""
    with connect_sqlite(COOKIES_DB) as cookies:
        has_banner = cookies.execute("SELECT 1 FROM cookie_banner_settings LIMIT 1").fetchone() is not None
        has_categories = cookies.execute("SELECT COUNT(*) FROM cookie_categories").fetchone()[0] >= 5
        if not (has_banner and has_categories):
            run_seed_file(cookies, COOKIES_REFERENCE_SEED)



def _seed_is_internal_storage_media_path(value: str) -> bool:
    path = str(value or '').strip().replace('\\', '/')
    if path == '' or re.match(r'^https?://', path, re.I) or path.startswith('//') or path.startswith('data:'):
        return False
    return '/storage/' in path or 'storage/media/' in path or path.startswith('storage/')


def _seed_public_media_url(value: str, app_base_path: str = '/mod') -> str:
    path = str(value or '').strip().replace('\\', '/')
    if path == '' or re.match(r'^https?://', path, re.I) or path.startswith('//') or path.startswith('data:'):
        return path
    base = '/' + app_base_path.strip('/') if app_base_path else ''
    if base and (path == base or path.startswith(base + '/')):
        return path
    for needle in ('/storage/media/', '/storage/'):
        pos = path.rfind(needle)
        if pos >= 0:
            return (base + path[pos:]) if base else path[pos:]
    for needle in ('storage/media/', 'storage/'):
        pos = path.rfind(needle)
        if pos >= 0:
            return (base + '/' + path[pos:]) if base else '/' + path[pos:]
    return path


def _seed_sanitize_srcset(value: str, app_base_path: str = '/mod') -> str:
    items: list[str] = []
    for raw in str(value or '').split(','):
        item = raw.strip()
        if not item:
            continue
        parts = item.split(None, 1)
        url = parts[0]
        descriptor = parts[1] if len(parts) > 1 else ''
        if _seed_is_internal_storage_media_path(url):
            url = _seed_public_media_url(url, app_base_path)
        items.append((url + (' ' + descriptor if descriptor else '')).strip())
    return ', '.join(items)


def _seed_sanitize_media_urls(value: object, key: str = '', app_base_path: str = '/mod') -> object:
    if isinstance(value, dict):
        return {str(k): _seed_sanitize_media_urls(v, str(k), app_base_path) for k, v in value.items()}
    if isinstance(value, list):
        return [_seed_sanitize_media_urls(v, key, app_base_path) for v in value]
    if not isinstance(value, str) or value == '':
        return value
    if key in {'srcset', 'image_srcset', 'poster_srcset'}:
        return _seed_sanitize_srcset(value, app_base_path)
    if key in {'src', 'image_src', 'poster', 'og_image_src', 'twitter_image_src', 'url'} and _seed_is_internal_storage_media_path(value):
        return _seed_public_media_url(value, app_base_path)
    return value

def insert_entry(cur: sqlite3.Cursor, site_id: int, content_type_id: int, entry_key: str, lang: str, title: str, slug: str, summary: str, body: str, iam_user_id: int, meta_title: str | None = None, meta_description: str | None = None) -> None:
    """Insere une entree de demo complete avec references IAM coherentes.

    Les bases core.sqlite et iam.sqlite restent separees; le seed doit donc
    renseigner explicitement les identifiants IAM utilises pour l'auteur, le
    proprietaire et les actions editoriales afin que l'audit inter-base soit OK
    des une base locale recreee de zero.
    """
    ts = now()
    cur.execute(
        """
        INSERT INTO content_entries(
            site_id, content_type_id, entry_key, status, workflow_state,
            author_iam_user_id, owner_iam_user_id,
            created_by_iam_user_id, updated_by_iam_user_id, published_by_iam_user_id,
            created_at, updated_at, published_at
        ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)
        """,
        (
            site_id,
            content_type_id,
            entry_key,
            "published",
            "published",
            iam_user_id,
            iam_user_id,
            iam_user_id,
            iam_user_id,
            iam_user_id,
            ts,
            ts,
            ts,
        ),
    )
    entry_id = cur.lastrowid
    doc = {
        "schema_version": 1,
        "entry_id": entry_id,
        "language_code": lang,
        "content": {
            "title": title,
            "slug": slug,
            "blocks": [
                {
                    "id": f"{entry_key}_body_01",
                    "type": "markdown",
                    "enabled": True,
                    "editorial_status": "published",
                    "label": "Contenu principal",
                    "data": {"text": body},
                    "sort_order": 0,
                }
            ],
        },
        "blocks": [
            {
                "id": f"{entry_key}_body_01",
                "type": "markdown",
                "enabled": True,
                "editorial_status": "published",
                "label": "Contenu principal",
                "data": {"text": body},
                "sort_order": 0,
            }
        ],
        "seo": {
            "meta_title": meta_title or title,
            "meta_description": meta_description or summary,
            "meta_robots": "index,follow",
        },
        "audit": {
            "created_by_iam_user_id": iam_user_id,
            "updated_by_iam_user_id": iam_user_id,
            "published_by_iam_user_id": iam_user_id,
        },
    }
    doc = _seed_sanitize_media_urls(doc)  # type: ignore[assignment]
    document_json = json.dumps(doc, ensure_ascii=False)
    checksum_sha256 = hashlib.sha256(document_json.encode("utf-8")).hexdigest()
    cur.execute(
        """
        INSERT INTO revisions(
            resource_type, resource_id, revision_number, language_code, workflow_status,
            document_json, checksum_sha256, revision_label, summary,
            created_by_iam_user_id, updated_by_iam_user_id, published_by_iam_user_id,
            created_at, updated_at, published_at
        ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        """,
        (
            "content_entry",
            entry_id,
            1,
            lang,
            "published",
            document_json,
            checksum_sha256,
            "Initial",
            summary,
            iam_user_id,
            iam_user_id,
            iam_user_id,
            ts,
            ts,
            ts,
        ),
    )
    rev_id = cur.lastrowid
    cur.execute(
        "INSERT INTO content_entry_localizations(entry_id, language_code, title, draft_slug, draft_status, admin_cache_published_at, updated_at) VALUES(?,?,?,?,?,?,?)",
        (entry_id, lang, title, slug, "ready", ts, ts),
    )
    cur.execute(
        "INSERT INTO content_entry_working_revisions(site_id, entry_id, language_code, working_revision_id, workflow_status, updated_by_iam_user_id, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?)",
        (site_id, entry_id, lang, rev_id, "published", iam_user_id, ts, ts),
    )
    cur.execute(
        "INSERT INTO content_entry_publications(site_id, entry_id, language_code, published_revision_id, workflow_status, published_by_iam_user_id, published_at, updated_at) VALUES(?,?,?,?,?,?,?,?)",
        (site_id, entry_id, lang, rev_id, "published", iam_user_id, ts, ts),
    )



def insert_multilingual_entry(
    cur: sqlite3.Cursor,
    site_id: int,
    content_type_id: int,
    entry_key: str,
    translations: dict[str, dict[str, object]],
    iam_user_id: int,
) -> int:
    """Insere une ressource editoriale unique avec plusieurs langues publiees.

    Contrat SEO : une meme ressource porte ses traductions par langue. Les
    routes publiees d'un meme entry_id deviennent la source fiable des hreflang,
    du sitemap multilingue et du changement de langue public. Le slug reste
    localise : seule la cle d'entree (content_entries.entry_key) relie les
    traductions entre elles.
    """
    ts = now()
    cur.execute(
        """
        INSERT INTO content_entries(
            site_id, content_type_id, entry_key, status, workflow_state,
            author_iam_user_id, owner_iam_user_id,
            created_by_iam_user_id, updated_by_iam_user_id, published_by_iam_user_id,
            created_at, updated_at, published_at
        ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)
        """,
        (
            site_id, content_type_id, entry_key, "published", "published",
            iam_user_id, iam_user_id, iam_user_id, iam_user_id, iam_user_id,
            ts, ts, ts,
        ),
    )
    entry_id = int(cur.lastrowid)

    for revision_number, (lang, data) in enumerate(translations.items(), start=1):
        title = data["title"]
        slug = data["slug"]
        lead = data["lead"]
        main_text = data["main_text"]
        meta_title = data.get("meta_title", title)
        meta_description = data.get("meta_description", lead)
        meta_robots = data.get("meta_robots", "index,follow")
        json_ld = data.get("json_ld", "")
        blocks = data.get("blocks", [])
        if not isinstance(blocks, list):
            blocks = []
        settings = data.get("settings", {})
        if not isinstance(settings, dict):
            settings = {}
        fields = data.get("fields", {})
        if not isinstance(fields, dict):
            fields = {}
        doc = {
            "schema_version": 1,
            "entry_id": entry_id,
            "language_code": lang,
            "content": {
                "title": title,
                "slug": slug,
                "blocks": blocks,
            },
            "settings": settings,
            "fields": fields,
            "seo": {
                "meta_title": meta_title,
                "meta_description": meta_description,
                "meta_robots": meta_robots,
            },
            "blocks": blocks,
            "audit": {
                "created_by_iam_user_id": iam_user_id,
                "updated_by_iam_user_id": iam_user_id,
                "published_by_iam_user_id": iam_user_id,
            },
        }
        if json_ld:
            doc["seo"]["json_ld"] = json_ld
        doc = _seed_sanitize_media_urls(doc)  # type: ignore[assignment]
        document_json = json.dumps(doc, ensure_ascii=False)
        checksum_sha256 = hashlib.sha256(document_json.encode("utf-8")).hexdigest()
        cur.execute(
            """
            INSERT INTO revisions(
                resource_type, resource_id, revision_number, language_code, workflow_status,
                document_json, checksum_sha256, revision_label, summary,
                created_by_iam_user_id, updated_by_iam_user_id, published_by_iam_user_id,
                created_at, updated_at, published_at
            ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            """,
            (
                "content_entry", entry_id, revision_number, lang, "published",
                document_json, checksum_sha256, "Initial", lead,
                iam_user_id, iam_user_id, iam_user_id, ts, ts, ts,
            ),
        )
        rev_id = int(cur.lastrowid)
        cur.execute(
            """
            INSERT INTO content_entry_localizations(
                entry_id, language_code, title, draft_slug,
                translation_status, source_language_code, localized_at,
                updated_by_iam_user_id, draft_status, admin_cache_published_at, updated_at
            ) VALUES(?,?,?,?,?,?,?,?,?,?,?)
            """,
            (entry_id, lang, title, slug, "reviewed", "fr" if lang != "fr" else None, ts, iam_user_id, "ready", ts, ts),
        )
        cur.execute(
            "INSERT INTO content_entry_working_revisions(site_id, entry_id, language_code, working_revision_id, workflow_status, updated_by_iam_user_id, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?)",
            (site_id, entry_id, lang, rev_id, "published", iam_user_id, ts, ts),
        )
        cur.execute(
            "INSERT INTO content_entry_publications(site_id, entry_id, language_code, published_revision_id, workflow_status, published_by_iam_user_id, published_at, updated_at) VALUES(?,?,?,?,?,?,?,?)",
            (site_id, entry_id, lang, rev_id, "published", iam_user_id, ts, ts),
        )
    return entry_id



def public_path(type_key: str, slug: str) -> str:
    slug = (slug or "item").strip("/").lower()
    if type_key == "page" and slug in {"home", "accueil"}:
        return "/"
    if type_key == "page":
        return "/" + slug
    return f"/{type_key}s/{slug}"


def seo_score(title: str, description: str, slug: str) -> int:
    score = 100
    if len(title) < 25:
        score -= 15
    if len(description) < 80:
        score -= 20
    if len(slug) < 3:
        score -= 10
    return max(0, score)


def _seed_dump_json(value: object) -> str:
    return json.dumps(value, ensure_ascii=False, separators=(",", ":"))


def _seed_sanitize_public_snapshot_row(cur: sqlite3.Cursor, snapshot_id: int, document: dict[str, object], blocks: list[object]) -> None:
    """Normalise les URLs médias d'un snapshot public déjà calculé.

    Les seeds SQL historiques peuvent contenir des snapshots pré-calculés avec
    des chemins disque absolus issus d'une machine locale. Le contrat public ne
    doit jamais dépendre de ces chemins: on réécrit donc `document_json` et
    `blocks_json` avec les mêmes règles que la publication PHP.
    """
    sanitized_document = _seed_sanitize_media_urls(document)
    if not isinstance(sanitized_document, dict):
        sanitized_document = document
    sanitized_blocks = _seed_sanitize_media_urls(blocks)
    if not isinstance(sanitized_blocks, list):
        sanitized_blocks = blocks
    cur.execute(
        """
        UPDATE public_content_snapshots
        SET document_json=?, blocks_json=?, projected_at=CURRENT_TIMESTAMP
        WHERE id=?
        """,
        (_seed_dump_json(sanitized_document), _seed_dump_json(sanitized_blocks), snapshot_id),
    )


def rebuild_seed_public_projections(cur: sqlite3.Cursor) -> None:
    """Construit uniquement les projections publiques des donnees de demo.

    Cette fonction n'est pas un second moteur de publication : elle sert au seed
    local lorsque PHP/PDO SQLite n'est pas disponible. Elle ne publie que les
    revisions deja marquees published et recopie leur provenance.
    """
    ts = now()
    for table in ["routes", "seo_metadata", "search_documents", "seo_audit_issues", "public_content_snapshots"]:
        cur.execute(f"DELETE FROM {table} WHERE resource_type='content_entry'")

    rows = cur.execute(
        """
        SELECT ce.id, ce.site_id, ct.type_key, cep.language_code,
               cep.published_revision_id, r.checksum_sha256, r.document_json, r.published_at
        FROM content_entries ce
        JOIN content_types ct ON ct.id = ce.content_type_id
        JOIN content_entry_publications cep ON cep.site_id = ce.site_id
          AND cep.entry_id = ce.id
          AND cep.workflow_status = 'published'
        JOIN revisions r ON r.id = cep.published_revision_id
          AND r.workflow_status = 'published'
        ORDER BY ce.id, cep.language_code
        """
    ).fetchall()
    for row in rows:
        entry_id, site_id, type_key, lang, rev_id, checksum, document_json, published_at = row
        document = json.loads(document_json)
        sanitized_document = _seed_sanitize_media_urls(document)
        if isinstance(sanitized_document, dict):
            document = sanitized_document
            document_json = _seed_dump_json(document)
        content = document.get("content") if isinstance(document.get("content"), dict) else {}
        seo = document.get("seo") if isinstance(document.get("seo"), dict) else {}
        title = str(content.get("title") or "")
        slug = str(content.get("slug") or "item")
        blocks_text = block_plain_text(document.get("blocks") if isinstance(document.get("blocks"), list) else content.get("blocks"))
        summary = str(seo.get("meta_description") or blocks_text[:220])
        path = public_path(str(type_key), slug)
        meta_title = str(seo.get("meta_title") or title)
        meta_description = str(seo.get("meta_description") or summary)
        meta_robots = str(seo.get("meta_robots") or "index,follow")
        score = seo_score(meta_title, meta_description, slug)
        seo_payload = {
            "meta_title": meta_title,
            "meta_description": meta_description,
            "meta_robots": meta_robots,
            "canonical_url": path,
            "seo_score": score,
        }
        if seo.get("json_ld"):
            seo_payload["json_ld"] = seo["json_ld"]
        seo_json = json.dumps(seo_payload, ensure_ascii=False, separators=(",", ":"))
        cur.execute(
            """
            INSERT INTO public_content_snapshots(
                site_id, language_code, resource_type, resource_id, route_path,
                title, slug, blocks_json, block_count, document_json, seo_json,
                source_published_revision_id, source_revision_checksum_sha256,
                published_at, projected_at
            ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            """,
            (site_id, lang, "content_entry", entry_id, path, title, slug, _seed_dump_json(document.get("blocks") or []), len(document.get("blocks") or []), document_json, seo_json, rev_id, checksum, published_at, ts),
        )
        cur.execute(
            """
            INSERT INTO routes(
                site_id, language_code, resource_type, resource_id, route_type, slug,
                full_path, is_primary, is_canonical, status,
                source_published_revision_id, source_revision_checksum_sha256,
                created_at, updated_at
            ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            """,
            (site_id, lang, "content_entry", entry_id, "content", slug, path, 1, 1, "active", rev_id, checksum, ts, ts),
        )
        cur.execute(
            """
            INSERT INTO seo_metadata(
                site_id, resource_type, resource_id, language_code,
                meta_title, meta_description, meta_robots, canonical_url,
                og_title, og_description, twitter_title, twitter_description,
                json_ld, seo_score, source_published_revision_id,
                source_revision_checksum_sha256, updated_at
            ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            """,
            (site_id, "content_entry", entry_id, lang, meta_title, meta_description, meta_robots, path, meta_title, meta_description, meta_title, meta_description, seo.get("json_ld"), score, rev_id, checksum, ts),
        )
        # Contrat de projection critique: search_documents garde une ligne par
        # publication, y compris pour les pages noindex. Le filtrage public de
        # recherche se fait a la lecture via seo_metadata.meta_robots, afin que
        # routes + snapshots + SEO + search_documents restent atomiquement
        # vérifiables et reconstructibles depuis la même révision publiée.
        cur.execute(
            """
            INSERT INTO search_documents(
                site_id, resource_type, resource_id, language_code, path,
                title, summary, search_text, source_published_revision_id,
                source_revision_checksum_sha256, updated_at
            ) VALUES(?,?,?,?,?,?,?,?,?,?,?)
            ON CONFLICT(site_id, resource_type, resource_id, language_code) DO UPDATE SET
                path = excluded.path,
                title = excluded.title,
                summary = excluded.summary,
                search_text = excluded.search_text,
                source_published_revision_id = excluded.source_published_revision_id,
                source_revision_checksum_sha256 = excluded.source_revision_checksum_sha256,
                updated_at = excluded.updated_at
            """,
            (site_id, "content_entry", entry_id, lang, path, title, summary, " ".join((title, summary, blocks_text)).strip(), rev_id, checksum, ts),
        )



def rebuild_seed_taxonomy_projections(cur: sqlite3.Cursor) -> None:
    """Reconstruit les projections publiques des archives taxonomiques natives.

    Les taxonomies n'ont pas de revision éditoriale publiée comme les contenus.
    Leur source de vérité est la ligne localisée `taxonomy_term_localizations`,
    mais elles doivent malgré tout produire les mêmes sorties publiques : route,
    SEO et recherche. Cette fonction garde donc les projections taxonomiques
    cohérentes lors d'une recréation from scratch, y compris après les seeds de
    contrat multisite.
    """
    ts = now()
    for table in ["routes", "seo_metadata", "search_documents", "seo_audit_issues"]:
        cur.execute(f"DELETE FROM {table} WHERE resource_type='taxonomy_term'")

    rows = cur.execute(
        """
        SELECT ttl.site_id, ttl.language_code, ttl.taxonomy_id, ttl.term_id,
               ttl.name, ttl.slug, ttl.full_path, ttl.description,
               ttl.meta_title, ttl.meta_description, tx.taxonomy_key
        FROM taxonomy_term_localizations ttl
        JOIN taxonomies tx ON tx.id = ttl.taxonomy_id AND tx.site_id = ttl.site_id
        JOIN taxonomy_terms tt ON tt.id = ttl.term_id AND tt.taxonomy_id = ttl.taxonomy_id
        WHERE tt.is_active = 1 AND tx.archive_enabled = 1
        ORDER BY ttl.site_id, ttl.language_code, ttl.full_path
        """
    ).fetchall()

    for site_id, lang, taxonomy_id, term_id, name, slug, full_path, description, meta_title, meta_description, taxonomy_key in rows:
        path = str(full_path or '').strip() or f"/{str(slug or term_id).strip('/').lower()}"
        title = str(meta_title or name or slug or taxonomy_key)
        summary = str(meta_description or description or title)
        robots = 'index,follow'
        cur.execute(
            """
            INSERT INTO routes(
                site_id, language_code, resource_type, resource_id, route_type, slug,
                full_path, is_primary, is_canonical, status, created_at, updated_at
            ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)
            """,
            (site_id, lang, 'taxonomy_term', term_id, 'taxonomy', slug, path, 1, 1, 'active', ts, ts),
        )
        cur.execute(
            """
            INSERT INTO seo_metadata(
                site_id, resource_type, resource_id, language_code,
                meta_title, meta_description, meta_robots, canonical_url,
                og_title, og_description, twitter_title, twitter_description,
                seo_score, updated_at
            ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON CONFLICT(site_id, resource_type, resource_id, language_code) DO UPDATE SET
                meta_title=excluded.meta_title,
                meta_description=excluded.meta_description,
                meta_robots=excluded.meta_robots,
                canonical_url=excluded.canonical_url,
                og_title=excluded.og_title,
                og_description=excluded.og_description,
                twitter_title=excluded.twitter_title,
                twitter_description=excluded.twitter_description,
                seo_score=excluded.seo_score,
                updated_at=excluded.updated_at
            """,
            (site_id, 'taxonomy_term', term_id, lang, title, summary, robots, path, title, summary, title, summary, seo_score(title, summary, str(slug or '')), ts),
        )
        cur.execute(
            """
            INSERT INTO search_documents(
                site_id, resource_type, resource_id, language_code, path,
                title, summary, search_text, updated_at
            ) VALUES(?,?,?,?,?,?,?,?,?)
            ON CONFLICT(site_id, resource_type, resource_id, language_code) DO UPDATE SET
                path=excluded.path,
                title=excluded.title,
                summary=excluded.summary,
                search_text=excluded.search_text,
                updated_at=excluded.updated_at
            """,
            (site_id, 'taxonomy_term', term_id, lang, path, str(name or title), summary, ' '.join([str(name or ''), summary, str(taxonomy_key or '')]).strip(), ts),
        )


def _seed_text(value: object) -> str:
    return str(value or '').strip()


def _seed_block_data(block: dict[str, object]) -> dict[str, object]:
    data = block.get('data')
    return data if isinstance(data, dict) else block


def _seed_is_public_block(block: object) -> bool:
    """Approximation stricte du contrat PHP BlockDocumentNormalizer.

    Le seed ne doit pas laisser passer une revision publiee qui ferait echouer
    `projections:rebuild`. On ne cherche pas ici a rendre tous les types, mais
    a verifier/reparer le minimum public attendu: un bloc actif, publie et non
    vide selon les conventions natives.
    """
    if not isinstance(block, dict):
        return False
    if block.get('enabled') is False:
        return False
    if str(block.get('editorial_status') or block.get('workflow_status') or 'published') != 'published':
        return False
    block_type = str(block.get('type') or block.get('block_type') or '').strip().lower() or 'markdown'
    data = _seed_block_data(block)
    if block_type == 'markdown':
        return _seed_text(data.get('text')) != ''
    if block_type in {'richtext', 'html_safe', 'html_raw'}:
        return _seed_text(data.get('html')) != ''
    if block_type == 'hero':
        return any(_seed_text(data.get(key)) for key in ('eyebrow', 'title', 'lead', 'text', 'image_src')) or int(data.get('image_media_id') or data.get('media_id') or 0) > 0
    if block_type == 'card':
        return _seed_text(data.get('title')) != '' or _seed_text(data.get('text')) != ''
    if block_type in {'image', 'video', 'audio'}:
        return int(data.get('media_id') or 0) > 0 or _seed_text(data.get('src')) != ''
    if block_type == 'buttons':
        return isinstance(data.get('items'), list) and len(data.get('items') or []) > 0
    if block_type == 'gallery':
        return isinstance(data.get('items'), list) and len(data.get('items') or []) > 0
    if block_type == 'columns':
        return isinstance(data.get('columns'), list) and any(isinstance(col, dict) and col.get('blocks') for col in data.get('columns') or [])
    if block_type in {'plan', 'articles'}:
        return True
    return False


def _seed_public_blocks(document: dict[str, object]) -> list[object]:
    blocks = document.get('blocks')
    if not isinstance(blocks, list):
        content = document.get('content')
        if isinstance(content, dict):
            blocks = content.get('blocks')
    return [block for block in (blocks if isinstance(blocks, list) else []) if _seed_is_public_block(block)]


def _seed_fallback_block(entry_id: int, language_code: str, document: dict[str, object]) -> dict[str, object]:
    content = document.get('content') if isinstance(document.get('content'), dict) else {}
    seo = document.get('seo') if isinstance(document.get('seo'), dict) else {}
    title = _seed_text(content.get('title')) or _seed_text(seo.get('meta_title')) or f'Contenu {entry_id}'
    summary = _seed_text(content.get('summary')) or _seed_text(seo.get('meta_description'))
    text = summary or title
    return {
        'id': f'seed_repair_{entry_id}_{language_code}_body',
        'type': 'markdown',
        'enabled': True,
        'editorial_status': 'published',
        'label': 'Contenu principal',
        'data': {'text': text},
        'sort_order': 0,
    }


def _replace_multisite_demo_urls(value: object) -> object:
    """Remplace les anciens domaines de démonstration par l'URL réelle webe.li/mod.

    Le seed multisite a d'abord utilisé multi-a.example.test / multi-b.example.test
    comme domaines primaires. En production de démonstration, le back-office et
    le public sont servis sous webe.li/mod/site-a et webe.li/mod/site-b. Cette
    réparation évite les redirections vers des hosts inexistants.
    """
    if isinstance(value, str):
        return (
            value
            .replace('https://multi-a.example.test/site-a', 'https://webe.li/mod/site-a')
            .replace('http://multi-a.example.test/site-a', 'https://webe.li/mod/site-a')
            .replace('https://multi-b.example.test/site-b', 'https://webe.li/mod/site-b')
            .replace('http://multi-b.example.test/site-b', 'https://webe.li/mod/site-b')
        )
    if isinstance(value, list):
        return [_replace_multisite_demo_urls(item) for item in value]
    if isinstance(value, dict):
        return {key: _replace_multisite_demo_urls(item) for key, item in value.items()}
    return value


def repair_multisite_primary_domains_for_webe_li_deployment() -> None:
    """Aligne les sites de contrat sur le déploiement réel /mod/site-a|site-b.

    Les domaines multi-*.example.test restent des alias de test, mais ne doivent
    plus être primaires. Sinon le runtime public redirige
    https://webe.li/mod/site-a vers https://multi-a.example.test/site-a/.
    """
    if not CORE_DB.exists():
        return
    with connect_sqlite(CORE_DB) as con:
        cur = con.cursor()
        cur.execute("UPDATE site_domains SET is_primary=0 WHERE site_id IN (10,11)")
        cur.execute("UPDATE site_domains SET is_primary=1, scheme='https', is_active=1, enforce_https=1 WHERE site_id=10 AND host='webe.li' AND base_path='/mod/site-a'")
        cur.execute("UPDATE site_domains SET is_primary=1, scheme='https', is_active=1, enforce_https=1 WHERE site_id=11 AND host='webe.li' AND base_path='/mod/site-b'")
        cur.execute("UPDATE site_domains SET is_primary=0, is_active=1 WHERE site_id=10 AND host='multi-a.example.test' AND base_path='/site-a'")
        cur.execute("UPDATE site_domains SET is_primary=0, is_active=1 WHERE site_id=11 AND host='multi-b.example.test' AND base_path='/site-b'")

        repaired_seo_json = 0
        for row_id, json_ld in cur.execute("SELECT id, json_ld FROM seo_metadata WHERE site_id IN (10,11) AND json_ld IS NOT NULL AND TRIM(json_ld) <> ''").fetchall():
            try:
                payload = json.loads(json_ld)
            except json.JSONDecodeError:
                payload = json_ld
            repaired = _replace_multisite_demo_urls(payload)
            encoded = json.dumps(repaired, ensure_ascii=False, separators=(',', ':')) if isinstance(repaired, (dict, list)) else str(repaired)
            if encoded != json_ld:
                cur.execute("UPDATE seo_metadata SET json_ld=?, updated_at=CURRENT_TIMESTAMP WHERE id=?", (encoded, row_id))
                repaired_seo_json += 1

        repaired_snapshot_json = 0
        for row_id, seo_json, document_json in cur.execute("SELECT id, seo_json, document_json FROM public_content_snapshots WHERE site_id IN (10,11)").fetchall():
            updates: dict[str, str] = {}
            for column, raw in [('seo_json', seo_json), ('document_json', document_json)]:
                if raw is None or str(raw).strip() == '':
                    continue
                try:
                    payload = json.loads(raw)
                except json.JSONDecodeError:
                    continue
                repaired = _replace_multisite_demo_urls(payload)
                encoded = json.dumps(repaired, ensure_ascii=False, separators=(',', ':'))
                if encoded != raw:
                    updates[column] = encoded
            if updates:
                assignments = ', '.join(f"{column}=?" for column in updates)
                cur.execute(f"UPDATE public_content_snapshots SET {assignments}, projected_at=CURRENT_TIMESTAMP WHERE id=?", [*updates.values(), row_id])
                repaired_snapshot_json += 1
        con.commit()
    if repaired_seo_json or repaired_snapshot_json:
        print(f"Correctif seed: domaines primaires multisite alignes sur webe.li/mod (json SEO: {repaired_seo_json}, snapshots: {repaired_snapshot_json}).")


def _canonical_seed_block_type(block_type: object) -> str:
    value = str(block_type or '').strip().lower() or 'markdown'
    return {
        'html': 'html_safe',
        'safe_html': 'html_safe',
        'raw_html': 'html_raw',
        'rich_text': 'richtext',
        'button': 'buttons',
    }.get(value, value)


def _canonicalize_seed_blocks(blocks: object) -> tuple[list[object], bool]:
    if not isinstance(blocks, list):
        return [], False
    changed = False
    canonical: list[object] = []
    for item in blocks:
        if not isinstance(item, dict):
            canonical.append(item)
            continue
        block = dict(item)
        old_type = block.get('type') or block.get('block_type')
        new_type = _canonical_seed_block_type(old_type)
        if old_type != new_type or block.get('block_type') is not None:
            block['type'] = new_type
            block.pop('block_type', None)
            changed = True
        data = block.get('data') if isinstance(block.get('data'), dict) else {}
        if new_type == 'columns' and isinstance(data.get('columns'), list):
            new_columns = []
            for column in data.get('columns') or []:
                if not isinstance(column, dict):
                    new_columns.append(column)
                    continue
                column_copy = dict(column)
                child_blocks, child_changed = _canonicalize_seed_blocks(column_copy.get('blocks'))
                if child_changed:
                    column_copy['blocks'] = child_blocks
                    changed = True
                new_columns.append(column_copy)
            if new_columns != data.get('columns'):
                data = dict(data)
                data['columns'] = new_columns
                block['data'] = data
                changed = True
        canonical.append(block)
    return canonical, changed


def repair_published_revision_documents_for_projection_inputs() -> None:
    """Repare les verites editoriales de seed avant projection PHP.

    Les anciens seeds ont pu laisser en base des revisions publiees dont le
    JSON ne portait pas `language_code`, ou des blocs historiques non reconnus
    comme publics par le normaliseur PHP. Cette reparation est volontairement
    idempotente et limitee aux revisions effectivement publiees.
    """
    if not CORE_DB.exists():
        return
    repaired_language = 0
    repaired_blocks = 0
    repaired_aliases = 0
    with connect_sqlite(CORE_DB) as con:
        rows = con.execute(
            """
            SELECT ce.id AS entry_id, ce.site_id, COALESCE(r.language_code, cep.language_code) AS revision_language,
                   r.id AS revision_id, r.document_json, cep.published_revision_id
            FROM revisions r
            JOIN content_entries ce ON ce.id = r.resource_id AND r.resource_type='content_entry'
            LEFT JOIN content_entry_publications cep ON cep.site_id = ce.site_id
                AND cep.entry_id = ce.id
                AND cep.language_code = r.language_code
                AND cep.published_revision_id = r.id
                AND cep.workflow_status='published'
            """
        ).fetchall()
        for entry_id, site_id, revision_language, revision_id, document_json, published_revision_id in rows:
            language_code = str(revision_language or '').strip()
            if not language_code:
                continue
            try:
                document = json.loads(document_json or '{}')
            except json.JSONDecodeError:
                continue
            if not isinstance(document, dict):
                document = {}
            changed = False
            if str(document.get('language_code') or '').strip() != language_code:
                document['language_code'] = language_code
                changed = True
                repaired_language += 1
            content = document.get('content')
            if not isinstance(content, dict):
                content = {}
                document['content'] = content
                changed = True
            blocks = document.get('blocks')
            if not isinstance(blocks, list):
                blocks = content.get('blocks') if isinstance(content.get('blocks'), list) else []
                document['blocks'] = blocks
                changed = True
            canonical_blocks, canonical_changed = _canonicalize_seed_blocks(blocks)
            if canonical_changed:
                document['blocks'] = canonical_blocks
                blocks = canonical_blocks
                changed = True
                repaired_aliases += 1
            if _seed_public_blocks(document) == []:
                fallback = _seed_fallback_block(int(entry_id), language_code, document)
                document['blocks'] = [fallback]
                content['blocks'] = [fallback]
                changed = True
                repaired_blocks += 1
            else:
                # Canonicalise le miroir content.blocks attendu par certains
                # points d'entree admin/fallbacks, sans modifier les blocs.
                if content.get('blocks') != document.get('blocks'):
                    content['blocks'] = document.get('blocks')
                    changed = True
            sanitized_document = _seed_sanitize_media_urls(document)
            if isinstance(sanitized_document, dict) and sanitized_document != document:
                document = sanitized_document
                content = document.get('content') if isinstance(document.get('content'), dict) else content
                changed = True
            if changed:
                encoded = _seed_dump_json(document)
                checksum = hashlib.sha256(encoded.encode('utf-8')).hexdigest()
                con.execute(
                    """
                    UPDATE revisions
                    SET language_code=?, document_json=?, checksum_sha256=?, updated_at=CURRENT_TIMESTAMP
                    WHERE id=?
                    """,
                    (language_code, encoded, checksum, revision_id),
                )
                con.execute(
                    """
                    UPDATE content_entry_publications
                    SET updated_at=CURRENT_TIMESTAMP
                    WHERE site_id=? AND entry_id=? AND language_code=? AND published_revision_id=?
                    """,
                    (site_id, entry_id, language_code, revision_id),
                )
                con.execute(
                    """
                    UPDATE content_entry_field_values
                    SET source_revision_checksum_sha256=?, projected_at=CURRENT_TIMESTAMP
                    WHERE entry_id=? AND language_code=? AND source_revision_id=?
                    """,
                    (checksum, entry_id, language_code, revision_id),
                )
        con.commit()
    if repaired_language or repaired_blocks or repaired_aliases:
        details = []
        if repaired_language:
            details.append(f"{repaired_language} langue(s) de document")
        if repaired_blocks:
            details.append(f"{repaired_blocks} bloc(s) public(s) manquant(s)")
        if repaired_aliases:
            details.append(f"{repaired_aliases} revision(s) avec alias de blocs canonicalise(s)")
        print("Correctif seed: " + ", ".join(details) + " repare(s) avant projections.")




def sanitize_existing_public_media_urls() -> None:
    """Balayage idempotent des projections publiques issues de snapshots SQL.

    Il complète `repair_published_revision_documents_for_projection_inputs()`:
    même si une ancienne projection pré-calculée survit dans le seed, aucune URL
    publiée ne doit contenir un chemin local absolu.
    """
    if not CORE_DB.exists():
        return
    fixed = 0
    with connect_sqlite(CORE_DB) as con:
        con.row_factory = sqlite3.Row
        for row in con.execute("SELECT id, document_json, blocks_json FROM public_content_snapshots"):
            try:
                document = json.loads(row['document_json'] or '{}')
            except json.JSONDecodeError:
                document = {}
            try:
                blocks = json.loads(row['blocks_json'] or '[]')
            except json.JSONDecodeError:
                blocks = []
            if not isinstance(document, dict):
                document = {}
            if not isinstance(blocks, list):
                blocks = []
            sanitized_document = _seed_sanitize_media_urls(document)
            sanitized_blocks = _seed_sanitize_media_urls(blocks)
            if sanitized_document != document or sanitized_blocks != blocks:
                _seed_sanitize_public_snapshot_row(con, int(row['id']), document, blocks)
                fixed += 1
        con.commit()
    if fixed:
        print(f"Correctif seed: {fixed} snapshot(s) public(s) avec URL média normalisée(s).")


def assert_seed_projection_inputs_are_valid() -> None:
    """Refuse un seed qui casserait la reconstruction PHP des projections.

    Les snapshots SQL peuvent déjà exister après l'injection du seed, mais ils ne
    doivent jamais masquer une vérité éditoriale invalide. La commande PHP
    `projections:rebuild` part de `content_entry_publications` et vérifie que la
    langue demandée correspond à la langue portée par le document de révision.
    """
    with connect_sqlite(CORE_DB) as con:
        rows = con.execute(
            """
            SELECT ce.id AS entry_id, ce.site_id, cep.language_code AS publication_language,
                   cep.published_revision_id, r.language_code AS revision_language, r.document_json
            FROM content_entry_publications cep
            JOIN content_entries ce ON ce.id = cep.entry_id AND ce.site_id = cep.site_id
            JOIN revisions r ON r.id = cep.published_revision_id
            WHERE cep.workflow_status='published'
            """
        ).fetchall()
    errors: list[str] = []
    for entry_id, site_id, publication_language, revision_id, revision_language, document_json in rows:
        publication_language = str(publication_language or '').strip()
        revision_language = str(revision_language or '').strip()
        try:
            document = json.loads(document_json or '{}')
        except json.JSONDecodeError:
            errors.append(f"entry={entry_id} site={site_id} revision={revision_id}: document_json invalide")
            continue
        document_language = str(document.get('language_code') or '').strip()
        if not publication_language:
            errors.append(f"entry={entry_id} site={site_id} revision={revision_id}: langue de publication vide")
        if revision_language and publication_language and revision_language != publication_language:
            errors.append(
                f"entry={entry_id} site={site_id} revision={revision_id}: "
                f"revision.language_code={revision_language} != publication={publication_language}"
            )
        if document_language != publication_language:
            errors.append(
                f"entry={entry_id} site={site_id} revision={revision_id}: "
                f"document.language_code={document_language!r} != publication={publication_language!r}"
            )
        if _seed_public_blocks(document) == []:
            errors.append(
                f"entry={entry_id} site={site_id} revision={revision_id}: "
                "aucun bloc actif publie non vide dans document.blocks/content.blocks"
            )
    if errors:
        preview = "\n".join(f"- {error}" for error in errors[:12])
        more = "" if len(errors) <= 12 else f"\n- ... {len(errors) - 12} erreur(s) supplementaire(s)"
        raise RuntimeError("Seed invalide pour projections publiques:\n" + preview + more)


def rebuild_public_projections(required: bool = True) -> int:
    try:
        repair_published_revision_documents_for_projection_inputs()
        sanitize_existing_public_media_urls()
        assert_seed_projection_inputs_are_valid()
    except Exception as exc:  # noqa: BLE001
        print(f"ERREUR: {exc}", file=sys.stderr)
        return 1

    configured_php = cms_subprocess_env().get("CMS_PHP_BINARY")
    php = configured_php if configured_php else shutil.which("php")
    if php is None or not Path(php).exists() or not CONSOLE.exists():
        message = (
            "PHP CLI ou backend/bin/console introuvable: les projections ne sont pas reconstruites. "
            "Installez PHP CLI ou relancez avec --skip-projections si vous voulez seulement injecter les donnees."
        )
        if required:
            print(f"ERREUR: {message}", file=sys.stderr)
            return 2
        print(f"AVERTISSEMENT: {message}")
        return 0

    proc = subprocess.run([php, str(CONSOLE), "projections:rebuild"], cwd=str(BASE), env=cms_subprocess_env(), text=True, capture_output=True)
    if proc.stdout:
        print(proc.stdout, end="")
    if proc.stderr:
        print(proc.stderr, file=sys.stderr, end="")
    if proc.returncode == 0:
        return 0

    print(
        "ERREUR: projections PHP non reconstruites. "
        "Le seed ne masque plus cette erreur avec les snapshots existants; "
        "corrigez la verite editoriale ou relancez explicitement avec --skip-projections.",
        file=sys.stderr,
    )
    return proc.returncode


def demo_home_blocks(lang: str) -> list[dict[str, object]]:
    copy = {
        "fr": {
            "eyebrow": "Publication propre, rapide et multilingue",
            "title": "Accueil",
            "lead": "Un front public de référence pour un CMS éditorial SEO-first.",
            "discover": "Découvrir le CMS",
            "contact": "Contact",
            "alt": "Illustration d’un CMS SEO-first avec contenus, recherche et publication",
            "cards": [
                ("↝", "SEO-first", "Canonical, hreflang, Open Graph, meta description et routes publiées propres."),
                ("文", "Multilingue", "Références en français, anglais et allemand avec navigation adaptée à la langue."),
                ("⚡", "Runtime léger", "HTML natif, templates Twig, CSS compact et couche publique minimale."),
            ],
            "section_eyebrow": "Front de référence",
            "section_title": "Une publication propre,<br>rapide et multilingue",
            "section_text": "Cette page d’accueil démontre une vitrine publique responsive, multilingue et pensée pour le référencement naturel : routes canoniques, meta title, meta description, hreflang, sitemap, robots.txt, Open Graph, JSON-LD, recherche publique et navigation claire.",
        },
        "en": {
            "eyebrow": "Clean, fast and multilingual publishing",
            "title": "Home",
            "lead": "A reference public front end for an SEO-first editorial CMS.",
            "discover": "Discover the CMS",
            "contact": "Contact",
            "alt": "Illustration of an SEO-first CMS with content, search and publishing",
            "cards": [
                ("↝", "SEO-first", "Canonical URLs, hreflang, Open Graph, meta description and clean published routes."),
                ("文", "Multilingual", "French, English and German references with navigation adapted to each language."),
                ("⚡", "Lightweight runtime", "Native HTML, Twig templates, compact CSS and a minimal public layer."),
            ],
            "section_eyebrow": "Reference front end",
            "section_title": "Clean, fast and multilingual publishing",
            "section_text": "This homepage demonstrates a responsive, multilingual and search-friendly public showcase: canonical routes, meta title, meta description, hreflang, sitemap, robots.txt, Open Graph, JSON-LD, public search and clear navigation.",
        },
        "de": {
            "eyebrow": "Saubere, schnelle und mehrsprachige Veröffentlichung",
            "title": "Startseite",
            "lead": "Ein Referenz-Frontend für ein SEO-first Redaktions-CMS.",
            "discover": "CMS entdecken",
            "contact": "Kontakt",
            "alt": "Illustration eines SEO-first CMS mit Inhalten, Suche und Veröffentlichung",
            "cards": [
                ("↝", "SEO-first", "Canonical, hreflang, Open Graph, Meta-Beschreibung und saubere veröffentlichte Routen."),
                ("文", "Mehrsprachig", "Referenzen auf Französisch, Englisch und Deutsch mit sprachgerechter Navigation."),
                ("⚡", "Leichtes Runtime", "Natives HTML, Twig-Templates, kompaktes CSS und eine minimale öffentliche Schicht."),
            ],
            "section_eyebrow": "Referenz-Frontend",
            "section_title": "Saubere, schnelle und mehrsprachige Veröffentlichung",
            "section_text": "Diese Startseite zeigt eine responsive, mehrsprachige und suchmaschinenfreundliche öffentliche Präsentation: kanonische Routen, Meta-Titel, Meta-Beschreibung, hreflang, Sitemap, robots.txt, Open Graph, JSON-LD, öffentliche Suche und klare Navigation.",
        },
    }[lang]
    return [
        {
            "id": "home_hero_01", "type": "hero", "enabled": True, "editorial_status": "published", "label": "Hero accueil", "css_class": "", "anchor": "",
            "data": {
                "eyebrow": copy["eyebrow"], "title": copy["title"], "lead": copy["lead"], "heading_level": "h1", "layout": "split",
                "image_src": "/frontend/theme-default/assets/img/cms-hero-1280.png", "image_alt": copy["alt"], "image_width": 1280, "image_height": 853, "image_loading": "eager",
                "buttons": [
                    {"label": copy["discover"], "url": "/articles", "style": "primary", "target": "_self"},
                    {"label": copy["contact"], "url": "/contact", "style": "ghost", "target": "_self"},
                ],
            },
            "sort_order": 0,
        },
        {
            "id": "home_cards_01", "type": "columns", "enabled": True, "editorial_status": "published", "label": "Cartes forces", "css_class": "section", "anchor": "",
            "data": {
                "layout": "cards-3",
                "columns": [
                    {"label": title, "width": "auto", "blocks": [{"id": f"home_card_{i}", "type": "card", "enabled": True, "editorial_status": "published", "label": title, "css_class": "card feature-card", "anchor": "", "data": {"icon": icon, "title": title, "text": text, "url": "", "link_label": "", "style": "feature"}, "sort_order": 0}]}
                    for i, (icon, title, text) in enumerate(copy["cards"], start=1)
                ],
            },
            "sort_order": 1,
        },
        {
            "id": "home_split_01", "type": "html", "enabled": True, "editorial_status": "published", "label": "Section publication", "css_class": "section", "anchor": "",
            "data": {"html": f'<section class="split-panel" aria-labelledby="publication-title"><div><p class="eyebrow">{copy["section_eyebrow"]}</p><h2 id="publication-title">{copy["section_title"]}</h2></div><div class="prose"><p>{copy["section_text"]}</p></div></section>'},
            "sort_order": 2,
        },
    ]





def block_plain_text(blocks: object) -> str:
    parts: list[str] = []
    if not isinstance(blocks, list):
        return ""
    for block in blocks:
        if not isinstance(block, dict):
            continue
        data = block.get("data") if isinstance(block.get("data"), dict) else {}
        btype = str(block.get("type") or "")
        if btype in {"markdown", "html"}:
            parts.append(str(data.get("text") or data.get("html") or ""))
        elif btype == "card":
            parts.append(" ".join(str(data.get(k) or "") for k in ["icon", "title", "text", "link_label"]))
        elif btype == "hero":
            parts.extend(str(data.get(k) or "") for k in ("eyebrow", "title", "lead"))
        elif btype in {"image", "video", "audio", "iframe"}:
            parts.extend(str(data.get(k) or "") for k in ("title", "caption", "alt"))
        elif btype == "columns":
            for column in data.get("columns") if isinstance(data.get("columns"), list) else []:
                if isinstance(column, dict):
                    parts.append(block_plain_text(column.get("blocks")))
    return re.sub(r"\s+", " ", " ".join(parts)).strip()

def demo_page_blocks(lang: str, title: str, summary: str, body: str, entry_key: str = "page") -> list[dict[str, object]]:
    """Blocs natifs pour les pages utilitaires du seed.

    Même les pages simples (contact, mentions légales, confidentialité, cookies)
    doivent démontrer le contrat éditorial par blocs. Le front peut encore rendre
    un ancien contenu sans bloc, mais la base de référence best-in-class repart de
    pages composées et éditables depuis le backoffice.
    """
    labels = {
        "fr": {"eyebrow": "Page éditoriale", "contact_cta": "Nous contacter"},
        "en": {"eyebrow": "Editorial page", "contact_cta": "Contact us"},
        "de": {"eyebrow": "Redaktionelle Seite", "contact_cta": "Kontakt aufnehmen"},
    }.get(lang, {"eyebrow": "Page éditoriale", "contact_cta": "Nous contacter"})
    blocks: list[dict[str, object]] = [
        {
            "id": f"{entry_key}_hero_01",
            "type": "hero",
            "enabled": True,
            "editorial_status": "published",
            "label": "Hero de page",
            "css_class": "",
            "anchor": "",
            "data": {
                "eyebrow": labels["eyebrow"],
                "title": title,
                "lead": summary,
                "heading_level": "h1",
                "layout": "simple",
                "buttons": [],
            },
            "sort_order": 0,
        },
        {
            "id": f"{entry_key}_body_01",
            "type": "markdown",
            "enabled": True,
            "editorial_status": "published",
            "label": "Contenu principal",
            "css_class": "prose",
            "anchor": "",
            "data": {"text": body},
            "sort_order": 1,
        },
    ]
    if entry_key == "contact":
        blocks.append(
            {
                "id": "contact_buttons_01",
                "type": "buttons",
                "enabled": True,
                "editorial_status": "published",
                "label": "Actions de contact",
                "css_class": "",
                "anchor": "",
                "data": {
                    "items": [
                        {"label": labels["contact_cta"], "url": "mailto:contact@example.test", "style": "primary", "target": "_self"},
                    ]
                },
                "sort_order": 2,
            }
        )
    return blocks


def demo_article_blocks(body: str) -> list[dict[str, object]]:
    return [
        {
            "id": "article_body_01",
            "type": "markdown",
            "enabled": True,
            "editorial_status": "published",
            "label": "Corps de l’article",
            "css_class": "",
            "anchor": "",
            "data": {"text": body},
            "sort_order": 0,
        }
    ]

def seed() -> None:
    ensure_databases_exist()
    if seed_from_default_sql_files():
        return

    core = connect_sqlite(CORE_DB)
    iam = connect_sqlite(IAM_DB)
    try:
        ensure_reference_data(core, iam)
        enforce_modules_permissions_policy(iam)
        ensure_cookies_reference_data()

        c = core.cursor()
        i = iam.cursor()

        i.execute("DELETE FROM iam_sessions")
        i.execute("DELETE FROM iam_audit_logs")
        i.execute("DELETE FROM api_tokens")
        i.execute("DELETE FROM iam_rate_limits")
        i.execute("DELETE FROM iam_user_site_roles")
        i.execute("DELETE FROM iam_user_roles")
        i.execute("DELETE FROM iam_users")
        i.execute(
            "INSERT INTO iam_users(email, email_normalized, password_hash, first_name, last_name, is_active, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?)",
            ("admin@example.test", "admin@example.test", hash_pw("admin123"), "Admin", "User", 1, now(), now()),
        )
        user_id = i.lastrowid
        role_id = fetch_required_id(i, "SELECT id FROM iam_roles WHERE role_key=?", ("super_admin",), "role IAM super_admin")
        i.execute("INSERT OR IGNORE INTO iam_user_roles(user_id, role_id) VALUES(?, ?)", (user_id, role_id))
        iam.commit()

        site_id = fetch_required_id(c, "SELECT id FROM sites WHERE site_key=?", ("main",), "site main")
        page_type = fetch_required_id(c, "SELECT id FROM content_types WHERE type_key=?", ("page",), "content type page")
        article_type = fetch_required_id(c, "SELECT id FROM content_types WHERE type_key=?", ("article",), "content type article")

        c.execute("DELETE FROM menu_item_localizations")
        c.execute("DELETE FROM menu_items")
        c.execute("DELETE FROM menus")

        c.execute("DELETE FROM site_localizations WHERE site_id=?", (site_id,))
        site_localizations = [
            ("fr", "CMS éditorial SEO-first", "Publication propre, rapide et multilingue", "Moteur public léger, routes propres et SEO natif.", " · CMS SEO-first", "CMS éditorial SEO-first, multi-site et multilingue, avec routes canoniques, hreflang, sitemap, Open Graph, JSON-LD et recherche publique propre."),
            ("en", "SEO-first editorial CMS", "Clean, fast and multilingual publishing", "Lightweight public runtime, clean routes and native SEO.", " · SEO-first CMS", "SEO-first, multi-site and multilingual editorial CMS with canonical routes, hreflang, sitemap, Open Graph, JSON-LD and clean public search."),
            ("de", "SEO-first Redaktions-CMS", "Saubere, schnelle und mehrsprachige Veröffentlichung", "Leichtes öffentliches Runtime, saubere Routen und natives SEO.", " · SEO-first CMS", "SEO-first, multisite- und mehrsprachiges Redaktions-CMS mit kanonischen Routen, hreflang, Sitemap, Open Graph, JSON-LD und sauberer öffentlicher Suche."),
        ]
        for row in site_localizations:
            c.execute("INSERT INTO site_localizations(site_id, language_code, site_title, baseline, footer_text, default_meta_title_suffix, default_meta_description) VALUES(?,?,?,?,?,?,?)", (site_id, *row))
        # Le seed repart d'une base propre et native : on efface d'abord les
        # projections publiques, y compris celles des archives taxonomiques, afin
        # d'éviter des références polymorphiques obsolètes lors d'un reseed local.
        for projection_table in ["routes", "seo_metadata", "search_documents", "seo_audit_issues", "public_content_snapshots"]:
            c.execute(f"DELETE FROM {projection_table}")

        c.execute("DELETE FROM content_entry_taxonomy_terms")
        c.execute("DELETE FROM content_type_taxonomies")
        c.execute("DELETE FROM taxonomy_term_localizations")
        c.execute("DELETE FROM taxonomy_terms")
        c.execute("DELETE FROM taxonomies")

        c.execute(
            "INSERT INTO taxonomies(site_id, taxonomy_key, name, description, is_hierarchical, is_localized, seo_enabled, archive_enabled, sort_order) VALUES(?,?,?,?,?,?,?,?,?)",
            (site_id, "categories", "Categories", "Classement éditorial principal", 1, 1, 1, 1, 1),
        )
        categories_tax_id = c.lastrowid
        c.execute(
            "INSERT INTO taxonomies(site_id, taxonomy_key, name, description, is_hierarchical, is_localized, seo_enabled, archive_enabled, sort_order) VALUES(?,?,?,?,?,?,?,?,?)",
            (site_id, "tags", "Tags", "Mots-clés éditoriaux transversaux", 0, 1, 1, 1, 2),
        )
        tags_tax_id = c.lastrowid

        taxonomy_terms = {
            categories_tax_id: [
                ("seo", "SEO", "seo", "Articles consacrés aux fondamentaux SEO du CMS : routes propres, métadonnées, canonical, hreflang, sitemap et données structurées.", "SEO éditorial natif · CMS SEO-first", "Articles consacrés au SEO natif du CMS : routes propres, métadonnées, canonical, hreflang, sitemap, Open Graph, JSON-LD et recherche publique."),
                ("cms", "CMS", "cms", "Articles consacrés au moteur CMS : publication éditoriale, révisions, projections publiques, back-office et runtime léger.", "CMS éditorial léger · Publication propre", "Articles consacrés au moteur CMS : publication éditoriale, révisions, projections publiques, back-office, données structurées et runtime léger."),
                ("multisite", "Multi-site", "multi-site", "Articles consacrés à la gestion multi-site et multilingue : langues, domaines, préfixes URL, hreflang et cohérence éditoriale.", "CMS multi-site et multilingue · Routes propres", "Articles consacrés au fonctionnement multi-site et multilingue du CMS : domaines, langues, préfixes URL, hreflang et cohérence des routes publiques."),
            ],
            tags_tax_id: [
                ("performance", "Performance", "performance", "Optimisation du runtime, du rendu public et des requêtes.", "Performance · CMS", "Contenus liés à la performance du CMS."),
                ("edition", "Édition", "edition", "Expérience d’édition, blocs, champs et publication.", "Édition · CMS", "Contenus liés à l’expérience d’édition du CMS."),
            ],
        }
        term_ids_by_key = {}
        for taxonomy_id, terms in taxonomy_terms.items():
            for idx, (key, name, slug, description, meta_title, meta_description) in enumerate(terms, start=1):
                c.execute("INSERT INTO taxonomy_terms(taxonomy_id, term_key, is_active, sort_order) VALUES(?,?,1,?)", (taxonomy_id, key, idx))
                term_id = c.lastrowid
                term_ids_by_key[key] = term_id
                prefix = "/categories/" if taxonomy_id == categories_tax_id else "/tags/"
                localized_terms = {
                    "fr": (name, slug, description, meta_title, meta_description),
                    "en": (name, slug, description, meta_title, meta_description),
                    "de": (name, slug, description, meta_title, meta_description),
                }
                for term_lang, (term_name, term_slug, term_description, term_meta_title, term_meta_description) in localized_terms.items():
                    c.execute(
                        "INSERT INTO taxonomy_term_localizations(site_id, taxonomy_id, term_id, language_code, name, slug, full_path, description, meta_title, meta_description) VALUES(?,?,?,?,?,?,?,?,?,?)",
                        (site_id, taxonomy_id, term_id, term_lang, term_name, term_slug, prefix + term_slug, term_description, term_meta_title, term_meta_description),
                    )

        for content_type_id, taxonomy_id, is_required, max_terms in [
            (page_type, categories_tax_id, 0, 1),
            (article_type, categories_tax_id, 1, 1),
            (page_type, tags_tax_id, 0, None),
            (article_type, tags_tax_id, 0, None),
        ]:
            c.execute(
                "INSERT INTO content_type_taxonomies(content_type_id, taxonomy_id, is_required, max_terms) VALUES(?,?,?,?)",
                (content_type_id, taxonomy_id, is_required, max_terms),
            )

        for projection_table in ["routes", "seo_metadata", "search_documents", "seo_audit_issues", "public_content_snapshots"]:
            c.execute(f"DELETE FROM {projection_table} WHERE resource_type='content_entry'")
        c.execute("DELETE FROM content_entry_localizations")
        c.execute("DELETE FROM content_entry_working_revisions")
        c.execute("DELETE FROM content_entry_publications")
        c.execute("DELETE FROM revisions")
        c.execute("DELETE FROM content_entries")
        demo_entries = [
            (page_type, "home", {
                "fr": {"title": "Accueil", "slug": "home", "lead": "Un front public de référence pour un CMS éditorial SEO-first.", "main_text": "Cette page d’accueil démontre une vitrine publique responsive, multilingue et pensée pour le référencement naturel : routes canoniques, meta title, meta description, hreflang, sitemap, robots.txt, Open Graph, JSON-LD, recherche publique et navigation claire.", "meta_title": "CMS éditorial SEO-first multilingue et multi-site", "meta_description": "Découvrez un CMS éditorial SEO-first avec routes propres, canonical, hreflang, sitemap, robots.txt, Open Graph, JSON-LD, recherche native et runtime léger.", "blocks": demo_home_blocks("fr")},
                "en": {"title": "Home", "slug": "home", "lead": "A reference public front end for an SEO-first editorial CMS.", "main_text": "This homepage demonstrates a responsive, multilingual and search-friendly public showcase: canonical routes, meta title, meta description, hreflang, sitemap, robots.txt, Open Graph, JSON-LD, public search and clear navigation.", "meta_title": "SEO-first multilingual and multi-site editorial CMS", "meta_description": "Explore an SEO-first editorial CMS with clean routes, canonical URLs, hreflang, sitemap, robots.txt, Open Graph, JSON-LD, native search and lightweight runtime.", "blocks": demo_home_blocks("en")},
                "de": {"title": "Startseite", "slug": "home", "lead": "Ein Referenz-Frontend für ein SEO-first Redaktions-CMS.", "main_text": "Diese Startseite zeigt eine responsive, mehrsprachige und suchmaschinenfreundliche öffentliche Präsentation: kanonische Routen, Meta-Titel, Meta-Beschreibung, hreflang, Sitemap, robots.txt, Open Graph, JSON-LD, öffentliche Suche und klare Navigation.", "meta_title": "SEO-first mehrsprachiges und multisite Redaktions-CMS", "meta_description": "Entdecken Sie ein SEO-first Redaktions-CMS mit sauberen Routen, Canonical-URLs, hreflang, Sitemap, robots.txt, Open Graph, JSON-LD, nativer Suche und leichtem Runtime.", "blocks": demo_home_blocks("de")},
            }),
            (page_type, "articles", {
                "fr": {"title": "Articles", "slug": "articles", "lead": "Guides, notes de conception et retours d’expérience sur le CMS.", "main_text": "Cette page d’index éditorial regroupe les derniers articles publiés et permet de les filtrer par catégories et tags utilisés réellement par les contenus.", "meta_title": "Articles · CMS éditorial SEO-first", "meta_description": "Retrouvez les derniers articles du CMS, avec filtres par catégories, tags populaires, pagination et blocs éditoriaux configurables.", "settings": {"article_limit": 6, "tag_limit": 12, "article_show_published_date": True, "article_show_author": False}, "blocks": [
                    {"id": "articles_intro_01", "type": "hero", "enabled": True, "editorial_status": "published", "label": "Introduction articles", "css_class": "", "anchor": "", "data": {"eyebrow": "Journal éditorial", "title": "Articles", "lead": "Guides, notes de conception et retours d’expérience sur le CMS.", "heading_level": "h1", "layout": "simple", "buttons": []}, "sort_order": 0},
                    {"id": "articles_note_01", "type": "markdown", "enabled": True, "editorial_status": "published", "label": "Note éditoriale", "css_class": "prose", "anchor": "", "data": {"text": "Filtrez les articles par catégories et tags. Le nombre d’articles affichés se règle dans les paramètres de cette page."}, "sort_order": 1}
                ]},
                "en": {"title": "Articles", "slug": "posts", "lead": "Guides, design notes and field feedback about the CMS.", "main_text": "This editorial index groups the latest published articles and lets visitors filter them by categories and tags that are actually used by content.", "meta_title": "Articles · SEO-first editorial CMS", "meta_description": "Browse the latest CMS articles with category filters, popular tags, pagination and configurable editorial blocks.", "settings": {"article_limit": 6, "tag_limit": 12, "article_show_published_date": True, "article_show_author": False}, "blocks": [
                    {"id": "articles_intro_01", "type": "hero", "enabled": True, "editorial_status": "published", "label": "Articles introduction", "css_class": "", "anchor": "", "data": {"eyebrow": "Editorial journal", "title": "Articles", "lead": "Guides, design notes and field feedback about the CMS.", "heading_level": "h1", "layout": "simple", "buttons": []}, "sort_order": 0}
                ]},
                "de": {"title": "Artikel", "slug": "beitraege", "lead": "Leitfäden, Designnotizen und Erfahrungsberichte zum CMS.", "main_text": "Diese redaktionelle Indexseite bündelt die neuesten veröffentlichten Artikel und filtert sie nach tatsächlich verwendeten Kategorien und Tags.", "meta_title": "Artikel · SEO-first Redaktions-CMS", "meta_description": "Lesen Sie die neuesten CMS-Artikel mit Kategorien, beliebten Tags, Pagination und konfigurierbaren redaktionellen Blöcken.", "settings": {"article_limit": 6, "tag_limit": 12, "article_show_published_date": True, "article_show_author": False}, "blocks": [
                    {"id": "articles_intro_01", "type": "hero", "enabled": True, "editorial_status": "published", "label": "Artikel Einführung", "css_class": "", "anchor": "", "data": {"eyebrow": "Redaktionelles Journal", "title": "Artikel", "lead": "Leitfäden, Designnotizen und Erfahrungsberichte zum CMS.", "heading_level": "h1", "layout": "simple", "buttons": []}, "sort_order": 0}
                ]},
            }),
            (page_type, "contact", {
                "fr": {"title": "Contact", "slug": "contact", "lead": "Contactez l’équipe.", "main_text": "Cette page démontre un contenu de type page publié via projections, avec une URL propre, des métadonnées localisées et une place claire dans la navigation.", "meta_title": "Contact · CMS SEO-first", "meta_description": "Contactez l’équipe du CMS éditorial SEO-first et vérifiez le rendu public d’une page localisée simple."},
                "en": {"title": "Contact", "slug": "contact-us", "lead": "Contact the team.", "main_text": "This page demonstrates a projected page content item with a clean URL, localized metadata and a clear navigation position.", "meta_title": "Contact · SEO-first CMS", "meta_description": "Contact the SEO-first editorial CMS team and check the public rendering of a simple localized page."},
                "de": {"title": "Kontakt", "slug": "kontakt", "lead": "Kontaktieren Sie das Team.", "main_text": "Diese Seite demonstriert einen projizierten Seiteninhalt mit sauberer URL, lokalisierten Metadaten und klarer Navigation.", "meta_title": "Kontakt · SEO-first CMS", "meta_description": "Kontaktieren Sie das Team des SEO-first Redaktions-CMS und prüfen Sie die öffentliche Darstellung einer einfachen lokalisierten Seite."},
            }),
            (page_type, "legal", {
                "fr": {"title": "Mentions légales", "slug": "mentions-legales", "lead": "Informations légales du site.", "main_text": "Éditeur, hébergement, responsabilité éditoriale et informations obligatoires sont centralisés sur cette page utilitaire.", "meta_title": "Mentions légales · CMS SEO-first", "meta_description": "Mentions légales et informations obligatoires du site de démonstration CMS SEO-first.", "meta_robots": "noindex,follow"},
                "en": {"title": "Legal notice", "slug": "legal-notice", "lead": "Legal information for the site.", "main_text": "Publisher, hosting, editorial responsibility and mandatory information are centralized on this utility page.", "meta_title": "Legal notice · SEO-first CMS", "meta_description": "Legal notice and mandatory information for the SEO-first CMS demonstration site.", "meta_robots": "noindex,follow"},
                "de": {"title": "Impressum", "slug": "impressum", "lead": "Rechtliche Informationen zur Website.", "main_text": "Herausgeber, Hosting, redaktionelle Verantwortung und Pflichtangaben sind auf dieser Hilfsseite zusammengefasst.", "meta_title": "Impressum · SEO-first CMS", "meta_description": "Impressum und Pflichtangaben der SEO-first CMS Demo-Website.", "meta_robots": "noindex,follow"},
            }),
            (page_type, "privacy", {
                "fr": {"title": "Politique de confidentialité", "slug": "politique-confidentialite", "lead": "Protection des données et confidentialité.", "main_text": "Cette page présente les principes de collecte, d’usage, de conservation et de protection des données personnelles.", "meta_title": "Politique de confidentialité · CMS SEO-first", "meta_description": "Politique de confidentialité et protection des données du site de démonstration CMS SEO-first.", "meta_robots": "noindex,follow"},
                "en": {"title": "Privacy policy", "slug": "privacy-policy", "lead": "Data protection and privacy.", "main_text": "This page presents the principles for collecting, using, retaining and protecting personal data.", "meta_title": "Privacy policy · SEO-first CMS", "meta_description": "Privacy policy and data protection information for the SEO-first CMS demonstration site.", "meta_robots": "noindex,follow"},
                "de": {"title": "Datenschutzerklärung", "slug": "datenschutzerklaerung", "lead": "Datenschutz und Privatsphäre.", "main_text": "Diese Seite erläutert die Grundsätze zur Erhebung, Nutzung, Speicherung und zum Schutz personenbezogener Daten.", "meta_title": "Datenschutzerklärung · SEO-first CMS", "meta_description": "Datenschutzerklärung und Datenschutzinformationen der SEO-first CMS Demo-Website.", "meta_robots": "noindex,follow"},
            }),
            (page_type, "cookies", {
                "fr": {"title": "Informations cookies", "slug": "cookies", "lead": "Usage des cookies et traceurs.", "main_text": "Cette page explique les cookies nécessaires, les préférences de consentement et les éventuelles mesures d’audience.", "meta_title": "Informations cookies · CMS SEO-first", "meta_description": "Informations sur les cookies, traceurs et préférences de consentement du site de démonstration CMS SEO-first.", "meta_robots": "noindex,follow"},
                "en": {"title": "Cookie information", "slug": "cookies", "lead": "Use of cookies and trackers.", "main_text": "This page explains necessary cookies, consent preferences and optional audience measurement.", "meta_title": "Cookie information · SEO-first CMS", "meta_description": "Information about cookies, trackers and consent preferences for the SEO-first CMS demonstration site.", "meta_robots": "noindex,follow"},
                "de": {"title": "Cookie-Informationen", "slug": "cookies", "lead": "Verwendung von Cookies und Trackern.", "main_text": "Diese Seite erklärt notwendige Cookies, Einwilligungspräferenzen und optionale Reichweitenmessung.", "meta_title": "Cookie-Informationen · SEO-first CMS", "meta_description": "Informationen zu Cookies, Trackern und Einwilligungspräferenzen der SEO-first CMS Demo-Website.", "meta_robots": "noindex,follow"},
            }),
            (article_type, "seo-multisite", {
                "fr": {"title": "SEO, multilingue et multi-site", "slug": "seo-multisite", "lead": "Article de démonstration sur la couche SEO native.", "main_text": "Le CMS repose sur des révisions comme source de vérité et sur des projections publiées pour les routes, les métadonnées SEO, la recherche, les redirections et les tombstones. Cette architecture permet un runtime léger et des signaux SEO cohérents dès la publication.", "meta_title": "SEO multilingue et multi-site dans un CMS éditorial", "meta_description": "Article de démonstration sur les projections publiées, les routes canoniques, le hreflang, la recherche native et le SEO multilingue."},
                "en": {"title": "SEO, multilingual and multi-site", "slug": "seo-multisite", "lead": "Demonstration article about the native SEO layer.", "main_text": "The CMS uses revisions as the source of truth and published projections for routes, SEO metadata, search, redirects and tombstones. This architecture keeps the public runtime lightweight and SEO signals coherent as soon as content is published.", "meta_title": "Multilingual and multi-site SEO in an editorial CMS", "meta_description": "Demonstration article about published projections, canonical routes, hreflang, native search and multilingual SEO."},
                "de": {"title": "SEO, mehrsprachig und multisite", "slug": "seo-multisite", "lead": "Demonstrationsartikel über die native SEO-Schicht.", "main_text": "Das CMS nutzt Revisionen als Quelle der Wahrheit und veröffentlichte Projektionen für Routen, SEO-Metadaten, Suche, Weiterleitungen und Tombstones. Diese Architektur hält das öffentliche Runtime leicht und SEO-Signale ab der Veröffentlichung kohärent.", "meta_title": "Mehrsprachiges und multisite SEO in einem Redaktions-CMS", "meta_description": "Demonstrationsartikel über veröffentlichte Projektionen, kanonische Routen, hreflang, native Suche und mehrsprachiges SEO."},
            }),
            (article_type, "runtime-leger", {
                "fr": {"title": "Runtime léger et projections publiques", "slug": "runtime-leger", "lead": "Pourquoi le front public doit lire des snapshots stables.", "main_text": "Un runtime public performant évite de recalculer les relations éditoriales à chaque requête. Les snapshots publiés deviennent le contrat de lecture : route, SEO, blocs et recherche sont déjà prêts.", "meta_title": "Runtime léger et snapshots publics", "meta_description": "Comprendre comment les projections publiques rendent le CMS plus rapide, stable et facile à mettre en cache."},
                "en": {"title": "Lightweight runtime and public projections", "slug": "runtime-leger", "lead": "Why the public front should read stable snapshots.", "main_text": "A fast public runtime avoids recalculating editorial relations on every request. Published snapshots become the read contract: route, SEO, blocks and search are ready.", "meta_title": "Lightweight runtime and public snapshots", "meta_description": "Understand how public projections make the CMS faster, more stable and easier to cache."},
                "de": {"title": "Leichtes Runtime und öffentliche Projektionen", "slug": "runtime-leger", "lead": "Warum das öffentliche Frontend stabile Snapshots lesen sollte.", "main_text": "Ein schnelles öffentliches Runtime vermeidet es, redaktionelle Beziehungen bei jeder Anfrage neu zu berechnen. Veröffentlichte Snapshots werden zum Lesevertrag: Route, SEO, Blöcke und Suche sind vorbereitet.", "meta_title": "Leichtes Runtime und öffentliche Snapshots", "meta_description": "Wie öffentliche Projektionen das CMS schneller, stabiler und cache-freundlicher machen."},
            }),
            (article_type, "editeur-blocs", {
                "fr": {"title": "Éditeur par blocs : garder le contenu lisible", "slug": "editeur-blocs", "lead": "Markdown, HTML, médias et hero dans un modèle commun.", "main_text": "Le système de blocs doit rester compréhensible : chaque bloc porte un type, des données, un ordre et des paramètres simples. Cette approche facilite le rendu desktop, mobile et SEO.", "meta_title": "Éditeur par blocs natif pour CMS", "meta_description": "Structurer les contenus avec des blocs Markdown, HTML, médias, iframe et hero sans alourdir le runtime public."},
                "en": {"title": "Block editor: keeping content readable", "slug": "editeur-blocs", "lead": "Markdown, HTML, media and hero blocks in one model.", "main_text": "The block system should stay understandable: each block carries a type, data, order and simple settings. This approach supports desktop, mobile and SEO rendering.", "meta_title": "Native block editor for CMS", "meta_description": "Structure content with Markdown, HTML, media, iframe and hero blocks without weighing down the public runtime."},
                "de": {"title": "Block-Editor: Inhalte lesbar halten", "slug": "editeur-blocs", "lead": "Markdown, HTML, Medien und Hero-Blöcke in einem Modell.", "main_text": "Das Blocksystem soll verständlich bleiben: Jeder Block trägt Typ, Daten, Reihenfolge und einfache Einstellungen. Das unterstützt Desktop, Mobile und SEO.", "meta_title": "Nativer Block-Editor für CMS", "meta_description": "Inhalte mit Markdown, HTML, Medien, Iframe und Hero-Blöcken strukturieren, ohne das öffentliche Runtime zu belasten."},
            }),
            (article_type, "taxonomies-editoriales", {
                "fr": {"title": "Taxonomies éditoriales : catégories et tags", "slug": "taxonomies-editoriales", "lead": "Différencier classement principal et mots-clés transversaux.", "main_text": "Les catégories servent de classement éditorial stable. Les tags permettent des croisements plus souples. L’index des articles peut alors proposer des filtres utiles sans configuration manuelle.", "meta_title": "Taxonomies éditoriales dans un CMS", "meta_description": "Utiliser catégories et tags pour filtrer automatiquement les articles et structurer une navigation éditoriale claire."},
                "en": {"title": "Editorial taxonomies: categories and tags", "slug": "taxonomies-editoriales", "lead": "Separate primary classification from transversal keywords.", "main_text": "Categories provide stable editorial classification. Tags allow more flexible cross-links. The article index can then provide useful filters without manual configuration.", "meta_title": "Editorial taxonomies in a CMS", "meta_description": "Use categories and tags to automatically filter articles and build clear editorial navigation."},
                "de": {"title": "Redaktionelle Taxonomien: Kategorien und Tags", "slug": "taxonomies-editoriales", "lead": "Hauptklassifikation und Querverweise trennen.", "main_text": "Kategorien bilden eine stabile redaktionelle Klassifikation. Tags ermöglichen flexiblere Querverbindungen. Der Artikelindex kann dadurch nützliche Filter ohne manuelle Konfiguration anbieten.", "meta_title": "Redaktionelle Taxonomien in einem CMS", "meta_description": "Kategorien und Tags nutzen, um Artikel automatisch zu filtern und klare redaktionelle Navigation zu schaffen."},
            }),
            (article_type, "routes-propres", {
                "fr": {"title": "Routes propres et menu éditorial", "slug": "routes-propres", "lead": "Un menu doit pointer vers une intention, pas vers un contenu isolé.", "main_text": "Un lien de menu appelé Articles doit mener à une collection. Les articles individuels restent accessibles par leurs routes propres, tandis que l’index porte le rôle de page d’entrée éditoriale.", "meta_title": "Routes propres et menus éditoriaux", "meta_description": "Pourquoi un menu Articles doit pointer vers une page d’index filtrable plutôt que vers un article précis."},
                "en": {"title": "Clean routes and editorial menu", "slug": "clean-routes", "lead": "A menu should point to an intent, not an isolated content item.", "main_text": "A menu link named Articles should lead to a collection. Individual articles keep their own clean routes, while the index acts as the editorial entry page.", "meta_title": "Clean routes and editorial menus", "meta_description": "Why an Articles menu should point to a filterable index page rather than to one specific article."},
                "de": {"title": "Saubere Routen und redaktionelles Menü", "slug": "saubere-routen", "lead": "Ein Menü sollte auf eine Absicht zeigen, nicht auf einen Einzelinhalt.", "main_text": "Ein Menülink namens Artikel sollte zu einer Sammlung führen. Einzelne Artikel behalten ihre eigenen sauberen Routen, während der Index die redaktionelle Einstiegsseite bildet.", "meta_title": "Saubere Routen und redaktionelle Menüs", "meta_description": "Warum ein Artikel-Menü auf eine filterbare Indexseite statt auf einen einzelnen Artikel zeigen sollte."},
            }),
            (article_type, "seo-jsonld", {
                "fr": {"title": "SEO technique : canonical, hreflang et JSON-LD", "slug": "seo-jsonld", "lead": "Les signaux SEO doivent être produits par le moteur.", "main_text": "Le CMS doit générer automatiquement canonical, hreflang, Open Graph, sitemap et JSON-LD à partir des données publiées. L’éditeur reste concentré sur la qualité du contenu.", "meta_title": "SEO technique natif dans un CMS", "meta_description": "Canonical, hreflang, Open Graph, sitemap et JSON-LD générés depuis les projections publiées du CMS."},
                "en": {"title": "Technical SEO: canonical, hreflang and JSON-LD", "slug": "technical-seo-jsonld", "lead": "SEO signals should be produced by the engine.", "main_text": "The CMS should automatically generate canonical URLs, hreflang, Open Graph, sitemap and JSON-LD from published data. Editors can focus on content quality.", "meta_title": "Native technical SEO in a CMS", "meta_description": "Canonical URLs, hreflang, Open Graph, sitemap and JSON-LD generated from CMS public projections."},
                "de": {"title": "Technisches SEO: Canonical, Hreflang und JSON-LD", "slug": "technisches-seo-jsonld", "lead": "SEO-Signale sollten vom Motor erzeugt werden.", "main_text": "Das CMS sollte Canonical-URLs, hreflang, Open Graph, Sitemap und JSON-LD automatisch aus veröffentlichten Daten erzeugen. Redakteure konzentrieren sich auf Inhalte.", "meta_title": "Natives technisches SEO im CMS", "meta_description": "Canonical-URLs, hreflang, Open Graph, Sitemap und JSON-LD aus öffentlichen CMS-Projektionen."},
            }),
        ]
        article_taxonomy_assignments = {
            "seo-multisite": ["seo", "performance", "edition"],
            "runtime-leger": ["cms", "performance"],
            "editeur-blocs": ["cms", "edition"],
            "taxonomies-editoriales": ["cms", "edition"],
            "routes-propres": ["multisite", "seo", "performance"],
            "seo-jsonld": ["seo", "performance"],
        }

        entry_ids_by_key = {}
        for content_type_id, entry_key, translations in demo_entries:
            for lang, data in translations.items():
                if content_type_id == article_type:
                    fields = data.get("fields") if isinstance(data.get("fields"), dict) else {}
                    fields.setdefault("author_name", "Équipe DEC CMS" if lang == "fr" else ("DEC CMS team" if lang == "en" else "DEC CMS Team"))
                    data["fields"] = fields
                if content_type_id == page_type and entry_key == "articles":
                    fields = data.get("fields") if isinstance(data.get("fields"), dict) else {}
                    fields.setdefault("article_limit", 6)
                    fields.setdefault("tag_limit", 12)
                    fields.setdefault("article_show_published_date", True)
                    fields.setdefault("article_show_author", False)
                    data["fields"] = fields
                if not isinstance(data.get("blocks"), list):
                    if content_type_id == page_type:
                        data["blocks"] = demo_page_blocks(
                            lang,
                            str(data.get("title", "")),
                            str(data.get("lead", "")),
                            str(data.get("main_text", "")),
                            entry_key,
                        )
                    else:
                        data["blocks"] = demo_article_blocks(str(data.get("main_text", "")))
            entry_id = insert_multilingual_entry(c, site_id, content_type_id, entry_key, translations, user_id)
            entry_ids_by_key[entry_key] = entry_id
            for sort_order, term_key in enumerate(article_taxonomy_assignments.get(entry_key, []), start=1):
                term_id = term_ids_by_key.get(term_key)
                if term_id:
                    c.execute(
                        "INSERT INTO content_entry_taxonomy_terms(entry_id, term_id, sort_order) VALUES(?,?,?)",
                        (entry_id, term_id, sort_order),
                    )
        def insert_menu(menu_key: str, name: str, location: str, entries: list[tuple[str, dict[str, str], str | None]]) -> None:
            c.execute(
                "INSERT INTO menus(site_id, menu_key, name, menu_location, language_mode, is_active) VALUES(?,?,?,?,?,1)",
                (site_id, menu_key, name, location, "shared"),
            )
            menu_id = c.lastrowid
            for pos, (entry_key, labels, manual_url) in enumerate(entries, start=1):
                if entry_key:
                    resource_id = entry_ids_by_key[entry_key]
                    c.execute(
                        "INSERT INTO menu_items(menu_id, resource_type, resource_id, sort_order, is_active) VALUES(?,?,?,?,1)",
                        (menu_id, "content_entry", resource_id, pos * 10),
                    )
                else:
                    c.execute(
                        "INSERT INTO menu_items(menu_id, manual_url, sort_order, is_active) VALUES(?,?,?,1)",
                        (menu_id, manual_url or "#", pos * 10),
                    )
                item_id = c.lastrowid
                for lang, label in labels.items():
                    c.execute("INSERT INTO menu_item_localizations(menu_item_id, language_code, label) VALUES(?,?,?)", (item_id, lang, label))

        insert_menu("primary", "Navigation principale", "header", [
            ("home", {"fr": "Accueil", "en": "Home", "de": "Startseite"}, None),
            ("articles", {"fr": "Articles", "en": "Articles", "de": "Artikel"}, None),
            ("contact", {"fr": "Contact", "en": "Contact", "de": "Kontakt"}, None),
        ])
        insert_menu("footer_top", "Pied de page — légal", "footer_top", [
            ("legal", {"fr": "Mentions légales", "en": "Legal notice", "de": "Impressum"}, None),
            ("privacy", {"fr": "Politique de confidentialité", "en": "Privacy policy", "de": "Datenschutzerklärung"}, None),
            ("cookies", {"fr": "Informations cookies", "en": "Cookie information", "de": "Cookie-Informationen"}, None),
        ])
        insert_menu("footer_bottom", "Pied de page — secondaire", "footer_bottom", [
            ("contact", {"fr": "Contact", "en": "Contact", "de": "Kontakt"}, None),
            ("", {"fr": "Plan du site", "en": "Sitemap", "de": "Sitemap"}, "/sitemap"),
        ])

        rebuild_seed_public_projections(c)
        rebuild_seed_taxonomy_projections(c)
        core.commit()
        sanitize_existing_public_media_urls()
    finally:
        core.close()
        iam.close()


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Injecte les donnees de demonstration locales.")
    parser.add_argument(
        "--skip-projections",
        action="store_true",
        help="N'appelle pas la commande PHP projections:rebuild apres le seed.",
    )
    parser.add_argument(
        "--allow-missing-php",
        action="store_true",
        help="N'echoue pas si PHP CLI manque; utile uniquement pour preparer une DB sans projections.",
    )
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    try:
        seed()
    except Exception as exc:  # noqa: BLE001
        print(f"ERREUR seed: {exc}", file=sys.stderr)
        return 1

    def sync_native_blueprints() -> int:
        proc = subprocess.run([sys.executable, str(NATIVE_BLUEPRINTS_SCRIPT)], cwd=BASE)
        return int(proc.returncode)

    code = sync_native_blueprints()
    if code != 0:
        return code

    if not args.skip_projections:
        code = rebuild_public_projections(required=not args.allow_missing_php)
        if code != 0:
            return code

        # Le bootstrap PHP peut resynchroniser les déclarations des modules.
        # La source native doit donc être réappliquée en dernière étape afin que
        # module_blueprints reflète l'état publié des modules système activés.
        code = sync_native_blueprints()
        if code != 0:
            return code

    print("Seed complete.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
