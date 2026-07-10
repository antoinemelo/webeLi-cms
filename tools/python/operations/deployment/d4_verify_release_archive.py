#!/usr/bin/env python3
from __future__ import annotations
import sys as _dec_sys
from pathlib import Path as _DecPath
_DEC_CMS_PROJECT_ROOT = next(parent for parent in _DecPath(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
if str(_DEC_CMS_PROJECT_ROOT) not in _dec_sys.path:
    _dec_sys.path.insert(0, str(_DEC_CMS_PROJECT_ROOT))

import argparse
import hashlib
import json
import sqlite3
import subprocess
import sys
import tempfile
import zipfile
from datetime import datetime, timezone
from pathlib import Path

from tools.python.lib.release_metadata import load_release_metadata
from tools.python.lib.database_inventory import database_paths

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
REQUIRED_FILES = [
    "backend/public/index.php",
    "backend/routes/web.php",
    "backend/routes/admin.php",
    "backend/routes/api.php",
    "backend/bootstrap/runtime.php",
    "config/release.json",
    "tools/cms.py",
    "tools/python/commands/smoke.py",
    "ops/_env.example",
    "storage/.htaccess",
    "storage/media/.htaccess",
    "storage/database/.htaccess",
    "storage/logs/.htaccess",
    "storage/backups/.htaccess",
    "storage/exports/.gitkeep",
    "storage/exports/.htaccess",
    "storage/exports/static/.gitkeep",
    "storage/exports/static/.htaccess",
    "storage/deployments/release-manifest.json",
    "docs/README.md",
    "docs/installation/README.md",
    "docs/installation/install-release.md",
    "docs/api/README.md",
    "docs/reference/README.md",
]
REQUIRED_DATABASES = list(database_paths(root=ROOT, release=True))
FORBIDDEN_PREFIXES = [
    "storage/exports/",
    "storage/qualification/",
    "storage/audit-results/",
    "storage/logs/",
    "storage/uploads/",
    "storage/cache/",
    ".git/",
    "frontend/admin-vue/node_modules/",
    "vendor/",
    "backend/vendor/",
    "docs/internal/",
    "docs/archive/",
    "docs/history/",
    "tools/python/tests/",
    "tools/tests/",
    "tools/php/tests/",
    "frontend/admin-vue/tests/",
    "frontend/admin-vue/test-results/",
]

FORBIDDEN_PATH_PARTS = {
    ".venv",
    "venv",
    "__pycache__",
    ".pytest_cache",
    ".mypy_cache",
    ".ruff_cache",
}

FORBIDDEN_SUFFIXES = [
    ".pyc", ".pyo", ".log", ".sqlite-wal", ".sqlite-shm", ".sqlite-journal",
    ".js.map", ".zip", ".tar", ".tgz", ".tar.gz", ".bak", ".backup", ".tmp",
]
FORBIDDEN_NAMES = {"ops/ftp.deploy.json", "ops/.env", ".env"}
ALLOWED_EXPORT_GUARDS = {
    "storage/exports/.gitkeep",
    "storage/exports/.htaccess",
    "storage/exports/static/.gitkeep",
    "storage/exports/static/.htaccess",
}
SECRET_NAME_PATTERNS = (".env", ".env.local", ".env.production", ".env.prod", "ftp.deploy.json")
RELEASE_COMMANDS = (
    (("smoke",), 60),
    (("validate",), 240),
    (("docs", "check"), 180),
)
RELEASE_TEST_COMMAND = (("test",), 60)
RELEASE_VERIFIED_COMMANDS = [
    "python3 tools/cms.py smoke",
    "python3 tools/cms.py validate",
    "python3 tools/cms.py docs check",
    "python3 tools/cms.py test (code 2 explicite si les tests source sont absents)",
]


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def has_forbidden_prefix(name: str, *, include_vendor: bool) -> bool:
    return any(
        name.startswith(prefix)
        for prefix in FORBIDDEN_PREFIXES
        if not (include_vendor and prefix in {"vendor/", "backend/vendor/"})
    )


def has_forbidden_path_part(name: str) -> bool:
    return bool(set(name.split("/")) & FORBIDDEN_PATH_PARTS)


def parse_args() -> argparse.Namespace:
    metadata = load_release_metadata()
    default_archive = ROOT / "storage" / "exports" / metadata.technical_version / f"{metadata.package_name()}.zip"
    parser = argparse.ArgumentParser(description="Vérifie qu'une archive de release DEC CMS est cohérente et installable.")
    parser.add_argument("--archive", default=str(default_archive), help="Archive ZIP à vérifier.")
    parser.add_argument("--allow-without-databases", action="store_true", help="Tolère une archive source sans bases SQLite.")
    parser.add_argument("--json", action="store_true", help="Sortie JSON.")
    return parser.parse_args()


def fail(errors: list[str], message: str) -> None:
    errors.append(message)


def strip_root(name: str, root_prefix: str) -> str:
    return name[len(root_prefix):] if root_prefix and name.startswith(root_prefix) else name


def detect_root(names: list[str]) -> str:
    parts = {name.split("/", 1)[0] for name in names if "/" in name}
    if len(parts) == 1:
        return next(iter(parts)) + "/"
    return ""


def verify_sqlite_from_zip(zf: zipfile.ZipFile, member: str, errors: list[str]) -> None:
    with tempfile.NamedTemporaryFile(suffix=".sqlite") as tmp:
        tmp.write(zf.read(member))
        tmp.flush()
        try:
            with sqlite3.connect(tmp.name) as con:
                integrity = con.execute("PRAGMA integrity_check").fetchone()[0]
        except sqlite3.Error as exc:
            fail(errors, f"Base SQLite illisible dans l'archive: {member} ({exc})")
            return
        if integrity != "ok":
            fail(errors, f"Base SQLite invalide dans l'archive: {member} integrity_check={integrity}")



def _tail(text: str, limit: int = 2000) -> str:
    text = text.strip()
    if len(text) <= limit:
        return text
    return "…" + text[-limit:]


def verify_release_commands_from_archive(archive: Path, root_prefix: str, errors: list[str]) -> None:
    """Extrait l'archive et exécute les commandes garanties en contexte release.

    Ce contrôle de packaging empêche une release de paraître valide alors que
    `smoke`, `validate` ou `docs check` dépendraient de tests source, de caches,
    de node_modules ou d'autres fichiers volontairement exclus du package.
    """
    with tempfile.TemporaryDirectory(prefix="dec-cms-release-commands-") as tmp:
        tmp_root = Path(tmp)
        with zipfile.ZipFile(archive) as zf:
            zf.extractall(tmp_root)
        install_root = tmp_root / root_prefix.rstrip("/") if root_prefix else tmp_root
        if not (install_root / "tools" / "cms.py").is_file():
            fail(errors, "Contrôle commandes release impossible: tools/cms.py absent après extraction.")
            return
        for command, timeout in RELEASE_COMMANDS:
            proc = subprocess.run(
                [
                    sys.executable,
                    str(install_root / "tools" / "cms.py"),
                    "--root",
                    str(install_root),
                    "--command-timeout",
                    str(timeout),
                    *command,
                ],
                cwd=install_root,
                text=True,
                capture_output=True,
                timeout=timeout + 30,
            )
            if proc.returncode != 0:
                label = " ".join(command)
                detail = "\n".join(
                    part
                    for part in (
                        f"stdout:\n{_tail(proc.stdout)}" if proc.stdout.strip() else "",
                        f"stderr:\n{_tail(proc.stderr)}" if proc.stderr.strip() else "",
                    )
                    if part
                )
                fail(
                    errors,
                    "Commande release non autonome dans l'archive: "
                    f"python3 tools/cms.py {label} (code {proc.returncode})."
                    + ("\n" + detail if detail else ""),
                )
        command, timeout = RELEASE_TEST_COMMAND
        proc = subprocess.run(
            [
                sys.executable,
                str(install_root / "tools" / "cms.py"),
                "--root",
                str(install_root),
                "--command-timeout",
                str(timeout),
                "--json",
                *command,
            ],
            cwd=install_root,
            text=True,
            capture_output=True,
            timeout=timeout + 30,
        )
        output = (proc.stdout or "") + "\n" + (proc.stderr or "")
        if proc.returncode != 2 or "release-archive-or-source-tests-missing" not in output:
            detail = "\n".join(
                part
                for part in (
                    f"stdout:\n{_tail(proc.stdout)}" if proc.stdout.strip() else "",
                    f"stderr:\n{_tail(proc.stderr)}" if proc.stderr.strip() else "",
                )
                if part
            )
            fail(
                errors,
                "Commande test incorrecte en contexte archive: "
                f"python3 tools/cms.py test doit retourner le code 2 explicite, code obtenu {proc.returncode}."
                + ("\n" + detail if detail else ""),
            )

def main() -> int:
    args = parse_args()
    archive = Path(args.archive).expanduser()
    if not archive.is_absolute():
        archive = ROOT / archive
    errors: list[str] = []
    warnings: list[str] = []

    if not archive.exists():
        fail(errors, f"Archive introuvable: {archive}")
    elif not zipfile.is_zipfile(archive):
        fail(errors, f"Archive ZIP invalide: {archive}")

    if not errors:
        with zipfile.ZipFile(archive) as zf:
            raw_names = [info.filename for info in zf.infolist() if not info.is_dir()]
            root_prefix = detect_root(raw_names)
            names = {strip_root(name, root_prefix) for name in raw_names}

            manifest = None
            manifest_member = root_prefix + "storage/deployments/release-manifest.json"
            if "storage/deployments/release-manifest.json" in names:
                try:
                    manifest = json.loads(zf.read(manifest_member).decode("utf-8"))
                except Exception as exc:  # noqa: BLE001
                    fail(errors, f"release-manifest.json illisible: {exc}")

            include_vendor = bool(isinstance(manifest, dict) and manifest.get("include_vendor") is True)

            for required in REQUIRED_FILES:
                if required not in names:
                    fail(errors, f"Fichier requis absent de l'archive: {required}")

            if include_vendor and "backend/vendor/autoload.php" not in names:
                fail(errors, "release-manifest.json déclare include_vendor=true, mais backend/vendor/autoload.php est absent.")

            missing_databases = [db for db in REQUIRED_DATABASES if db not in names]
            if missing_databases and not args.allow_without_databases:
                fail(errors, "Bases SQLite absentes de l'archive: " + ", ".join(missing_databases))
            for db in REQUIRED_DATABASES:
                member = root_prefix + db
                if db in names:
                    verify_sqlite_from_zip(zf, member, errors)

            for name in sorted(names):
                if name in {
                    "storage/cache/.gitkeep",
                    "storage/logs/.gitkeep",
                    "storage/logs/.htaccess",
                    "storage/uploads/.gitkeep",
                    "storage/exports/.gitkeep",
                    "storage/exports/.htaccess",
                    "storage/exports/static/.gitkeep",
                    "storage/exports/static/.htaccess",
                    "storage/database/.gitkeep",
                    "storage/database/.htaccess",
                    "storage/backups/.gitkeep",
                    "storage/backups/.htaccess",
                    "storage/backups/sqlite/.gitkeep",
                    "storage/backups/sqlite/.htaccess",
                }:
                    continue
                if name.startswith("storage/exports/") and name not in ALLOWED_EXPORT_GUARDS:
                    fail(errors, f"Export local interdit dans l'archive: {name}")
                elif has_forbidden_prefix(name, include_vendor=include_vendor):
                    fail(errors, f"Fichier local interdit dans l'archive: {name}")
                if has_forbidden_path_part(name):
                    fail(errors, f"Répertoire local interdit dans l'archive: {name}")
                if any(name.endswith(suffix) for suffix in FORBIDDEN_SUFFIXES):
                    fail(errors, f"Suffixe interdit dans l'archive: {name}")
                if name in FORBIDDEN_NAMES or Path(name).name in SECRET_NAME_PATTERNS:
                    fail(errors, f"Secret/config locale interdit dans l'archive: {name}")

            if isinstance(manifest, dict):
                if manifest.get("include_databases") is not True and not args.allow_without_databases:
                    fail(errors, "release-manifest.json doit déclarer include_databases=true pour une release v1 standard.")
                if not manifest.get("release_id") or not manifest.get("technical_version"):
                    fail(errors, "release-manifest.json doit contenir release_id et technical_version.")
                expected_root_prefix = root_prefix.rstrip("/")
                if manifest.get("release_root_prefix") != expected_root_prefix:
                    fail(errors, f"release-manifest.json doit déclarer release_root_prefix={expected_root_prefix}.")
                if manifest.get("generated_docs_fresh") is not True:
                    fail(errors, "release-manifest.json doit déclarer generated_docs_fresh=true.")
                verified_commands = manifest.get("verified_commands")
                if not isinstance(verified_commands, list) or not all(command in verified_commands for command in RELEASE_VERIFIED_COMMANDS[:3]):
                    fail(errors, "release-manifest.json doit lister les commandes release vérifiées.")
                notes = manifest.get("notes")
                if isinstance(notes, list) and not any("smoke, validate et docs check" in str(note) for note in notes):
                    warnings.append("Manifest sans note explicite sur les commandes release autonomes.")
                manifest_files = manifest.get("files")
                if isinstance(manifest_files, dict):
                    for required in REQUIRED_FILES:
                        if required == "storage/deployments/release-manifest.json":
                            continue
                        if required not in manifest_files:
                            warnings.append(f"Manifest sans entrée fichier: {required}")

            if not errors:
                verify_release_commands_from_archive(archive, root_prefix, errors)

    verification = {
        "archive_sha256": sha256_file(archive) if archive.exists() and archive.is_file() else None,
        "verification_timestamp": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "verified_commands": RELEASE_VERIFIED_COMMANDS,
    }
    if args.json:
        print(json.dumps({"ok": not errors, "errors": errors, "warnings": warnings, "archive": str(archive), **verification}, ensure_ascii=False, indent=2))
    else:
        print("[verify release archive]")
        for warning in warnings:
            print(f"WARN {warning}")
        for error in errors:
            print(f"ERROR {error}", file=sys.stderr)
        if not errors:
            print(f"OK Archive installable: {archive}")
        print(f"Résumé: {len(warnings)} avertissement(s), {len(errors)} erreur(s)")
    return 0 if not errors else 1


if __name__ == "__main__":
    raise SystemExit(main())
