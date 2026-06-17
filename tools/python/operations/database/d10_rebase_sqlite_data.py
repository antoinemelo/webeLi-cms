#!/usr/bin/env python3
"""Rebase contrôlé des données SQLite sur une structure de référence.

Objectif : construire une nouvelle base à partir d'une structure récente
(target-structure) et des données vivantes d'une base source (source-data), sans
modifier les fichiers d'entrée.

Exemple :

    python3 tools/python/operations/database/d10_rebase_sqlite_data.py \
      --database core \
      --source-data /tmp/core_prod.sqlite \
      --target-structure /tmp/core_dev.sqlite \
      --output /tmp/core_rebased.sqlite \
      --plan

Puis :

    python3 tools/python/operations/database/d10_rebase_sqlite_data.py \
      --database core \
      --source-data /tmp/core_prod.sqlite \
      --target-structure /tmp/core_dev.sqlite \
      --output /tmp/core_rebased.sqlite \
      --apply

Le script est volontairement conservateur :
- il ne modifie jamais source-data ni target-structure ;
- il refuse d'écraser output sauf avec --overwrite ;
- il copie uniquement les colonnes communes ;
- il refuse les colonnes cible NOT NULL absentes de la source sans DEFAULT ;
- il préserve les tables natives de référence en les fusionnant sans écraser la
  version target ;
- il ignore par défaut les index FTS et tables techniques reconstruisibles ;
- il lance integrity_check et foreign_key_check sur la base produite.
"""
from __future__ import annotations

import argparse
import json
import shutil
import sqlite3
import sys
import tempfile
from dataclasses import dataclass
from pathlib import Path
from typing import Any

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())

DATABASE_FILES = {
    "core": ROOT / "storage" / "database" / "core.sqlite",
    "iam": ROOT / "storage" / "database" / "iam.sqlite",
    "forms": ROOT / "storage" / "database" / "forms.sqlite",
    "cookies": ROOT / "storage" / "database" / "cookies.sqlite",
}

# Tables reconstruites par les projecteurs ou par SQLite FTS. Elles ne doivent
# pas être importées aveuglément depuis une production ancienne.
TECHNICAL_SKIP_TABLES = {
    "search_documents",
    "search_documents_fts",
    "search_documents_fts_config",
    "search_documents_fts_content",
    "search_documents_fts_data",
    "search_documents_fts_docsize",
    "search_documents_fts_idx",
    "outbox_events",
    "system_jobs",
}
TECHNICAL_PREFIXES = (
    "sqlite_",
)

# Tables natives à conserver depuis target-structure. Les lignes de production
# sont ajoutées uniquement si leur clé primaire n'existe pas encore.
CORE_NATIVE_MERGE_TABLES = {
    "themes",
    "modules",
    "editor_block_types",
    "schema_field_types",
    "media_variant_presets",
}

# Tables qui ne doivent pas être recopiées entre bases par nature.
ALWAYS_SKIP_TABLES = {
    "schema_migrations",
}

APPEARANCE_DEFAULTS = {
    "main_heading_font_family": "heading",
    "main_heading_letter_spacing": "normal",
    "show_site_title_in_header": True,
}
SEO_DEFAULTS = {
    "redirect_to_primary_host": True,
    "force_https": True,
    "apple_touch_icon_required": True,
    "social_share_enabled": True,
    "single_h1_policy": True,
}


@dataclass(frozen=True)
class ColumnInfo:
    cid: int
    name: str
    type: str
    notnull: bool
    default: str | None
    pk: int


@dataclass
class TablePlan:
    name: str
    action: str
    source_rows: int = 0
    target_rows: int = 0
    common_columns: tuple[str, ...] = ()
    missing_target_columns: tuple[str, ...] = ()
    reason: str = ""


def q(identifier: str) -> str:
    return '"' + identifier.replace('"', '""') + '"'


def sqlite_uri(path: Path) -> str:
    return path.resolve().as_uri() + "?mode=ro"


def connect(path: Path) -> sqlite3.Connection:
    con = sqlite3.connect(path)
    con.row_factory = sqlite3.Row
    return con


def table_names(con: sqlite3.Connection, schema: str = "main") -> list[str]:
    rows = con.execute(
        f"SELECT name FROM {q(schema)}.sqlite_master WHERE type='table' ORDER BY name"
    ).fetchall()
    return [str(row[0]) for row in rows]


def table_info(con: sqlite3.Connection, table: str, schema: str = "main") -> list[ColumnInfo]:
    rows = con.execute(f"PRAGMA {q(schema)}.table_info({q(table)})").fetchall()
    return [
        ColumnInfo(
            cid=int(row[0]),
            name=str(row[1]),
            type=str(row[2] or ""),
            notnull=bool(row[3]),
            default=None if row[4] is None else str(row[4]),
            pk=int(row[5] or 0),
        )
        for row in rows
    ]


def row_count(con: sqlite3.Connection, table: str, schema: str = "main") -> int:
    try:
        return int(con.execute(f"SELECT COUNT(*) FROM {q(schema)}.{q(table)}").fetchone()[0])
    except sqlite3.Error:
        return 0


def primary_key_columns(columns: list[ColumnInfo]) -> list[str]:
    return [column.name for column in sorted(columns, key=lambda col: col.pk) if column.pk]


def is_generated_or_internal(table: str) -> bool:
    if table in ALWAYS_SKIP_TABLES:
        return True
    if table.startswith(TECHNICAL_PREFIXES):
        return True
    return False


def native_merge_tables(database: str) -> set[str]:
    if database == "core":
        return set(CORE_NATIVE_MERGE_TABLES)
    return set()


def technical_skip_tables(database: str) -> set[str]:
    if database == "core":
        return set(TECHNICAL_SKIP_TABLES)
    return set()


def analyse_copy_columns(
    source_columns: list[ColumnInfo],
    target_columns: list[ColumnInfo],
    table: str,
) -> tuple[tuple[str, ...], tuple[str, ...], list[str]]:
    source_by_name = {column.name: column for column in source_columns}
    common = tuple(column.name for column in target_columns if column.name in source_by_name)
    missing = tuple(column.name for column in target_columns if column.name not in source_by_name)
    errors: list[str] = []
    for column in target_columns:
        if column.name in source_by_name:
            continue
        # Une colonne NOT NULL absente de la source est acceptable uniquement si
        # SQLite peut la remplir par DEFAULT ou si c'est une clé primaire rowid.
        is_integer_pk = column.pk and "INT" in column.type.upper()
        if column.notnull and column.default is None and not is_integer_pk:
            errors.append(
                f"{table}.{column.name}: colonne NOT NULL sans DEFAULT absente de la source"
            )
    if not common:
        errors.append(f"{table}: aucune colonne commune entre source et cible")
    return common, missing, errors


def build_plan(args: argparse.Namespace, target_path: Path) -> tuple[list[TablePlan], list[str]]:
    errors: list[str] = []
    plans: list[TablePlan] = []
    with connect(target_path) as con:
        con.execute("ATTACH DATABASE ? AS src", (sqlite_uri(args.source_data),))
        source_tables = set(table_names(con, "src"))
        target_tables = set(table_names(con, "main"))
        native_tables = native_merge_tables(args.database)
        skip_tables = technical_skip_tables(args.database)

        for table in sorted(target_tables):
            if is_generated_or_internal(table):
                plans.append(TablePlan(table, "skip", reason="table interne ou journal de migrations"))
                continue
            if table in skip_tables and not args.include_technical:
                plans.append(TablePlan(table, "skip", reason="table technique reconstruisible"))
                continue
            if table not in source_tables:
                plans.append(TablePlan(table, "keep", target_rows=row_count(con, table), reason="absente de la source"))
                continue

            source_columns = table_info(con, table, "src")
            target_columns = table_info(con, table, "main")
            common, missing, column_errors = analyse_copy_columns(source_columns, target_columns, table)
            errors.extend(column_errors)
            action = "merge-native" if table in native_tables and not args.copy_native else "replace"
            plans.append(
                TablePlan(
                    name=table,
                    action=action,
                    source_rows=row_count(con, table, "src"),
                    target_rows=row_count(con, table, "main"),
                    common_columns=common,
                    missing_target_columns=missing,
                    reason="table native conservée/fusionnée" if action == "merge-native" else "données vivantes recopiées depuis source",
                )
            )

        for table in sorted(source_tables - target_tables):
            if is_generated_or_internal(table):
                continue
            plans.append(TablePlan(table, "ignore-source", source_rows=row_count(con, table, "src"), reason="absente de la structure cible"))
    return plans, errors


def print_plan(plans: list[TablePlan], errors: list[str]) -> None:
    print("[d10 rebase sqlite data] plan")
    for plan in plans:
        detail = f"{plan.name}: {plan.action}"
        if plan.source_rows or plan.target_rows:
            detail += f" (source={plan.source_rows}, cible={plan.target_rows})"
        if plan.missing_target_columns:
            detail += " ; colonnes cible remplies par défaut/NULL: " + ", ".join(plan.missing_target_columns)
        if plan.reason:
            detail += f" ; {plan.reason}"
        print("- " + detail)
    if errors:
        print("\nERREURS bloquantes:", file=sys.stderr)
        for error in errors:
            print("- " + error, file=sys.stderr)


def execute_replace(con: sqlite3.Connection, plan: TablePlan) -> int:
    columns_sql = ", ".join(q(column) for column in plan.common_columns)
    con.execute(f"DELETE FROM {q(plan.name)}")
    con.execute(
        f"INSERT INTO {q(plan.name)} ({columns_sql}) "
        f"SELECT {columns_sql} FROM src.{q(plan.name)}"
    )
    return int(con.execute(f"SELECT COUNT(*) FROM {q(plan.name)}").fetchone()[0])


def execute_merge_native(con: sqlite3.Connection, plan: TablePlan) -> int:
    target_columns = table_info(con, plan.name, "main")
    pk_columns = primary_key_columns(target_columns)
    if not pk_columns:
        # Sans clé primaire stable, on conserve la table target telle quelle.
        return int(con.execute(f"SELECT COUNT(*) FROM {q(plan.name)}").fetchone()[0])
    columns_sql = ", ".join(q(column) for column in plan.common_columns)
    where = " AND ".join(f"target.{q(column)} = src.{q(column)}" for column in pk_columns)
    con.execute(
        f"INSERT INTO {q(plan.name)} ({columns_sql}) "
        f"SELECT {columns_sql} FROM src.{q(plan.name)} AS src "
        f"WHERE NOT EXISTS (SELECT 1 FROM {q(plan.name)} AS target WHERE {where})"
    )
    return int(con.execute(f"SELECT COUNT(*) FROM {q(plan.name)}").fetchone()[0])



def trigger_definitions(con: sqlite3.Connection) -> list[tuple[str, str]]:
    rows = con.execute(
        "SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND sql IS NOT NULL ORDER BY name"
    ).fetchall()
    return [(str(row[0]), str(row[1])) for row in rows]


def drop_triggers(con: sqlite3.Connection, triggers: list[tuple[str, str]]) -> None:
    for name, _sql in triggers:
        con.execute(f"DROP TRIGGER IF EXISTS {q(name)}")


def recreate_triggers(con: sqlite3.Connection, triggers: list[tuple[str, str]]) -> None:
    for _name, sql in triggers:
        con.execute(sql)


def update_sqlite_sequence(con: sqlite3.Connection, plans: list[TablePlan]) -> None:
    has_sequence = "sqlite_sequence" in set(table_names(con, "main"))
    has_source_sequence = "sqlite_sequence" in set(table_names(con, "src"))
    if not has_sequence:
        return
    for plan in plans:
        if plan.action not in {"replace", "merge-native"}:
            continue
        seq = None
        if has_source_sequence:
            row = con.execute("SELECT seq FROM src.sqlite_sequence WHERE name = ?", (plan.name,)).fetchone()
            if row is not None:
                seq = int(row[0])
        pk_columns = primary_key_columns(table_info(con, plan.name, "main"))
        if seq is None and len(pk_columns) == 1:
            try:
                row = con.execute(f"SELECT MAX({q(pk_columns[0])}) FROM {q(plan.name)}").fetchone()
                seq = 0 if row[0] is None else int(row[0])
            except (sqlite3.Error, TypeError, ValueError):
                seq = None
        if seq is None:
            continue
        con.execute("DELETE FROM sqlite_sequence WHERE name = ?", (plan.name,))
        con.execute("INSERT INTO sqlite_sequence(name, seq) VALUES (?, ?)", (plan.name, seq))


def load_json_object(raw: str | None) -> dict[str, Any]:
    if not raw:
        return {}
    try:
        value = json.loads(raw)
    except json.JSONDecodeError:
        return {}
    return value if isinstance(value, dict) else {}


def dump_json_object(value: dict[str, Any]) -> str:
    return json.dumps(value, ensure_ascii=False, separators=(",", ":"))


def apply_core_backfills(con: sqlite3.Connection) -> int:
    changed = 0
    tables = set(table_names(con, "main"))

    if "site_settings" in tables:
        rows = con.execute(
            "SELECT id, namespace, setting_key, value_json FROM site_settings "
            "WHERE (namespace = 'public_ui' AND setting_key = 'defaults') "
            "OR (namespace = 'seo' AND setting_key = 'defaults')"
        ).fetchall()
        for row in rows:
            data = load_json_object(row["value_json"])
            before = dump_json_object(data)
            if row["namespace"] == "public_ui":
                for key, value in APPEARANCE_DEFAULTS.items():
                    data.setdefault(key, value)
            elif row["namespace"] == "seo":
                for key, value in SEO_DEFAULTS.items():
                    data.setdefault(key, value)
            after = dump_json_object(data)
            if after != before:
                con.execute("UPDATE site_settings SET value_json = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", (after, row["id"]))
                changed += 1

    if "themes" in tables:
        rows = con.execute("SELECT id, theme_key, config_json FROM themes").fetchall()
        for row in rows:
            data = load_json_object(row["config_json"])
            defaults = data.get("appearance_defaults")
            if not isinstance(defaults, dict):
                defaults = {}
                data["appearance_defaults"] = defaults
            before = dump_json_object(data)
            theme_key = str(row["theme_key"] or "")
            for key, value in APPEARANCE_DEFAULTS.items():
                defaults.setdefault(key, value)
            if theme_key in {"aurora", "pulse"}:
                defaults.setdefault("main_heading_font_family", "arial")
                defaults["main_heading_letter_spacing"] = defaults.get("main_heading_letter_spacing") or "normal"
            after = dump_json_object(data)
            if after != before:
                con.execute("UPDATE themes SET config_json = ? WHERE id = ?", (after, row["id"]))
                changed += 1

    return changed


def apply_backfills(con: sqlite3.Connection, database: str) -> int:
    if database == "core":
        return apply_core_backfills(con)
    return 0


def validate_database(con: sqlite3.Connection) -> list[str]:
    errors: list[str] = []
    integrity_rows = con.execute("PRAGMA integrity_check").fetchall()
    integrity_values = [str(row[0]) for row in integrity_rows]
    if integrity_values != ["ok"]:
        errors.append("integrity_check: " + "; ".join(integrity_values[:5]))
    fk_rows = con.execute("PRAGMA foreign_key_check").fetchall()
    if fk_rows:
        preview = []
        for row in fk_rows[:10]:
            preview.append("/".join(str(value) for value in row))
        errors.append("foreign_key_check: " + "; ".join(preview))
    return errors


def ensure_paths(args: argparse.Namespace) -> tuple[Path, Path, Path]:
    source = args.source_data.resolve()
    target = args.target_structure.resolve()
    output = args.output.resolve()
    if not source.exists():
        raise FileNotFoundError(f"source-data introuvable: {source}")
    if not target.exists():
        raise FileNotFoundError(f"target-structure introuvable: {target}")
    if output.exists() and not args.overwrite and args.apply:
        raise FileExistsError(f"output existe déjà: {output} (utiliser --overwrite)")
    if output.resolve() in {source, target}:
        raise ValueError("output doit être différent de source-data et target-structure")
    return source, target, output


def apply_rebase(args: argparse.Namespace, plans: list[TablePlan]) -> Path:
    source, target, output = ensure_paths(args)
    output.parent.mkdir(parents=True, exist_ok=True)
    if output.exists():
        output.unlink()
    # Copie atomique approximative : travail dans un fichier temporaire du même dossier.
    with tempfile.NamedTemporaryFile(prefix=output.name + ".", suffix=".tmp", dir=str(output.parent), delete=False) as tmp:
        tmp_path = Path(tmp.name)
    try:
        shutil.copy2(target, tmp_path)
        with connect(tmp_path) as con:
            con.isolation_level = None
            con.execute("PRAGMA foreign_keys = OFF")
            con.execute("ATTACH DATABASE ? AS src", (sqlite_uri(source),))
            con.execute("BEGIN IMMEDIATE")
            try:
                triggers = trigger_definitions(con)
                if triggers:
                    drop_triggers(con, triggers)
                for plan in plans:
                    if plan.action == "replace":
                        count = execute_replace(con, plan)
                        print(f"replace {plan.name}: {count} ligne(s)")
                    elif plan.action == "merge-native":
                        count = execute_merge_native(con, plan)
                        print(f"merge-native {plan.name}: {count} ligne(s) conservée(s)/fusionnée(s)")
                update_sqlite_sequence(con, plans)
                backfills = apply_backfills(con, args.database)
                if backfills:
                    print(f"backfills JSON natifs: {backfills} ligne(s) ajustée(s)")
                if triggers:
                    recreate_triggers(con, triggers)
                    print(f"triggers restaurés: {len(triggers)}")
                con.execute("COMMIT")
            except Exception:
                con.execute("ROLLBACK")
                raise
            con.execute("DETACH DATABASE src")
            con.execute("PRAGMA foreign_keys = ON")
            validation_errors = validate_database(con)
            if validation_errors:
                raise RuntimeError("; ".join(validation_errors))
        tmp_path.replace(output)
        return output
    except Exception:
        if tmp_path.exists():
            tmp_path.unlink()
        raise


def default_database_path(database: str) -> Path:
    return DATABASE_FILES[database]


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Construit une base SQLite neuve avec structure cible récente et données source existantes."
    )
    parser.add_argument("--database", choices=sorted(DATABASE_FILES), required=True, help="Profil de base à traiter.")
    parser.add_argument("--source-data", type=Path, required=True, help="Base contenant les données vivantes à importer.")
    parser.add_argument("--target-structure", type=Path, required=True, help="Base servant de structure/native defaults de référence.")
    parser.add_argument("--output", type=Path, required=True, help="Base produite. Ne doit pas être source-data ni target-structure.")
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--plan", action="store_true", help="Affiche le plan sans créer la base output.")
    mode.add_argument("--apply", action="store_true", help="Crée la base output.")
    parser.add_argument("--overwrite", action="store_true", help="Autorise l'écrasement du fichier output.")
    parser.add_argument("--include-technical", action="store_true", help="Copie aussi les tables techniques reconstruisibles. Déconseillé.")
    parser.add_argument("--copy-native", action="store_true", help="Remplace aussi les tables natives par celles de la source. Déconseillé.")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    try:
        source, target, output = ensure_paths(args)
        plans, errors = build_plan(args, target)
        print_plan(plans, errors)
        if errors:
            return 1
        if args.plan:
            print("\nPlan uniquement. Aucune base n'a été modifiée.")
            return 0
        result = apply_rebase(args, plans)
        print(f"\nOK Base produite: {result}")
        print("À faire ensuite si la base est installée dans storage/database/:")
        print("- python3 tools/python/operations/database/b3_rebuild_search.py")
        print("- python3 tools/cms.py validate --category database")
        print("- python3 tools/cms.py validate --full --validator RUNTIME_INTEGRITY")
        return 0
    except Exception as exc:
        print(f"ERREUR: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
