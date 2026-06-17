#!/usr/bin/env python3
from __future__ import annotations
import sys as _dec_sys
from pathlib import Path as _DecPath
_DEC_CMS_PROJECT_ROOT = next(parent for parent in _DecPath(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
if str(_DEC_CMS_PROJECT_ROOT) not in _dec_sys.path:
    _dec_sys.path.insert(0, str(_DEC_CMS_PROJECT_ROOT))

import argparse
import shutil
import sqlite3
import zipfile
from pathlib import Path

from tools.python.lib.deploylib import (
    build_release_manifest,
    configured_dependency_paths,
    default_release_id,
    dependency_exclude_patterns,
    ensure_parent,
    should_exclude,
    write_json,
)
from tools.python.lib.release_metadata import load_release_metadata
from tools.python.lib.database_inventory import native_database_names

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
DIST_DIR = ROOT / "storage" / "exports"
DEFAULT_STAGE = DIST_DIR / "release_stage"

DEFAULT_EXCLUDES = [
    ".git",
    ".git/*",
    ".DS_Store",
    "Thumbs.db",
    "vendor",
    "vendor/*",
    "backend/vendor",
    "backend/vendor/*",
    "admin-app/assets/*.js.map",
    "ops/ftp.deploy.json",
    "backend/storage",
    "backend/storage/*",
    "storage/cache/*",
    "storage/logs/*",
    "storage/logs/**",
    "storage/backups/*",
    "storage/backups/**",
    "storage/uploads/*",
    # Règle stricte: une release ne transporte jamais ses exports locaux,
    # ses anciens ZIP ni son propre staging. Les seuls fichiers tolérés
    # sous storage/exports sont les garde-fous .gitkeep/.htaccess.
    "storage/exports",
    "storage/exports/*",
    "storage/exports/**",
    "storage/deployments/current.json",
    "storage/deployments/history.ndjson",
    "storage/deployments/release-manifest.json",
    "storage/deployments/*.json",
    "storage/deployments/*.ndjson",
    "storage/deployments/releases/*",
    "storage/qualification",
    "storage/qualification/*",
    "storage/qualification/**",
    "storage/audit-results",
    "storage/audit-results/*",
    "storage/audit-results/**",
    "storage/database/*.sqlite",
    "storage/database/*.sqlite-*",
    # La release publique ne distribue que la documentation publique actuelle.
    "docs/internal",
    "docs/internal/*",
    "docs/internal/**",
    "docs/archive",
    "docs/archive/*",
    "docs/archive/**",
    "docs/history",
    "docs/history/*",
    "docs/history/**",
    "docs/adr",
    "docs/adr/*",
    "docs/adr/**",
    "tests/*",
    "**/__pycache__/*",
    "**/*.pyc",
]

KEEP_FILES = {
    "storage/cache/.gitkeep",
    "storage/logs/.gitkeep",
    "storage/uploads/.gitkeep",
    "storage/exports/.gitkeep",
    "storage/exports/.htaccess",
    "storage/exports/static/.gitkeep",
    "storage/exports/static/.htaccess",
    "storage/database/.gitkeep",
    "storage/database/.htaccess",
    "storage/logs/.htaccess",
    "storage/backups/.gitkeep",
    "storage/backups/.htaccess",
    "storage/backups/sqlite/.gitkeep",
    "storage/backups/sqlite/.htaccess",
    "storage/deployments/.gitkeep",
    "storage/deployments/releases/.gitkeep",
}

FORBIDDEN_STAGE_FILES = (
    "storage/exports/release_stage/",
    "storage/exports/am_cms_mod_release.zip",
    "storage/exports/last_release_manifest.json",
    "storage/exports/last_ftp_deploy_manifest.json",
    "storage/exports/last_deployment_plan.json",
    "storage/exports/last_deployment_report.json",
)

REQUIRED_SQLITE_DATABASES = native_database_names(release=True)


def assert_clean_stage(stage_dir: Path) -> None:
    """Bloque les packages qui embarqueraient des exports locaux."""
    offenders: list[str] = []
    for item in sorted(stage_dir.rglob("*")):
        if not item.is_file():
            continue
        rel = item.relative_to(stage_dir).as_posix()
        if rel in {
            "storage/exports/.gitkeep",
            "storage/exports/.htaccess",
            "storage/exports/static/.gitkeep",
            "storage/exports/static/.htaccess",
        }:
            continue
        if rel.startswith("storage/exports/"):
            offenders.append(rel)
        elif any(rel.startswith(prefix) for prefix in FORBIDDEN_STAGE_FILES):
            offenders.append(rel)

    if offenders:
        details = "\n".join(f"- {rel}" for rel in offenders[:30])
        extra = "" if len(offenders) <= 30 else f"\n... +{len(offenders) - 30} autre(s) fichier(s)"
        raise RuntimeError(
            "Package non propre: des exports locaux seraient inclus. "
            "Une release ne doit contenir ni anciens exports ni release_stage.\n"
            + details
            + extra
        )

def copy_tree(src_root: Path, dst_root: Path, excludes: list[str], include_databases: bool, include_vendor: bool) -> None:
    if dst_root.exists():
        shutil.rmtree(dst_root)
    dst_root.mkdir(parents=True, exist_ok=True)

    effective_excludes = list(excludes) + dependency_exclude_patterns(ROOT)
    if include_vendor:
        allowed_dependency_roots = {"vendor", "backend/vendor"}
        twig_vendor = configured_dependency_paths(ROOT).get("twig_vendor")
        if twig_vendor is not None:
            try:
                twig_rel = twig_vendor.relative_to(ROOT.resolve()).as_posix().rstrip("/")
            except ValueError:
                twig_rel = ""
            if twig_rel and "node_modules" not in twig_rel.split("/"):
                allowed_dependency_roots.add(twig_rel)

        def is_allowed_runtime_dependency_pattern(pattern: str) -> bool:
            normalized = pattern.rstrip("/*")
            return any(
                normalized == root or normalized.startswith(root + "/")
                for root in allowed_dependency_roots
            )

        effective_excludes = [
            pattern for pattern in effective_excludes
            if not is_allowed_runtime_dependency_pattern(pattern)
        ]

    for source in src_root.rglob("*"):
        rel = source.relative_to(src_root).as_posix()

        if not include_databases and (rel.startswith("storage/database/") and rel not in KEEP_FILES):
            continue
        if should_exclude(rel, effective_excludes) and rel not in KEEP_FILES:
            continue

        target = dst_root / rel
        if source.is_dir():
            target.mkdir(parents=True, exist_ok=True)
            continue

        ensure_parent(target)
        shutil.copy2(source, target)



def inject_stage_databases(stage_dir: Path) -> None:
    """Ajoute les bases seedées comme artefacts de release contrôlés.

    Les fichiers `storage/database/*.sqlite` sont exclus du parcours générique
    pour éviter d'embarquer silencieusement des bases locales accidentelles.
    Quand une release standard doit contenir les bases, seules les bases
    attendues sont copiées explicitement puis validées.
    """
    source_dir = ROOT / "storage" / "database"
    target_dir = stage_dir / "storage" / "database"
    target_dir.mkdir(parents=True, exist_ok=True)
    for name in REQUIRED_SQLITE_DATABASES:
        source = source_dir / name
        if not source.exists():
            raise RuntimeError(f"Base SQLite source absente: storage/database/{name}. Lancez python3 tools/python/operations/database/a_db_init.py.")
        shutil.copy2(source, target_dir / name)


def validate_stage_databases(stage_dir: Path) -> None:
    db_dir = stage_dir / "storage" / "database"
    missing = [name for name in REQUIRED_SQLITE_DATABASES if not (db_dir / name).exists()]
    if missing:
        raise RuntimeError("Bases SQLite absentes du staging: " + ", ".join(missing))
    for name in REQUIRED_SQLITE_DATABASES:
        path = db_dir / name
        try:
            with sqlite3.connect(path) as con:
                integrity = con.execute("PRAGMA integrity_check").fetchone()[0]
        except sqlite3.Error as exc:
            raise RuntimeError(f"Base SQLite illisible dans le staging: {name} ({exc})") from exc
        if integrity != "ok":
            raise RuntimeError(f"Base SQLite invalide dans le staging: {name} integrity_check={integrity}")

def create_zip(folder: Path, zip_path: Path, package_root: str = "") -> None:
    ensure_parent(zip_path)
    with zipfile.ZipFile(zip_path, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        for item in sorted(folder.rglob("*")):
            if not item.is_file():
                continue
            rel = item.relative_to(folder)
            arcname = Path(package_root) / rel if package_root else rel
            archive.write(item, arcname.as_posix())


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Prépare un package de release propre pour déploiement.")
    parser.add_argument("--name", default="", help="Préfixe du nom de l'archive. Par défaut: technical_version depuis config/release.json.")
    parser.add_argument("--release-id", default="", help="Identifiant de release explicite. Par défaut: nom + UTC + git/uuid.")
    parser.add_argument(
        "--output-dir",
        default="",
        help=(
            "Répertoire de sortie. Par défaut: "
            "storage/exports/<technical_version>/"
        ),
    )
    parser.add_argument("--stage-dir", default=str(DEFAULT_STAGE), help="Répertoire temporaire de staging.")
    parser.add_argument("--exclude-databases", action="store_true", help="Exclut storage/database/*.sqlite. Par défaut, une release v1 embarque les bases SQLite seedées.")
    parser.add_argument("--include-databases", action="store_true", help="Compatibilité: conservé, sans effet car les bases sont incluses par défaut.")
    parser.add_argument("--include-vendor", action="store_true", help="Inclut les dépendances PHP de runtime (vendor, backend/vendor et chemin Twig configuré). Les node_modules de build restent toujours exclus.")
    parser.add_argument("--no-zip", action="store_true", help="Prépare seulement le staging sans créer de zip.")
    parser.add_argument("--clean-stage", action="store_true", help="Supprime le staging à la fin. À éviter avant d3_deploy_ftp.py.")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    release_metadata = load_release_metadata()
    output_dir = (
        Path(args.output_dir).expanduser()
        if args.output_dir
        else DIST_DIR / release_metadata.technical_version
    )
    stage_dir = Path(args.stage_dir).expanduser()
    if not output_dir.is_absolute():
        output_dir = ROOT / output_dir
    if not stage_dir.is_absolute():
        stage_dir = ROOT / stage_dir

    output_dir.mkdir(parents=True, exist_ok=True)

    if args.include_vendor and not (ROOT / "backend" / "vendor" / "autoload.php").is_file():
        raise RuntimeError(
            "--include-vendor demandé, mais backend/vendor/autoload.php est absent. "
            "Exécutez composer install --working-dir backend --no-dev avant le packaging."
        )
    package_name = args.name or release_metadata.package_name()
    release_id = args.release_id or default_release_id(package_name, ROOT)
    include_databases = not args.exclude_databases
    copy_tree(ROOT, stage_dir, DEFAULT_EXCLUDES, include_databases=include_databases, include_vendor=args.include_vendor)
    if include_databases:
        inject_stage_databases(stage_dir)
        validate_stage_databases(stage_dir)

    manifest = build_release_manifest(
        stage_dir,
        package_name=package_name,
        release_id=release_id,
        source_root=ROOT,
        include_databases=include_databases,
        include_vendor=args.include_vendor,
        notes=[
            "Package source/runtime sans exports historiques ni release_stage embarqué.",
            "storage/exports ne conserve que .gitkeep/.htaccess et le sous-dossier static vide protégé dans une release standard.",
            "Les futurs ZIP statiques, manifests d'export statique et fichiers générés sous storage/exports/static sont exclus des releases runtime.",
            "storage/database/*.sqlite est exclu du parcours générique, puis les bases natives seedées attendues sont injectées explicitement comme artefacts de release, y compris ai.sqlite.",
            "Utilisez --exclude-databases uniquement pour fabriquer un package source sans données.",
            "Les dépendances configurées dans ops/.env sont exclues par défaut.",
            "--include-vendor inclut uniquement les dépendances PHP de runtime (vendor/, backend/vendor/ et le chemin Twig configuré).",
            "Les node_modules du back-office restent toujours exclus: seuls les assets admin compilés sont distribués.",
            "Les fichiers sensibles ou de debug (ops/ftp.deploy.json, *.js.map) sont exclus des releases standard.",
            f"L'archive ZIP contient le dossier racine {release_metadata.package_root}/.",
        ],
        release_metadata=release_metadata.to_dict(),
    )
    manifest_path = stage_dir / "storage" / "deployments" / "release-manifest.json"
    write_json(manifest_path, manifest)
    assert_clean_stage(stage_dir)

    if args.no_zip:
        print(f"Staging prêt: {stage_dir}")
        print(f"Release manifest: {manifest_path}")
        print(f"Release ID: {release_id}")
        print(f"Package: {package_name}")
        print(f"Dossier racine ZIP: {release_metadata.package_root}/")
        return 0

    zip_path = output_dir / f"{package_name}.zip"
    create_zip(stage_dir, zip_path, package_root=release_metadata.package_root)
    print(f"Archive créée: {zip_path}")
    print(f"Staging: {stage_dir}")
    print(f"Release manifest: {manifest_path}")
    print(f"Release ID: {release_id}")
    print(f"Package: {package_name}")
    print(f"Dossier racine ZIP: {release_metadata.package_root}/")

    if args.clean_stage and stage_dir.exists():
        shutil.rmtree(stage_dir)
        print(f"Staging supprimé: {stage_dir}")
        print("Attention: relancez d2_package_release.py sans --clean-stage avant d3_deploy_ftp.py.")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
