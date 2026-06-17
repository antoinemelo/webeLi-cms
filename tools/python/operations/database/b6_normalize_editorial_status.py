#!/usr/bin/env python3
"""Normalise les anciennes divergences content_entries.status / workflow_state.

Usage depuis la racine `mod/` :

    python3 tools/python/operations/database/b6_normalize_editorial_status.py --dry-run
    python3 tools/python/operations/database/b6_normalize_editorial_status.py --apply

Contrat : `content_entries.status` est canonique. Si une ancienne base contient
une divergence, `workflow_state` est réaligné sur `status`. Le schéma neuf bloque
ensuite toute nouvelle divergence par CHECK + triggers.
"""
from __future__ import annotations

import argparse
import sqlite3
import sys
from pathlib import Path

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
DEFAULT_DB = ROOT / "storage" / "database" / "core.sqlite"
ALLOWED = {"draft", "review", "scheduled", "published", "archived"}


def fail(message: str) -> int:
    print(f"ERREUR: {message}", file=sys.stderr)
    return 1


def configure_sqlite(con: sqlite3.Connection) -> None:
    con.row_factory = sqlite3.Row
    con.execute("PRAGMA busy_timeout = 5000")
    con.execute("PRAGMA foreign_keys = ON")


def normalize(db_path: Path, apply: bool) -> int:
    if not db_path.exists():
        return fail(f"Base introuvable: {db_path}")
    con = sqlite3.connect(db_path)
    configure_sqlite(con)
    try:
        rows = con.execute(
            """
            SELECT id, entry_key, status, workflow_state
            FROM content_entries
            WHERE status <> workflow_state
            ORDER BY id
            """
        ).fetchall()
        invalid = [row for row in rows if str(row["status"]) not in ALLOWED]
        if invalid:
            return fail(f"Statuts canoniques invalides: {[dict(row) for row in invalid[:10]]}")
        if not rows:
            print("OK: aucune divergence content_entries.status / workflow_state.")
            return 0
        print(f"Divergences détectées: {len(rows)}")
        for row in rows[:20]:
            print(f"- id={row['id']} entry_key={row['entry_key']} status={row['status']} workflow_state={row['workflow_state']} -> workflow_state={row['status']}")
        if len(rows) > 20:
            print(f"... {len(rows) - 20} autres lignes masquées")
        if not apply:
            print("Dry-run uniquement. Relancer avec --apply pour corriger.")
            return 2
        con.execute("BEGIN")
        con.execute(
            """
            UPDATE content_entries
            SET workflow_state = status,
                updated_at = CURRENT_TIMESTAMP
            WHERE status <> workflow_state
            """
        )
        con.commit()
        remaining = con.execute("SELECT COUNT(*) AS c FROM content_entries WHERE status <> workflow_state").fetchone()["c"]
        if int(remaining) != 0:
            return fail("Des divergences persistent après normalisation.")
        print("OK: divergences normalisées, workflow_state reflète status.")
        return 0
    except Exception:
        con.rollback()
        raise
    finally:
        con.close()


def main() -> int:
    parser = argparse.ArgumentParser(description="Normalise content_entries.workflow_state sur content_entries.status.")
    parser.add_argument("--db", default=str(DEFAULT_DB), help="Chemin de core.sqlite")
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--dry-run", action="store_true", help="Lister les divergences sans modifier")
    mode.add_argument("--apply", action="store_true", help="Corriger les divergences")
    args = parser.parse_args()
    return normalize(Path(args.db).resolve(), apply=bool(args.apply))


if __name__ == "__main__":
    raise SystemExit(main())
