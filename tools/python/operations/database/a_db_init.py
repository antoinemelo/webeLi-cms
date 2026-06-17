#!/usr/bin/env python3
"""Initialisation locale des bases SQLite.

Creer/recreer uniquement les structures SQLite depuis
les fichiers SQL source. Les donnees injectees, qu'elles soient de reference ou
de demonstration, relevent des scripts de seed.

Commande recommandee pour retrouver les bases locales fournies par defaut::

    python3 tools/python/operations/database/a_db_init.py

Sans parametre, cette commande recree les structures, injecte le seed par defaut,
puis reconstruit les projections publiques/recherche via la chaine officielle.
Utiliser --structures-only pour obtenir des bases vides.
"""
from __future__ import annotations

import argparse
import sqlite3
import subprocess
import sys
from pathlib import Path

BASE = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
CORE_DB = BASE / "storage" / "database" / "core.sqlite"
IAM_DB = BASE / "storage" / "database" / "iam.sqlite"
FORMS_DB = BASE / "storage" / "database" / "forms.sqlite"
COOKIES_DB = BASE / "storage" / "database" / "cookies.sqlite"
AI_DB = BASE / "storage" / "database" / "ai.sqlite"
CORE_SQL = BASE / "database" / "schema" / "core.sql"
IAM_SQL = BASE / "database" / "iam.sql"
FORMS_SQL = BASE / "database" / "modules" / "forms.sql"
COOKIES_SQL = BASE / "database" / "modules" / "cookies.sql"
AI_SQL = BASE / "database" / "modules" / "ai.sql"
COOKIES_REFERENCE_SEED = BASE / "database" / "seeds" / "cookies_seed.sql"
AI_DEFAULT_SEED = BASE / "database" / "seeds" / "default" / "ai_default_seed.sql"
CORE_REFERENCE_SEED = BASE / "database" / "seeds" / "core_seed.sql"
IAM_REFERENCE_SEED = BASE / "database" / "seeds" / "iam_seed.sql"
SEED_SCRIPT = BASE / "tools" / "python" / "operations" / "database" / "b0_db_seed.py"
SQLITE_BUSY_TIMEOUT_MS = 5000

PRIVATE_HTACCESS = """Options -Indexes

<IfModule mod_authz_core.c>
    Require all denied
</IfModule>

<IfModule !mod_authz_core.c>
    Deny from all
</IfModule>
"""

MEDIA_STORAGE_DIRS = [
    BASE / "storage" / "database",
    BASE / "storage" / "logs",
    BASE / "storage" / "media",
    BASE / "storage" / "media" / "public",
    BASE / "storage" / "media" / "quarantine",
    BASE / "storage" / "cache",
    BASE / "storage" / "backups",
]

PRIVATE_STORAGE_DIRS = [
    BASE / "storage" / "database",
    BASE / "storage" / "logs",
    BASE / "storage" / "backups",
]


def configure_sqlite(connection: sqlite3.Connection, *, writable: bool = True) -> None:
    """Applique le profil SQLite natif du CMS pour les bases recreees localement."""
    connection.execute(f"PRAGMA busy_timeout = {SQLITE_BUSY_TIMEOUT_MS}")
    connection.execute("PRAGMA foreign_keys = ON")
    if writable:
        connection.execute("PRAGMA journal_mode = WAL")


def connect_sqlite(db_path: Path, *, writable: bool = True) -> sqlite3.Connection:
    connection = sqlite3.connect(db_path)
    configure_sqlite(connection, writable=writable)
    return connection


def run_sql_file(connection: sqlite3.Connection, sql_path: Path) -> None:
    if not sql_path.exists():
        raise FileNotFoundError(f"Fichier SQL introuvable: {sql_path}")
    sql = sql_path.read_text(encoding="utf-8").strip()
    if not sql:
        return

    # sqlite3.executescript() sur un gros schéma FTS + triggers peut être difficile
    # à diagnostiquer. L'exécution statement par statement rend l'init plus robuste
    # et localise précisément l'instruction fautive en cas d'erreur.
    buffer = ""
    for line in sql.splitlines(keepends=True):
        buffer += line
        if not sqlite3.complete_statement(buffer):
            continue
        statement = buffer.strip()
        buffer = ""
        if statement:
            connection.executescript(statement)
    if buffer.strip():
        raise sqlite3.OperationalError(f"Instruction SQL incomplete dans {sql_path}")
    connection.commit()


def ensure_storage_dirs() -> None:
    """Crée les répertoires runtime et rétablit leurs garde-fous serveur.

    La reconstruction from scratch doit rester sûre même si ``storage/`` a été
    supprimé entièrement avant l’exécution. Les fichiers existants ne sont pas
    écrasés afin de préserver une configuration serveur volontairement renforcée.
    """
    for directory in MEDIA_STORAGE_DIRS:
        directory.mkdir(parents=True, exist_ok=True)
        keep = directory / ".gitkeep"
        if not keep.exists():
            keep.write_text("", encoding="utf-8")

    for directory in PRIVATE_STORAGE_DIRS:
        protection = directory / ".htaccess"
        if not protection.exists():
            protection.write_text(PRIVATE_HTACCESS, encoding="utf-8")


def create_structure(db_path: Path, schema_path: Path) -> None:
    if not schema_path.exists():
        raise FileNotFoundError(f"Schema SQL introuvable: {schema_path}")

    db_path.parent.mkdir(parents=True, exist_ok=True)
    if db_path.exists():
        db_path.unlink()

    con = connect_sqlite(db_path)
    try:
        run_sql_file(con, schema_path)
    finally:
        con.close()


def apply_reference_seeds() -> None:
    seeds = [
        (CORE_DB, CORE_REFERENCE_SEED),
        (IAM_DB, IAM_REFERENCE_SEED),
        (COOKIES_DB, COOKIES_REFERENCE_SEED),
        (AI_DB, AI_DEFAULT_SEED),
    ]
    for db_path, seed_path in seeds:
        con = connect_sqlite(db_path)
        try:
            run_sql_file(con, seed_path)
        finally:
            con.close()


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Recree les bases SQLite locales depuis zero.")
    parser.add_argument(
        "--structures-only",
        action="store_true",
        help="Cree uniquement les schemas vides, sans seed. Ancien comportement minimal.",
    )
    parser.add_argument(
        "--with-seed",
        action="store_true",
        help="Compatibilite: le seed est maintenant applique par defaut sauf avec --structures-only.",
    )
    parser.add_argument(
        "--seed-skip-projections",
        action="store_true",
        help="Avec le seed, n'appelle pas la reconstruction PHP des projections/recherche.",
    )
    parser.add_argument(
        "--with-reference-seed",
        action="store_true",
        help=(
            "Applique seulement les seeds SQL de reference apres la creation des structures. "
            "Ne pas utiliser avec --with-seed, qui injecte deja le jeu par defaut complet."
        ),
    )
    return parser.parse_args()


def main() -> int:
    args = parse_args()

    ensure_storage_dirs()

    if args.with_reference_seed and not args.structures_only:
        # Le seed complet contient deja les donnees de reference; on garde
        # --with-reference-seed uniquement pour les bases techniques minimales.
        raise SystemExit("Utilisez --with-reference-seed avec --structures-only, ou lancez le seed complet par defaut.")

    create_structure(CORE_DB, CORE_SQL)
    create_structure(IAM_DB, IAM_SQL)
    create_structure(FORMS_DB, FORMS_SQL)
    create_structure(COOKIES_DB, COOKIES_SQL)
    create_structure(AI_DB, AI_SQL)

    print("Database structures initialized from SQL schemas: core, iam, forms, cookies, ai.", flush=True)

    if args.with_reference_seed:
        apply_reference_seeds()
        print("Reference/minimal seeds applied: core, iam, cookies, ai. Forms remains empty.", flush=True)
        return 0

    if args.structures_only:
        print("Default seed not executed. Run: python3 tools/python/operations/database/b0_db_seed.py", flush=True)
        return 0

    seed_script = SEED_SCRIPT
    if not seed_script.exists():
        print("ERREUR: script de seed introuvable: b0_db_seed.py", file=sys.stderr)
        return 2
    command = [sys.executable, str(seed_script)]
    if args.seed_skip_projections:
        command.append("--skip-projections")
    print("Running seed script:", " ".join(command), flush=True)
    return subprocess.run(command, cwd=str(BASE)).returncode


if __name__ == "__main__":
    raise SystemExit(main())
