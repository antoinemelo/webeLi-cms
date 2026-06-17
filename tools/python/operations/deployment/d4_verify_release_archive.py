#!/usr/bin/env python3
from __future__ import annotations
import sys as _dec_sys
from pathlib import Path as _DecPath
_DEC_CMS_PROJECT_ROOT = next(parent for parent in _DecPath(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
if str(_DEC_CMS_PROJECT_ROOT) not in _dec_sys.path:
    _dec_sys.path.insert(0, str(_DEC_CMS_PROJECT_ROOT))

import argparse
import json
import sqlite3
import sys
import tempfile
import zipfile
from pathlib import Path

from tools.python.lib.release_metadata import load_release_metadata
from tools.python.lib.database_inventory import native_database_paths

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
REQUIRED_FILES = [
    "backend/public/index.php",
    "backend/routes/web.php",
    "backend/routes/admin.php",
    "backend/routes/api.php",
    "backend/bootstrap/runtime.php",
    "config/release.json",
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
REQUIRED_DATABASES = list(native_database_paths(release=True))
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
]

FORBIDDEN_SUFFIXES = [
    ".pyc", ".log", ".sqlite-wal", ".sqlite-shm", ".sqlite-journal",
    ".js.map", ".zip", ".tar", ".tgz", ".tar.gz", ".bak", ".backup",
]
FORBIDDEN_NAMES = {"ops/ftp.deploy.json", ".env", "ops/.env"}
ALLOWED_EXPORT_GUARDS = {
    "storage/exports/.gitkeep",
    "storage/exports/.htaccess",
    "storage/exports/static/.gitkeep",
    "storage/exports/static/.htaccess",
}
SECRET_NAME_PATTERNS = (".env", ".env.local", ".env.production", ".env.prod", "ftp.deploy.json")


def has_forbidden_prefix(name: str, *, include_vendor: bool) -> bool:
    return any(
        name.startswith(prefix)
        for prefix in FORBIDDEN_PREFIXES
        if not (include_vendor and prefix in {"vendor/", "backend/vendor/"})
    )


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
                if any(name.endswith(suffix) for suffix in FORBIDDEN_SUFFIXES):
                    fail(errors, f"Suffixe interdit dans l'archive: {name}")
                if name in FORBIDDEN_NAMES or Path(name).name in SECRET_NAME_PATTERNS:
                    fail(errors, f"Secret/config locale interdit dans l'archive: {name}")

            if isinstance(manifest, dict):
                if manifest.get("include_databases") is not True and not args.allow_without_databases:
                    fail(errors, "release-manifest.json doit déclarer include_databases=true pour une release v1 standard.")
                if not manifest.get("release_id") or not manifest.get("technical_version"):
                    fail(errors, "release-manifest.json doit contenir release_id et technical_version.")
                manifest_files = manifest.get("files")
                if isinstance(manifest_files, dict):
                    for required in REQUIRED_FILES:
                        if required == "storage/deployments/release-manifest.json":
                            continue
                        if required not in manifest_files:
                            warnings.append(f"Manifest sans entrée fichier: {required}")

    if args.json:
        print(json.dumps({"ok": not errors, "errors": errors, "warnings": warnings, "archive": str(archive)}, ensure_ascii=False, indent=2))
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
