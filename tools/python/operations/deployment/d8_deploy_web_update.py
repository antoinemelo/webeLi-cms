#!/usr/bin/env python3
"""Mise a jour controlee d'une installation web DEC CMS.

Ce script synchronise une release applicative vers une cible de production sans
jamais ecraser les donnees vivantes protegees (bases SQLite, medias, secrets,
logs, backups). Il est concu pour le cas typique::

    python3 tools/python/operations/deployment/d8_deploy_web_update.py plan  --source ../dec_v05-e01c.zip --target ../eve
    python3 tools/python/operations/deployment/d8_deploy_web_update.py apply --source ../dec_v05-e01c.zip --target ../eve --delete-obsolete

Le mode `apply` cree toujours une sauvegarde de rollback contenant les fichiers
modifies/supprimes et un manifest JSON. Les migrations de bases restent separees
et doivent etre lancees explicitement via `d9_migrate_sqlite.py` si necessaire.
"""
from __future__ import annotations

import argparse
import json
import os
import shutil
import sys
import tempfile
import time
import zipfile
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
BACKUP_DIR_NAME = Path("storage") / "backups" / "deployments"
MAINTENANCE_FLAG_NAME = Path("storage") / "maintenance.flag"

PROTECTED_PREFIXES = (
    "storage/database/",
    "storage/media/",
    "storage/uploads/",
    "storage/security/",
    "storage/logs/",
    "storage/cache/",
    "storage/backups/",
    "ops/.env",
    "vendor/",
    "backend/vendor/",
    "node_modules/",
    "frontend/admin-vue/node_modules/",
    "admin-app/node_modules/",
)
EXCLUDE_PREFIXES = (
    ".git/",
    ".idea/",
    ".vscode/",
    "__pycache__/",
    "storage/deployments/",
)
EXCLUDE_SUFFIXES = (".pyc", ".pyo", ".swp", ".tmp", ".DS_Store")


@dataclass(frozen=True)
class Delta:
    added: list[str]
    changed: list[str]
    removed: list[str]
    protected_skipped: list[str]


def rel_posix(path: Path, root: Path) -> str:
    return path.relative_to(root).as_posix()


def is_under(rel: str, prefixes: Iterable[str]) -> bool:
    normalized = rel.replace("\\", "/")
    for prefix in prefixes:
        clean = prefix.rstrip("/")
        if prefix.endswith("/"):
            if normalized == clean or normalized.startswith(prefix):
                return True
        elif normalized == clean:
            return True
    return False


def is_excluded(rel: str) -> bool:
    name = rel.rsplit("/", 1)[-1]
    return is_under(rel, EXCLUDE_PREFIXES) or name in EXCLUDE_SUFFIXES or rel.endswith(EXCLUDE_SUFFIXES)


def is_protected(rel: str) -> bool:
    return is_under(rel, PROTECTED_PREFIXES)


def list_files(root: Path) -> set[str]:
    files: set[str] = set()
    for path in root.rglob("*"):
        if not path.is_file():
            continue
        rel = rel_posix(path, root)
        if is_excluded(rel):
            continue
        files.add(rel)
    return files


def same_file(a: Path, b: Path) -> bool:
    if not b.exists() or not b.is_file():
        return False
    if a.stat().st_size != b.stat().st_size:
        return False
    # Lecture binaire simple: les fichiers CMS sont de taille raisonnable et la
    # comparaison exacte evite de dependre des dates mtime entre deux serveurs.
    return a.read_bytes() == b.read_bytes()


def find_package_root(extracted: Path) -> Path:
    entries = [p for p in extracted.iterdir() if p.is_dir() and not p.name.startswith("__MACOSX")]
    if (extracted / "backend").exists() or (extracted / "index.php").exists():
        return extracted
    if len(entries) == 1:
        return entries[0]
    for preferred in ("mod", "eve", "cms"):
        candidate = extracted / preferred
        if candidate.exists() and candidate.is_dir():
            return candidate
    raise RuntimeError("Impossible de determiner la racine applicative dans l'archive.")


def resolve_source(source: Path, temp_root: Path) -> Path:
    if source.is_dir():
        return source.resolve()
    if source.is_file() and zipfile.is_zipfile(source):
        extract_dir = temp_root / "release"
        extract_dir.mkdir(parents=True, exist_ok=True)
        with zipfile.ZipFile(source) as archive:
            archive.extractall(extract_dir)
        return find_package_root(extract_dir).resolve()
    raise RuntimeError(f"Source invalide: {source}")


def compute_delta(source_root: Path, target_root: Path, delete_obsolete: bool) -> Delta:
    source_files = list_files(source_root)
    target_files = list_files(target_root) if target_root.exists() else set()
    added: list[str] = []
    changed: list[str] = []
    removed: list[str] = []
    protected_skipped: list[str] = []

    for rel in sorted(source_files):
        if is_protected(rel):
            protected_skipped.append(rel)
            continue
        source = source_root / rel
        target = target_root / rel
        if not target.exists():
            added.append(rel)
        elif not same_file(source, target):
            changed.append(rel)

    if delete_obsolete:
        for rel in sorted(target_files - source_files):
            if is_protected(rel):
                protected_skipped.append(rel)
                continue
            removed.append(rel)
    return Delta(added=added, changed=changed, removed=removed, protected_skipped=sorted(set(protected_skipped)))


def deployment_manifest(source_root: Path, target_root: Path, delta: Delta) -> dict:
    return {
        "schema_version": 1,
        "created_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "source_root": str(source_root),
        "target_root": str(target_root),
        "added": delta.added,
        "changed": delta.changed,
        "removed": delta.removed,
        "protected_skipped": delta.protected_skipped,
        "protected_prefixes": list(PROTECTED_PREFIXES),
    }


def create_rollback_archive(target_root: Path, delta: Delta, manifest: dict, output_dir: Path) -> Path:
    output_dir.mkdir(parents=True, exist_ok=True)
    stamp = time.strftime("%Y%m%d-%H%M%S")
    archive_path = output_dir / f"rollback-web-update-{stamp}.zip"
    with zipfile.ZipFile(archive_path, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        archive.writestr("deployment-manifest.json", json.dumps(manifest, ensure_ascii=False, indent=2) + "\n")
        for rel in sorted(set(delta.changed + delta.removed)):
            path = target_root / rel
            if path.exists() and path.is_file():
                archive.write(path, f"previous/{rel}")
    return archive_path


def ensure_no_path_escape(root: Path, rel: str) -> Path:
    target = (root / rel).resolve()
    root_resolved = root.resolve()
    if os.path.commonpath([str(root_resolved), str(target)]) != str(root_resolved):
        raise RuntimeError(f"Chemin hors cible refuse: {rel}")
    return target


def copy_file(source_root: Path, target_root: Path, rel: str) -> None:
    source = source_root / rel
    target = ensure_no_path_escape(target_root, rel)
    target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(source, target)


def remove_file(target_root: Path, rel: str) -> None:
    target = ensure_no_path_escape(target_root, rel)
    if target.exists() and target.is_file():
        target.unlink()
        # Nettoyage doux des dossiers vides, sans remonter au-dela de la cible.
        parent = target.parent
        while parent != target_root and parent.exists():
            try:
                parent.rmdir()
            except OSError:
                break
            parent = parent.parent


def print_delta(delta: Delta) -> None:
    print(f"A ajouter : {len(delta.added)}")
    print(f"A modifier: {len(delta.changed)}")
    print(f"A supprimer: {len(delta.removed)}")
    print(f"Proteges ignores: {len(delta.protected_skipped)}")
    for label, items in (("ADD", delta.added), ("MOD", delta.changed), ("DEL", delta.removed), ("SKIP", delta.protected_skipped)):
        for rel in items[:200]:
            print(f"{label} {rel}")
        if len(items) > 200:
            print(f"{label} ... {len(items) - 200} entree(s) supplementaire(s)")


def restore_from_archive(target_root: Path, archive_path: Path, yes: bool) -> int:
    if not yes:
        print("ERREUR: rollback non confirme. Relancez avec --yes.", file=sys.stderr)
        return 2
    if not zipfile.is_zipfile(archive_path):
        print(f"ERREUR: archive invalide: {archive_path}", file=sys.stderr)
        return 2
    with tempfile.TemporaryDirectory(prefix="amcms-rollback-") as tmp:
        tmp_path = Path(tmp)
        with zipfile.ZipFile(archive_path) as archive:
            archive.extractall(tmp_path)
        manifest_path = tmp_path / "deployment-manifest.json"
        if not manifest_path.exists():
            print("ERREUR: deployment-manifest.json absent.", file=sys.stderr)
            return 2
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        for rel in manifest.get("added", []):
            if not is_protected(rel):
                remove_file(target_root, rel)
        previous_root = tmp_path / "previous"
        for path in previous_root.rglob("*") if previous_root.exists() else []:
            if path.is_file():
                rel = rel_posix(path, previous_root)
                if not is_protected(rel):
                    target = ensure_no_path_escape(target_root, rel)
                    target.parent.mkdir(parents=True, exist_ok=True)
                    shutil.copy2(path, target)
    print(f"Rollback applique depuis: {archive_path}")
    return 0


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Planifie, applique ou restaure une mise a jour fichiers web DEC CMS.")
    sub = parser.add_subparsers(dest="command", required=True)

    def add_common(p: argparse.ArgumentParser) -> None:
        p.add_argument("--source", required=True, help="Dossier racine de release ou archive ZIP contenant la release.")
        p.add_argument("--target", required=True, help="Dossier racine de l'installation a mettre a jour.")
        p.add_argument("--delete-obsolete", action="store_true", help="Supprime les anciens fichiers absents de la release, hors chemins proteges.")
        p.add_argument("--json", action="store_true", help="Affiche le plan en JSON.")

    add_common(sub.add_parser("plan", help="Affiche les changements sans modifier la cible."))
    apply_p = sub.add_parser("apply", help="Applique la mise a jour apres backup de rollback.")
    add_common(apply_p)
    apply_p.add_argument("--backup-dir", default=None, help="Dossier des archives de rollback. Par défaut: <target>/storage/backups/deployments.")
    apply_p.add_argument("--maintenance-flag", action="store_true", help="Cree storage/maintenance.flag pendant la copie.")
    apply_p.add_argument("--yes", action="store_true", help="Confirmation obligatoire pour appliquer.")

    rollback_p = sub.add_parser("rollback", help="Restaure une mise a jour depuis une archive de rollback.")
    rollback_p.add_argument("--target", required=True, help="Dossier racine de l'installation a restaurer.")
    rollback_p.add_argument("--archive", required=True, help="Archive rollback-web-update-*.zip.")
    rollback_p.add_argument("--yes", action="store_true", help="Confirmation obligatoire pour restaurer.")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    if args.command == "rollback":
        return restore_from_archive(Path(args.target).expanduser().resolve(), Path(args.archive).expanduser().resolve(), args.yes)

    target_root = Path(args.target).expanduser().resolve()
    if args.command == "apply" and not args.yes:
        print("ERREUR: application non confirmee. Lancez d'abord plan, puis relancez apply avec --yes.", file=sys.stderr)
        return 2

    with tempfile.TemporaryDirectory(prefix="amcms-release-") as tmp:
        source_root = resolve_source(Path(args.source).expanduser().resolve(), Path(tmp))
        if not source_root.exists():
            print(f"ERREUR: source absente: {source_root}", file=sys.stderr)
            return 2
        target_root.mkdir(parents=True, exist_ok=True)
        delta = compute_delta(source_root, target_root, bool(args.delete_obsolete))
        manifest = deployment_manifest(source_root, target_root, delta)
        if getattr(args, "json", False):
            print(json.dumps(manifest, ensure_ascii=False, indent=2))
        else:
            print_delta(delta)

        if args.command == "plan":
            return 0

        backup_dir = Path(args.backup_dir).expanduser() if args.backup_dir else target_root / BACKUP_DIR_NAME
        backup_path = create_rollback_archive(target_root, delta, manifest, backup_dir)
        print(f"Archive rollback: {backup_path}")
        maintenance_created = False
        try:
            if args.maintenance_flag:
                maintenance_flag = target_root / MAINTENANCE_FLAG_NAME
                maintenance_flag.parent.mkdir(parents=True, exist_ok=True)
                maintenance_flag.write_text("Maintenance temporaire pendant mise a jour web.\n", encoding="utf-8")
                maintenance_created = True
            for rel in delta.added + delta.changed:
                copy_file(source_root, target_root, rel)
            for rel in delta.removed:
                remove_file(target_root, rel)
        finally:
            maintenance_flag = target_root / MAINTENANCE_FLAG_NAME
            if maintenance_created and maintenance_flag.exists():
                maintenance_flag.unlink()
        print("Mise a jour web terminee. Les bases SQLite et medias proteges n'ont pas ete modifies.")
        return 0


if __name__ == "__main__":
    raise SystemExit(main())
