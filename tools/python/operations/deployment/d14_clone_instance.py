#!/usr/bin/env python3
"""Clone a local CMS instance into another directory.

The destination directory and the public runtime configuration are deliberately
separate. This allows workflows such as creating ``../mod2`` while keeping the
clone configured as ``/cms`` for a later filesystem rename.
"""
from __future__ import annotations

import argparse
import os
import re
import shutil
import sqlite3
import sys
from dataclasses import dataclass, field
from fnmatch import fnmatch
from pathlib import Path
from typing import Iterable


ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())

DEFAULT_EXCLUDES = [
    ".agents",
    ".agents/*",
    ".codex",
    ".codex/*",
    ".git",
    ".git/*",
    ".github",
    ".github/*",
    ".DS_Store",
    "Thumbs.db",
    "README.md",
    "TREE.txt",
    "docs",
    "docs/*",
    "docs/**",
    "tests",
    "tests/*",
    "tests/**",
    "tools/tests/.venv",
    "tools/tests/.venv/*",
    "tools/tests/.venv/**",
    "tools/tests/users.csv",
    "tools/tests/*_credentials.csv",
    "tools/tests/*.zip",
    "tools/tests/webeLi-evidence-*",
    "tools/tests/webeLi-evidence-*/**",
    "frontend/admin-vue",
    "frontend/admin-vue/*",
    "frontend/admin-vue/**",
    "node_modules",
    "node_modules/*",
    "node_modules/**",
    "frontend/admin-vue/node_modules",
    "frontend/admin-vue/node_modules/*",
    "frontend/admin-vue/node_modules/**",
    "storage/cache/*",
    "storage/cache/**",
    "storage/logs/*.log",
    "storage/exports",
    "storage/exports/*",
    "storage/exports/**",
    "storage/deployments/current.json",
    "storage/deployments/history.ndjson",
    "storage/deployments/release-manifest.json",
    "storage/deployments/*.json",
    "storage/deployments/*.ndjson",
    "storage/deployments/releases/*",
    "storage/deployments/releases/**",
    "**/__pycache__",
    "**/__pycache__/*",
    "**/*.pyc",
    "admin-app/assets/*.js.map",
    "ops/ftp.deploy.json",
    "ops/*.example",
    "ops/_env.example",
    "ops/.env.example",
]

KEEP_FILES = {
    "storage/cache/.gitkeep",
    "storage/cache/twig/.gitkeep",
    "storage/logs/.gitkeep",
    "storage/media/.gitkeep",
    "storage/media/public/.gitkeep",
    "storage/media/quarantine/.gitkeep",
    "storage/database/.gitkeep",
    "storage/deployments/.gitkeep",
    "storage/security/.gitkeep",
}

TEXT_EXTENSIONS = {
    "",
    ".env",
    ".example",
    ".htaccess",
    ".php",
    ".phtml",
    ".twig",
    ".html",
    ".css",
    ".js",
    ".json",
    ".md",
    ".txt",
    ".xml",
    ".yml",
    ".yaml",
    ".sql",
    ".ini",
    ".conf",
    ".lock",
}

BINARY_SUFFIXES = {
    ".sqlite",
    ".db",
    ".png",
    ".jpg",
    ".jpeg",
    ".gif",
    ".webp",
    ".svgz",
    ".ico",
    ".pdf",
    ".zip",
    ".gz",
    ".tar",
    ".woff",
    ".woff2",
    ".ttf",
    ".eot",
    ".mp4",
    ".mov",
    ".mp3",
}


@dataclass
class Stats:
    copied_files: int = 0
    copied_dirs: int = 0
    skipped: int = 0
    text_files_changed: int = 0
    text_replacements: int = 0
    sqlite_files_changed: int = 0
    sqlite_cells_changed: int = 0
    sqlite_tables_touched: list[str] = field(default_factory=list)
    sqlite_tables_skipped: list[str] = field(default_factory=list)


def normalize_public_base_path(value: str) -> str:
    cleaned = value.strip()
    if cleaned in {"", "/"}:
        return ""
    return "/" + cleaned.strip("/")


def default_base_path_from_dir(path: Path) -> str:
    return normalize_public_base_path(path.name)


def is_relative_to(path: Path, parent: Path) -> bool:
    try:
        path.resolve().relative_to(parent.resolve())
        return True
    except ValueError:
        return False


def should_exclude(rel: str, patterns: Iterable[str]) -> bool:
    rel = rel.replace(os.sep, "/").strip("/")
    for pattern in patterns:
        pattern = pattern.strip("/")
        if fnmatch(rel, pattern):
            return True
        if pattern.endswith("/**") and rel.startswith(pattern[:-3].rstrip("/") + "/"):
            return True
        if pattern.endswith("/*") and rel.startswith(pattern[:-2].rstrip("/") + "/"):
            return True
    return False


def ensure_empty_destination(destination: Path, force: bool) -> None:
    if destination.exists():
        if not force:
            raise RuntimeError(f"Destination already exists: {destination}. Use --force to replace it.")
        shutil.rmtree(destination)
    destination.mkdir(parents=True, exist_ok=True)


def copy_runtime_tree(source: Path, destination: Path, excludes: list[str], stats: Stats, dry_run: bool) -> None:
    for item in sorted(source.rglob("*")):
        rel = item.relative_to(source).as_posix()
        excluded = False if rel in KEEP_FILES else should_exclude(rel, excludes)
        if excluded:
            stats.skipped += 1
            continue

        target = destination / rel
        if item.is_dir():
            stats.copied_dirs += 1
            if not dry_run:
                target.mkdir(parents=True, exist_ok=True)
            continue

        stats.copied_files += 1
        if not dry_run:
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(item, target)


def env_template_source(source: Path) -> Path | None:
    for candidate in (
        source / "ops" / ".env",
        source / "ops" / ".env.example",
        source / "ops" / "_env.example",
    ):
        if candidate.is_file():
            return candidate
    return None


def upsert_env_value(content: str, key: str, value: str) -> str:
    lines = content.splitlines()
    done = False
    output: list[str] = []
    for line in lines:
        if line.startswith(f"{key}="):
            output.append(f"{key}={value}")
            done = True
        else:
            output.append(line)
    if not done:
        output.append(f"{key}={value}")
    return "\n".join(output).rstrip() + "\n"


def env_value(content: str, key: str) -> str | None:
    for line in content.splitlines():
        if line.startswith(f"{key}="):
            return line.split("=", 1)[1].strip().strip('"')
    return None


def public_base_replace(text: str, old_base_path: str, new_base_path: str) -> tuple[str, int]:
    """Replace a public base path without corrupting words such as modules."""
    if not old_base_path or old_base_path == new_base_path:
        return text, 0

    boundary = r"(?=$|[/?#&\"'\s<>\)\]\},;:])"
    pattern = re.compile(re.escape(old_base_path) + boundary)
    count = 0

    def repl(match: re.Match[str]) -> str:
        nonlocal count
        count += 1
        return new_base_path

    return pattern.sub(repl, text), count


def apply_replacements_to_text(
    text: str,
    *,
    old_base_path: str,
    new_base_path: str,
    old_abs_path: str,
    new_abs_path: str,
) -> tuple[str, int]:
    updated = text
    count = 0

    if old_abs_path and old_abs_path != new_abs_path:
        replacements = updated.count(old_abs_path)
        if replacements:
            updated = updated.replace(old_abs_path, new_abs_path)
            count += replacements

    updated, replacements = public_base_replace(updated, old_base_path, new_base_path)
    count += replacements
    return updated, count


def ensure_env_file(
    source: Path,
    destination: Path,
    old_base_path: str,
    new_base_path: str,
    old_abs_path: str,
    new_abs_path: str,
    new_public_base_url: str | None,
    dry_run: bool,
) -> None:
    template = env_template_source(source)
    if template is None:
        content = "APP_ENV=production\nAPP_DEBUG=0\n"
    else:
        content = template.read_text(encoding="utf-8", errors="replace")

    content, _ = apply_replacements_to_text(
        content,
        old_base_path=old_base_path,
        new_base_path=new_base_path,
        old_abs_path=old_abs_path,
        new_abs_path=new_abs_path,
    )
    content = upsert_env_value(content, "APP_BASE_PATH", new_base_path or "/")
    if new_public_base_url:
        content = upsert_env_value(content, "APP_PUBLIC_BASE_URL", new_public_base_url.rstrip("/"))
    elif env_value(content, "APP_PUBLIC_BASE_URL") is None:
        content = upsert_env_value(content, "APP_PUBLIC_BASE_URL", new_base_path or "/")

    env_path = destination / "ops" / ".env"
    if not dry_run:
        env_path.parent.mkdir(parents=True, exist_ok=True)
        env_path.write_text(content, encoding="utf-8")


def is_text_candidate(path: Path) -> bool:
    suffix = path.suffix.lower()
    if suffix in BINARY_SUFFIXES:
        return False
    if suffix in TEXT_EXTENSIONS:
        return True
    if not suffix and path.stat().st_size < 2_000_000:
        return True
    return False


def replace_in_text_files(
    destination: Path,
    old_base_path: str,
    new_base_path: str,
    old_abs_path: str,
    new_abs_path: str,
    stats: Stats,
    dry_run: bool,
) -> None:
    for path in sorted(destination.rglob("*")):
        if not path.is_file() or not is_text_candidate(path):
            continue
        try:
            original = path.read_text(encoding="utf-8")
        except UnicodeDecodeError:
            continue

        updated, count = apply_replacements_to_text(
            original,
            old_base_path=old_base_path,
            new_base_path=new_base_path,
            old_abs_path=old_abs_path,
            new_abs_path=new_abs_path,
        )
        if updated != original:
            stats.text_files_changed += 1
            stats.text_replacements += count
            if not dry_run:
                path.write_text(updated, encoding="utf-8")


def detect_suspicious_path_rewrites(destination: Path, new_dir_name: str) -> list[str]:
    suspicious: list[str] = []
    bad_tokens = [f"{new_dir_name}ules.php", f"{new_dir_name}ules"]
    for path in sorted(destination.rglob("*")):
        if not path.is_file() or not is_text_candidate(path):
            continue
        try:
            content = path.read_text(encoding="utf-8")
        except UnicodeDecodeError:
            continue
        for token in bad_tokens:
            if token and token in content:
                suspicious.append(f"{path.relative_to(destination).as_posix()} contains {token!r}")
                break
    return suspicious


def quote_identifier(identifier: str) -> str:
    return '"' + identifier.replace('"', '""') + '"'


def sqlite_tables(connection: sqlite3.Connection) -> list[tuple[str, str]]:
    rows = connection.execute(
        "SELECT name, COALESCE(sql, '') "
        "FROM sqlite_master "
        "WHERE type='table' AND name NOT LIKE 'sqlite_%' "
        "ORDER BY name"
    ).fetchall()
    return [(str(name), str(sql or "")) for name, sql in rows]


def is_virtual_or_shadow_table(table_name: str, create_sql: str) -> bool:
    sql_upper = create_sql.upper()
    if "CREATE VIRTUAL TABLE" in sql_upper or "USING FTS" in sql_upper:
        return True
    shadow_suffixes = (
        "_fts",
        "_fts_config",
        "_fts_content",
        "_fts_data",
        "_fts_docsize",
        "_fts_idx",
        "_config",
        "_content",
        "_data",
        "_docsize",
        "_idx",
    )
    return table_name.endswith(shadow_suffixes)


def sqlite_columns(connection: sqlite3.Connection, table: str) -> list[dict[str, object]]:
    rows = connection.execute(f"PRAGMA table_info({quote_identifier(table)})").fetchall()
    return [
        {
            "cid": int(row[0]),
            "name": str(row[1]),
            "type": str(row[2] or ""),
            "notnull": int(row[3]),
            "default": row[4],
            "pk": int(row[5]),
        }
        for row in rows
    ]


def is_sqlite_text_type(declared_type: str) -> bool:
    value = declared_type.upper()
    return value == "" or "TEXT" in value or "CHAR" in value or "CLOB" in value or "VARCHAR" in value or "JSON" in value


def replace_in_sqlite_table(
    connection: sqlite3.Connection,
    db_name: str,
    table: str,
    old_base_path: str,
    new_base_path: str,
    old_abs_path: str,
    new_abs_path: str,
    stats: Stats,
    dry_run: bool,
) -> int:
    columns = sqlite_columns(connection, table)
    pk_columns = sorted([c for c in columns if int(c["pk"]) > 0], key=lambda c: int(c["pk"]))
    text_columns = [c for c in columns if is_sqlite_text_type(str(c["type"]))]
    if not text_columns:
        return 0
    if not pk_columns:
        stats.sqlite_tables_skipped.append(f"{db_name}:{table} (no explicit primary key)")
        return 0

    selected_names = [str(c["name"]) for c in pk_columns + text_columns]
    rows = connection.execute(
        "SELECT "
        + ", ".join(quote_identifier(name) for name in selected_names)
        + f" FROM {quote_identifier(table)}"
    ).fetchall()
    changed_cells = 0
    pk_count = len(pk_columns)
    text_count = len(text_columns)

    for row in rows:
        pk_values = row[:pk_count]
        text_values = row[pk_count : pk_count + text_count]
        updates: list[tuple[str, str]] = []

        for column, value in zip(text_columns, text_values):
            if value is None or not isinstance(value, str):
                continue
            updated, _count = apply_replacements_to_text(
                value,
                old_base_path=old_base_path,
                new_base_path=new_base_path,
                old_abs_path=old_abs_path,
                new_abs_path=new_abs_path,
            )
            if updated != value:
                updates.append((str(column["name"]), updated))
                changed_cells += 1

        if updates and not dry_run:
            set_sql = ", ".join(f"{quote_identifier(name)} = ?" for name, _ in updates)
            where_sql = " AND ".join(f"{quote_identifier(str(c['name']))} = ?" for c in pk_columns)
            params = [value for _, value in updates] + list(pk_values)
            connection.execute(f"UPDATE {quote_identifier(table)} SET {set_sql} WHERE {where_sql}", params)

    return changed_cells


def replace_in_sqlite_file(
    db_path: Path,
    old_base_path: str,
    new_base_path: str,
    old_abs_path: str,
    new_abs_path: str,
    stats: Stats,
    dry_run: bool,
) -> None:
    if not db_path.exists() or db_path.stat().st_size == 0:
        return

    changed_cells_total = 0
    touched_tables: list[str] = []
    uri = f"file:{db_path.as_posix()}?mode=ro" if dry_run else str(db_path)
    connection = sqlite3.connect(uri, uri=dry_run)
    try:
        connection.execute("PRAGMA foreign_keys=OFF")
        for table, create_sql in sqlite_tables(connection):
            if is_virtual_or_shadow_table(table, create_sql):
                stats.sqlite_tables_skipped.append(f"{db_path.name}:{table} (virtual/shadow table)")
                continue
            changed = replace_in_sqlite_table(
                connection,
                db_path.name,
                table,
                old_base_path,
                new_base_path,
                old_abs_path,
                new_abs_path,
                stats,
                dry_run,
            )
            if changed:
                changed_cells_total += changed
                touched_tables.append(table)

        if not dry_run:
            connection.commit()
            if changed_cells_total:
                connection.execute("VACUUM")
    finally:
        connection.close()

    if changed_cells_total:
        stats.sqlite_files_changed += 1
        stats.sqlite_cells_changed += changed_cells_total
        stats.sqlite_tables_touched.extend(f"{db_path.name}:{table}" for table in touched_tables)


def replace_in_sqlite_databases(
    destination: Path,
    old_base_path: str,
    new_base_path: str,
    old_abs_path: str,
    new_abs_path: str,
    stats: Stats,
    dry_run: bool,
) -> None:
    db_dir = destination / "storage" / "database"
    if not db_dir.exists():
        return
    for db_path in sorted(db_dir.glob("*.sqlite")):
        replace_in_sqlite_file(db_path, old_base_path, new_base_path, old_abs_path, new_abs_path, stats, dry_run)


def ensure_runtime_directories(destination: Path, dry_run: bool) -> None:
    dirs = [
        "storage/database",
        "storage/cache",
        "storage/cache/twig",
        "storage/logs",
        "storage/media/public",
        "storage/media/quarantine",
        "storage/deployments",
        "storage/security",
    ]
    for rel in dirs:
        path = destination / rel
        if not dry_run:
            path.mkdir(parents=True, exist_ok=True)
            (path / ".gitkeep").touch(exist_ok=True)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Clone a local DEC CMS instance into another directory.")
    parser.add_argument("--source", type=Path, default=ROOT, help="Source instance directory. Default: current project root.")
    parser.add_argument("--destination", type=Path, required=True, help="Destination directory to create, for example ../mod2 or ../eve.")
    parser.add_argument("--old-base-path", default=None, help="Source public APP_BASE_PATH. Default: source directory name.")
    parser.add_argument("--new-base-path", "--target-base-path", dest="new_base_path", default=None, help="Public APP_BASE_PATH written into the clone. Default: destination directory name.")
    parser.add_argument("--new-public-base-url", default=None, help="Exact APP_PUBLIC_BASE_URL to write into ops/.env.")
    parser.add_argument("--force", action="store_true", help="Replace destination if it already exists.")
    parser.add_argument("--dry-run", action="store_true", help="Show planned work without writing files.")
    parser.add_argument("--include-dev-admin-vue", action="store_true", help="Include frontend/admin-vue sources.")
    parser.add_argument("--include-docs", action="store_true", help="Include docs/, README.md and TREE.txt.")
    return parser.parse_args()


def build_excludes(include_dev_admin_vue: bool, include_docs: bool) -> list[str]:
    excludes = list(DEFAULT_EXCLUDES)
    if include_dev_admin_vue:
        excludes = [item for item in excludes if not item.startswith("frontend/admin-vue")]
    if include_docs:
        excludes = [item for item in excludes if item not in {"README.md", "TREE.txt", "docs", "docs/*", "docs/**"}]
    return excludes


def main() -> int:
    args = parse_args()
    source = args.source.expanduser().resolve()
    destination = args.destination.expanduser().resolve()

    if not source.exists() or not source.is_dir():
        raise RuntimeError(f"Source not found or invalid: {source}")
    if source == destination or is_relative_to(destination, source):
        raise RuntimeError("Destination must not be identical to the source or placed inside the source.")

    old_base_path = normalize_public_base_path(args.old_base_path) if args.old_base_path else default_base_path_from_dir(source)
    new_base_path = normalize_public_base_path(args.new_base_path) if args.new_base_path else default_base_path_from_dir(destination)
    old_abs_path = str(source)
    new_abs_path = str(destination)
    stats = Stats()

    print(f"Source directory       : {source}")
    print(f"Destination directory  : {destination}")
    print(f"Source public base path: {old_base_path or '/'}")
    print(f"Target public base path: {new_base_path or '/'}")
    if args.new_public_base_url:
        print(f"Target public base URL : {args.new_public_base_url.rstrip('/')}")
    if destination.name != new_base_path.strip("/"):
        print("Directory name and public base path are intentionally different.")
    if args.dry_run:
        print("Mode                   : dry-run")

    if not args.dry_run:
        ensure_empty_destination(destination, force=args.force)

    excludes = build_excludes(args.include_dev_admin_vue, args.include_docs)
    copy_runtime_tree(source, destination, excludes, stats, dry_run=args.dry_run)
    ensure_runtime_directories(destination, dry_run=args.dry_run)
    ensure_env_file(
        source,
        destination,
        old_base_path,
        new_base_path,
        old_abs_path,
        new_abs_path,
        args.new_public_base_url,
        dry_run=args.dry_run,
    )
    replace_in_text_files(destination, old_base_path, new_base_path, old_abs_path, new_abs_path, stats, dry_run=args.dry_run)
    replace_in_sqlite_databases(destination, old_base_path, new_base_path, old_abs_path, new_abs_path, stats, dry_run=args.dry_run)

    suspicious_rewrites = [] if args.dry_run else detect_suspicious_path_rewrites(destination, destination.name)
    if suspicious_rewrites:
        print("\nWARNING: suspicious rewrites detected:")
        for item in suspicious_rewrites[:20]:
            print(f"- {item}")
        print("These entries may indicate a public path replacement issue.")

    print("\nSummary")
    print(f"- copied files           : {stats.copied_files}")
    print(f"- copied directories     : {stats.copied_dirs}")
    print(f"- skipped entries        : {stats.skipped}")
    print(f"- changed text files     : {stats.text_files_changed}")
    print(f"- text replacements      : {stats.text_replacements}")
    print(f"- changed SQLite files   : {stats.sqlite_files_changed}")
    print(f"- changed SQLite cells   : {stats.sqlite_cells_changed}")
    if stats.sqlite_tables_touched:
        print("- touched SQLite tables  :")
        for item in stats.sqlite_tables_touched[:80]:
            print(f"  - {item}")
        if len(stats.sqlite_tables_touched) > 80:
            print(f"  - ... +{len(stats.sqlite_tables_touched) - 80} more")
    if stats.sqlite_tables_skipped:
        print("- skipped SQLite tables  :")
        for item in stats.sqlite_tables_skipped[:40]:
            print(f"  - {item}")
        if len(stats.sqlite_tables_skipped) > 40:
            print(f"  - ... +{len(stats.sqlite_tables_skipped) - 40} more")

    print("\nLocal instance clone ready." if not args.dry_run else "\nDry-run complete.")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        raise SystemExit(1)
