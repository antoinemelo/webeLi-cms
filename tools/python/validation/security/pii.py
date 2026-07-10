from __future__ import annotations

import re
import sqlite3
from pathlib import Path

from tools.python.validation.model import ValidationReport

VALIDATOR_ID = "PII_EMAIL_GUARD"
DOMAIN = "security"
MODES = ("fast", "full", "slow")
ROOT = Path(__file__).resolve().parents[4]

EMAIL_RE = re.compile(r"[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}")
ALLOWED_DEMO_DOMAINS = {"example.test", "example.org", "example.com"}
FORBIDDEN_EMAILS: set[str] = set()
FORBIDDEN_DOMAINS = {
    "gmail.com",
    "ge.ch",
    "webe.li",
}
TEXT_SUFFIXES = {
    "",
    ".env",
    ".example",
    ".json",
    ".md",
    ".php",
    ".py",
    ".sql",
    ".txt",
    ".vue",
    ".yaml",
    ".yml",
}
SCAN_TARGETS = (
    "backend/config",
    "backend/src/Core/Installer.php",
    "database/migrations",
    "database/seeds",
    "docs",
    "examples",
    "ops",
    "storage/database",
    "storage/exports",
    "tools/php/tests/fixtures",
    "tools/python/operations/database/b0_db_seed.py",
)
EXCLUDED_PARTS = {".git", "node_modules", "vendor", "__pycache__"}

# Fichiers et dossiers locaux/générés qui peuvent contenir des secrets réels
# nécessaires à l’exploitation, mais qui ne sont ni des données de démonstration
# ni des artefacts distribuables. Ils restent exclus des releases par d2_package_release.py
# et/ou par .gitignore.
EXCLUDED_RELATIVE_FILES = {
    "ops/ftp.deploy.json",
}
EXCLUDED_RELATIVE_PREFIXES = (
    "storage/exports/release_stage/",
)


def _relative_posix(path: Path) -> str:
    try:
        return path.relative_to(ROOT).as_posix()
    except ValueError:
        return path.as_posix()


def _is_excluded(path: Path) -> bool:
    rel = _relative_posix(path)
    if rel in EXCLUDED_RELATIVE_FILES:
        return True
    if any(rel.startswith(prefix) for prefix in EXCLUDED_RELATIVE_PREFIXES):
        return True
    try:
        parts = set(path.relative_to(ROOT).parts)
    except ValueError:
        parts = set(path.parts)
    return bool(parts & EXCLUDED_PARTS)


def _iter_scan_files() -> list[Path]:
    files: list[Path] = []
    for rel in SCAN_TARGETS:
        target = ROOT / rel
        if target.is_file():
            files.append(target)
        elif target.is_dir():
            files.extend(path for path in target.rglob("*") if path.is_file())
    return sorted(set(path for path in files if not _is_excluded(path)))


def _is_ssh_remote_false_positive(email: str, text: str) -> bool:
    # `git@github.com:org/repo.git` is an SSH remote, not a demo data email.
    return email == "git@github.com" and "git@github.com:" in text


def _is_allowed_email(email: str, context: str) -> bool:
    normalized = email.lower()
    if _is_ssh_remote_false_positive(normalized, context):
        return True
    if normalized in FORBIDDEN_EMAILS:
        return False
    domain = normalized.rsplit("@", 1)[-1]
    if domain in FORBIDDEN_DOMAINS:
        return False
    return domain in ALLOWED_DEMO_DOMAINS


def _check_email(report: ValidationReport, email: str, *, path: str, context: str, location: str | None = None) -> None:
    report.checked()
    if _is_allowed_email(email, context):
        return
    domain = email.lower().rsplit("@", 1)[-1]
    report.add(
        "SEC-PII-EMAIL-001",
        "Adresse email non neutre détectée dans une surface de démonstration ou de publication.",
        path=path,
        email=email,
        domain=domain,
        location=location,
        allowed_domains=sorted(ALLOWED_DEMO_DOMAINS),
    )


def _scan_text_file(report: ValidationReport, path: Path) -> None:
    rel = path.relative_to(ROOT).as_posix()
    if path.suffix.lower() not in TEXT_SUFFIXES and not path.name.endswith((".env", ".env.example")):
        return
    try:
        text = path.read_text(encoding="utf-8")
    except UnicodeDecodeError:
        return
    for line_number, line in enumerate(text.splitlines(), 1):
        for email in EMAIL_RE.findall(line):
            _check_email(report, email, path=rel, context=line, location=f"line {line_number}")


def _scan_sqlite_database(report: ValidationReport, path: Path) -> None:
    rel = path.relative_to(ROOT).as_posix()
    try:
        connection = sqlite3.connect(path)
    except sqlite3.Error as exc:
        report.checked()
        report.add("SEC-PII-EMAIL-002", "Base SQLite illisible pour le contrôle PII.", path=rel, error=str(exc))
        return

    try:
        tables = [
            row[0]
            for row in connection.execute(
                "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
            ).fetchall()
        ]
        for table in tables:
            columns = [row[1] for row in connection.execute(f'PRAGMA table_info("{table}")').fetchall()]
            for column in columns:
                try:
                    rows = connection.execute(
                        f'SELECT _rowid_ AS __rid__, "{column}" AS __value__ FROM "{table}" WHERE "{column}" IS NOT NULL'
                    ).fetchall()
                except sqlite3.Error:
                    try:
                        rows = connection.execute(
                            f'SELECT "{column}" AS __value__ FROM "{table}" WHERE "{column}" IS NOT NULL'
                        ).fetchall()
                    except sqlite3.Error:
                        continue
                for row in rows:
                    value = row[1] if len(row) > 1 else row[0]
                    if isinstance(value, bytes):
                        text = value.decode("utf-8", errors="ignore")
                    else:
                        text = str(value)
                    if "@" not in text:
                        continue
                    rid = row[0] if len(row) > 1 else "?"
                    for email in EMAIL_RE.findall(text):
                        _check_email(
                            report,
                            email,
                            path=rel,
                            context=text,
                            location=f"{table}.{column} rowid={rid}",
                        )
    finally:
        connection.close()


def validate(mode: str = "fast") -> ValidationReport:
    report = ValidationReport(VALIDATOR_ID, DOMAIN, mode)
    for path in _iter_scan_files():
        if path.suffix.lower() == ".sqlite":
            _scan_sqlite_database(report, path)
        else:
            _scan_text_file(report, path)
    return report
