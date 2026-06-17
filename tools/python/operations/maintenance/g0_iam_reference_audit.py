#!/usr/bin/env python3
"""Audit des references IAM stockees dans core.sqlite.

Le CMS conserve core.sqlite et iam.sqlite separes. Les colonnes *_iam_user_id
stockees dans core.sqlite sont donc des references applicatives vers iam_users,
pas des foreign keys SQLite natives. Ce script verifie les references orphelines
et signale les references vers des utilisateurs desactives.

Il verifie aussi les rattachements IAM multi-site (`iam_user_site_roles`) :
`site_id` est une reference applicative vers `core.sites(id)`, car SQLite ne
peut pas poser une FK native entre deux fichiers de base separes.
"""
from __future__ import annotations

import argparse
import json
import sqlite3
from dataclasses import asdict, dataclass
from pathlib import Path
from typing import Iterable

BASE = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
CORE_PATH = BASE / "storage" / "database" / "core.sqlite"
IAM_PATH = BASE / "storage" / "database" / "iam.sqlite"

REFERENCE_COLUMNS = [
    ("content_entries", "author_iam_user_id", True),
    ("content_entries", "owner_iam_user_id", True),
    ("content_entries", "created_by_iam_user_id", True),
    ("content_entries", "updated_by_iam_user_id", False),
    ("content_entries", "published_by_iam_user_id", False),
    ("content_entry_working_revisions", "updated_by_iam_user_id", False),
    ("content_entry_publications", "published_by_iam_user_id", False),
    ("content_entry_publications", "unpublished_by_iam_user_id", False),
    ("revisions", "created_by_iam_user_id", False),
    ("revisions", "updated_by_iam_user_id", False),
    ("revisions", "published_by_iam_user_id", False),
    ("revision_comments", "created_by_iam_user_id", False),
    ("revision_locks", "locked_by_iam_user_id", False),
    ("media_asset_events", "actor_iam_user_id", False),
]

@dataclass(frozen=True)
class Issue:
    severity: str
    table: str
    column: str
    row_id: int
    user_id: int | None
    message: str


def connect(path: Path) -> sqlite3.Connection:
    con = sqlite3.connect(path)
    con.row_factory = sqlite3.Row
    return con


def table_exists(con: sqlite3.Connection, table: str) -> bool:
    row = con.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?", (table,)).fetchone()
    return row is not None


def column_exists(con: sqlite3.Connection, table: str, column: str) -> bool:
    return any(row["name"] == column for row in con.execute(f"PRAGMA table_info({table})"))


def user_map(iam: sqlite3.Connection) -> dict[int, sqlite3.Row]:
    return {int(row["id"]): row for row in iam.execute("SELECT id, email, is_active, disabled_at FROM iam_users")}


def active_site_ids(core: sqlite3.Connection) -> set[int]:
    if not table_exists(core, "sites"):
        return set()
    return {int(row["id"]) for row in core.execute("SELECT id FROM sites WHERE is_active = 1")}


def audit(core: sqlite3.Connection, iam: sqlite3.Connection, *, fail_on_inactive: bool = False) -> list[Issue]:
    users = user_map(iam)
    site_ids = active_site_ids(core)
    issues: list[Issue] = []

    for table, column, required_when_present in REFERENCE_COLUMNS:
        if not table_exists(core, table) or not column_exists(core, table, column):
            continue
        for row in core.execute(f"SELECT id, {column} AS user_id FROM {table} WHERE {column} IS NOT NULL"):
            row_id = int(row["id"])
            user_id = int(row["user_id"])
            user = users.get(user_id)
            if user is None:
                issues.append(Issue("error", table, column, row_id, user_id, "reference IAM orpheline"))
                continue
            if int(user["is_active"] or 0) != 1:
                issues.append(Issue("error" if fail_on_inactive else "warning", table, column, row_id, user_id, "utilisateur IAM desactive"))

        if required_when_present:
            for row in core.execute(f"SELECT id FROM {table} WHERE {column} IS NULL"):
                issues.append(Issue("warning", table, column, int(row["id"]), None, "reference IAM recommandee absente"))

    if table_exists(iam, "iam_user_site_roles"):
        for row in iam.execute("SELECT id, user_id, site_id, role_id FROM iam_user_site_roles"):
            row_id = int(row["id"])
            user_id = int(row["user_id"])
            site_id = int(row["site_id"])
            if user_id not in users:
                issues.append(Issue("error", "iam_user_site_roles", "user_id", row_id, user_id, "utilisateur IAM orphelin dans un rattachement site"))
            elif int(users[user_id]["is_active"] or 0) != 1:
                issues.append(Issue("error" if fail_on_inactive else "warning", "iam_user_site_roles", "user_id", row_id, user_id, "utilisateur IAM desactive rattache a un site"))
            if site_id not in site_ids:
                issues.append(Issue("error", "iam_user_site_roles", "site_id", row_id, user_id, f"site_id applicatif introuvable ou inactif: {site_id}"))

    return issues


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Audite les references IAM applicatives entre core.sqlite et iam.sqlite.")
    parser.add_argument("--core", type=Path, default=CORE_PATH, help="Chemin vers core.sqlite")
    parser.add_argument("--iam", type=Path, default=IAM_PATH, help="Chemin vers iam.sqlite")
    parser.add_argument("--json", action="store_true", help="Sortie JSON")
    parser.add_argument("--fail-on-inactive", action="store_true", help="Traite les utilisateurs desactives comme erreurs")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    if not args.core.exists() or not args.iam.exists():
        print("ERROR bases SQLite introuvables. Lancez tools/python/operations/database/a_db_init.py puis b_db_seed.py si necessaire.")
        return 2

    core = connect(args.core)
    iam = connect(args.iam)
    try:
        issues = audit(core, iam, fail_on_inactive=args.fail_on_inactive)
    finally:
        core.close()
        iam.close()

    payload = {
        "core": str(args.core),
        "iam": str(args.iam),
        "errors": sum(1 for issue in issues if issue.severity == "error"),
        "warnings": sum(1 for issue in issues if issue.severity == "warning"),
        "issues": [asdict(issue) for issue in issues],
    }

    if args.json:
        print(json.dumps(payload, ensure_ascii=False, indent=2))
    else:
        print(f"[iam references] errors={payload['errors']} warnings={payload['warnings']}")
        for issue in issues:
            print(f"{issue.severity.upper()} {issue.table}.{issue.column} row={issue.row_id} user={issue.user_id}: {issue.message}")

    return 1 if payload["errors"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
