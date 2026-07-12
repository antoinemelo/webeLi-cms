from __future__ import annotations

import json
import sqlite3
from pathlib import Path

from tools.python.lib.database_inventory import native_database_names
from tools.python.validation.checks import ROOT
from tools.python.validation.model import ValidationReport

VALIDATOR_ID = "RUNTIME_INTEGRITY"
DOMAIN = "qualification"
MODES = ("full", "slow")


def _table_exists(db: sqlite3.Connection, name: str) -> bool:
    return db.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?", (name,)).fetchone() is not None


def _check_database(report: ValidationReport, path: Path) -> None:
    report.checked(2)
    if not path.is_file():
        report.add("DB-201", "Base native absente", path=path.relative_to(ROOT).as_posix())
        return
    try:
        db = sqlite3.connect(f"file:{path}?mode=ro", uri=True)
        db.row_factory = sqlite3.Row
        integrity = str(db.execute("PRAGMA integrity_check").fetchone()[0])
        foreign_keys = db.execute("PRAGMA foreign_key_check").fetchall()
        if integrity != "ok":
            report.add("DB-202", "La base active échoue à integrity_check", path=path.relative_to(ROOT).as_posix(), result=integrity)
        if foreign_keys:
            report.add("DB-203", "La base active contient des violations de clés étrangères", path=path.relative_to(ROOT).as_posix(), violations=[tuple(row) for row in foreign_keys[:20]])
        if path.name == "core.sqlite":
            _check_core_projections(report, db)
        db.close()
    except Exception as exc:
        report.add("DB-204", "Impossible de contrôler la base active", path=path.relative_to(ROOT).as_posix(), error=str(exc))


def _check_core_projections(report: ValidationReport, db: sqlite3.Connection) -> None:
    required = {
        "content_entry_publications", "revisions", "public_content_snapshots",
        "routes", "seo_metadata", "search_documents",
    }
    existing = {row[0] for row in db.execute("SELECT name FROM sqlite_master WHERE type='table'")}
    report.checked(len(required))
    missing = sorted(required - existing)
    if missing:
        report.add("PRJ-201", "Tables nécessaires au contrôle des projections absentes", details={"tables": missing})
        return

    queries = [
        ("PRJ-202", "Publication sans snapshot correspondant", """
            SELECT cep.entry_id, cep.language_code
            FROM content_entry_publications cep
            LEFT JOIN public_content_snapshots pcs
              ON pcs.site_id=cep.site_id AND pcs.language_code=cep.language_code
             AND pcs.resource_type='content_entry' AND pcs.resource_id=cep.entry_id
             AND pcs.source_published_revision_id=cep.published_revision_id
            WHERE cep.workflow_status='published' AND pcs.id IS NULL
            LIMIT 20
        """),
        ("PRJ-203", "Snapshot non reproductible depuis une révision publiée", """
            SELECT pcs.resource_id, pcs.language_code
            FROM public_content_snapshots pcs
            LEFT JOIN content_entry_publications cep
              ON cep.site_id=pcs.site_id AND cep.entry_id=pcs.resource_id
             AND cep.language_code=pcs.language_code
             AND cep.published_revision_id=pcs.source_published_revision_id
             AND cep.workflow_status='published'
            LEFT JOIN revisions r
              ON r.id=pcs.source_published_revision_id
             AND r.resource_type='content_entry' AND r.resource_id=pcs.resource_id
             AND r.language_code=pcs.language_code AND r.workflow_status='published'
             AND r.checksum_sha256=pcs.source_revision_checksum_sha256
            WHERE pcs.resource_type='content_entry' AND (cep.id IS NULL OR r.id IS NULL)
            LIMIT 20
        """),
        ("PRJ-204", "Projection publique incomplète (route ou SEO absent)", """
            SELECT pcs.resource_id, pcs.language_code, pcs.route_path
            FROM public_content_snapshots pcs
            LEFT JOIN routes r
              ON r.site_id=pcs.site_id AND r.language_code=pcs.language_code
             AND r.resource_type=pcs.resource_type AND r.resource_id=pcs.resource_id
             AND r.full_path=pcs.route_path AND r.status='active'
             AND r.source_published_revision_id=pcs.source_published_revision_id
             AND r.source_revision_checksum_sha256=pcs.source_revision_checksum_sha256
            LEFT JOIN seo_metadata sm
              ON sm.site_id=pcs.site_id AND sm.language_code=pcs.language_code
             AND sm.resource_type=pcs.resource_type AND sm.resource_id=pcs.resource_id
             AND sm.source_published_revision_id=pcs.source_published_revision_id
             AND sm.source_revision_checksum_sha256=pcs.source_revision_checksum_sha256
            WHERE pcs.resource_type='content_entry' AND (r.id IS NULL OR sm.id IS NULL)
            LIMIT 20
        """),
        ("PRJ-205", "Document de recherche sans snapshot public correspondant", """
            SELECT sd.resource_id, sd.language_code
            FROM search_documents sd
            LEFT JOIN public_content_snapshots pcs
              ON pcs.site_id=sd.site_id AND pcs.language_code=sd.language_code
             AND pcs.resource_type=sd.resource_type AND pcs.resource_id=sd.resource_id
             AND pcs.source_published_revision_id=sd.source_published_revision_id
             AND pcs.source_revision_checksum_sha256=sd.source_revision_checksum_sha256
            WHERE sd.resource_type='content_entry' AND pcs.id IS NULL
            LIMIT 20
        """),
    ]
    for code, message, query in queries:
        report.checked()
        rows = db.execute(query).fetchall()
        if rows:
            report.add(code, message, details={"rows": [dict(row) for row in rows]})

    report.checked()
    noindex_rows = db.execute("""
        SELECT sd.resource_id, sd.language_code
        FROM search_documents sd
        JOIN seo_metadata sm ON sm.site_id=sd.site_id
         AND sm.language_code=sd.language_code
         AND sm.resource_type=sd.resource_type
         AND sm.resource_id=sd.resource_id
        WHERE sd.resource_type='content_entry'
          AND COALESCE(sm.meta_robots,'index,follow') LIKE '%noindex%'
        LIMIT 20
    """).fetchall()
    if noindex_rows:
        report.add("PRJ-207", "Des contenus noindex restent projetés dans l’index interne search_documents", details={"rows": [dict(row) for row in noindex_rows]})

    report.checked()
    bad_json = []
    for row in db.execute("SELECT id, document_json FROM public_content_snapshots WHERE document_json IS NOT NULL"):
        try:
            json.loads(row["document_json"])
        except Exception:
            bad_json.append(row["id"])
            if len(bad_json) >= 20:
                break
    if bad_json:
        report.add("PRJ-206", "Snapshots publics contenant un document_json invalide", details={"ids": bad_json})


def validate(mode: str = "full") -> ValidationReport:
    report = ValidationReport(VALIDATOR_ID, DOMAIN, mode)
    db_dir = ROOT / "storage" / "database"
    for name in native_database_names(backup=True):
        _check_database(report, db_dir / name)
    return report
