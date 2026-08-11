#!/usr/bin/env python3
"""Déploiement FTP optionnel depuis le staging de release.

Ce script est volontairement préfixé d3 pour rester dans la chaîne de
livraison d_ orchestrée par d_deploy.py.
"""
from __future__ import annotations
import sys as _dec_sys
from pathlib import Path as _DecPath
_DEC_CMS_PROJECT_ROOT = next(parent for parent in _DecPath(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
if str(_DEC_CMS_PROJECT_ROOT) not in _dec_sys.path:
    _dec_sys.path.insert(0, str(_DEC_CMS_PROJECT_ROOT))

import argparse
import ftplib
import hashlib
import io
import json
import posixpath
import sys
import tempfile
import time
from pathlib import Path

from tools.python.operations.deployment import d8_deploy_web_update as web_update

from tools.python.lib.deploylib import (
    build_deploy_report,
    collect_files,
    compute_delta,
    dependency_prefixes,
    read_json,
    read_release_manifest,
    write_json,
    write_local_deployment_markers,
)

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
DEFAULT_CONFIG = ROOT / "ops" / "ftp.deploy.json"
DEFAULT_STAGE = ROOT / "storage" / "exports" / "release_stage"
DEFAULT_MANIFEST = ROOT / "storage" / "exports" / "last_ftp_deploy_manifest.json"
PROTECTED_PREFIXES = dependency_prefixes(ROOT)
INSTANCE_DATA_PREFIXES = (
    "storage/database/",
    "storage/media/",
    "storage/uploads/",
    "storage/security/",
    "storage/logs/",
    "storage/cache/",
    "storage/backups/",
    "ops/.env",
    "ops/modules.local.json",
    "local/modules/",
)
REMOTE_DEPLOYMENT_DIR = "storage/deployments"


class DeployError(RuntimeError):
    pass


def format_bytes(value: int | float) -> str:
    size = float(value)
    units = ["B", "KB", "MB", "GB", "TB"]
    for unit in units:
        if size < 1024 or unit == units[-1]:
            return f"{size:.1f} {unit}" if unit != "B" else f"{int(size)} {unit}"
        size /= 1024
    return f"{size:.1f} TB"


def format_duration(seconds: float) -> str:
    if seconds < 60:
        return f"{seconds:.1f}s"
    minutes, sec = divmod(int(seconds), 60)
    hours, minutes = divmod(minutes, 60)
    if hours:
        return f"{hours}h {minutes}m {sec}s"
    return f"{minutes}m {sec}s"


class ProgressTracker:
    def __init__(self, total_operations: int, quiet: bool = False) -> None:
        self.total_operations = max(total_operations, 1)
        self.quiet = quiet
        self.current_index = 0
        self.bytes_uploaded = 0
        self.started_at = time.monotonic()

    def log(self, message: str = "") -> None:
        if not self.quiet:
            print(message, flush=True)

    def start_operation(self, action: str, rel_path: str, size: int | None = None) -> None:
        self.current_index += 1
        suffix = f" ({format_bytes(size)})" if size is not None else ""
        self.log(f"[{self.current_index}/{self.total_operations}] {action}: {rel_path}{suffix}")

    def add_bytes(self, count: int) -> None:
        self.bytes_uploaded += count

    def print_file_progress(self, sent: int, total: int) -> None:
        if self.quiet or total <= 0:
            return
        percent = min(100, int((sent / total) * 100))
        print(
            f"\r    progression: {percent:3d}% "
            f"({format_bytes(sent)} / {format_bytes(total)})",
            end="",
            flush=True,
        )

    def end_file_progress(self) -> None:
        if not self.quiet:
            print("", flush=True)

    def summary(self, deleted_count: int, created_dirs: int, dry_run: bool) -> None:
        elapsed = time.monotonic() - self.started_at
        mode = "Simulation terminée" if dry_run else "Déploiement terminé"
        self.log()
        self.log("────────────────────────────────────────")
        self.log(mode)
        self.log(f"Durée: {format_duration(elapsed)}")
        self.log(f"Opérations traitées: {self.current_index}/{self.total_operations}")
        self.log(f"Volume envoyé: {format_bytes(self.bytes_uploaded)}")
        self.log(f"Dossiers créés ou vérifiés: {created_dirs}")
        self.log(f"Fichiers supprimés: {deleted_count}")
        self.log("────────────────────────────────────────")


def read_config(path: Path) -> dict:
    if not path.exists():
        raise DeployError(f"Configuration FTP introuvable: {path}")
    return json.loads(path.read_text(encoding="utf-8"))


def connect_ftp(config: dict, quiet: bool = False) -> ftplib.FTP:
    host = config["host"]
    port = int(config.get("port", 21))
    username = config["username"]
    password = config["password"]
    timeout = int(config.get("timeout", 30))
    use_tls = bool(config.get("tls", False))

    if not quiet:
        print(f"Connexion FTP: {host}:{port} — utilisateur {username}", flush=True)
        print(f"TLS: {'oui' if use_tls else 'non'}", flush=True)

    ftp: ftplib.FTP = ftplib.FTP_TLS() if use_tls else ftplib.FTP()
    ftp.connect(host, port, timeout=timeout)
    ftp.login(username, password)

    if use_tls and isinstance(ftp, ftplib.FTP_TLS):
        ftp.prot_p()

    passive = config.get("passive")
    if passive is not None:
        ftp.set_pasv(bool(passive))
        if not quiet:
            print(f"Mode passif: {'oui' if passive else 'non'}", flush=True)

    if not quiet:
        print("Connexion FTP établie.", flush=True)

    return ftp


def ensure_remote_dirs(ftp: ftplib.FTP, remote_dir: str, dry_run: bool, quiet: bool = False) -> int:
    remote_dir = remote_dir.strip()
    if not remote_dir:
        return 0

    created_or_checked = 0
    parts = [part for part in remote_dir.replace("\\", "/").split("/") if part]
    current = ""

    for part in parts:
        current = f"{current}/{part}" if current else f"/{part}"
        created_or_checked += 1

        if dry_run:
            if not quiet:
                print(f"[dry-run] mkdir -p {current}", flush=True)
            continue

        try:
            ftp.mkd(current)
            if not quiet:
                print(f"mkdir: {current}", flush=True)
        except ftplib.error_perm as exc:
            message = str(exc)
            if not (message.startswith("550") or "exists" in message.lower() or "File exists" in message):
                raise

    return created_or_checked


def remote_exists(ftp: ftplib.FTP, remote_path: str) -> bool:
    parent = posixpath.dirname(remote_path) or "/"
    name = posixpath.basename(remote_path)
    try:
        entries = ftp.nlst(parent)
    except ftplib.all_errors:
        return False

    normalized = {entry.rstrip("/") for entry in entries}
    return remote_path.rstrip("/") in normalized or name in normalized


def upload_file(ftp: ftplib.FTP, local_path: Path, remote_path: str, dry_run: bool, tracker: ProgressTracker) -> None:
    size = local_path.stat().st_size

    if dry_run:
        tracker.log(f"[dry-run] upload {local_path} -> {remote_path}")
        tracker.add_bytes(size)
        return

    sent = 0
    last_percent = -1

    with local_path.open("rb") as handle:
        def callback(chunk: bytes) -> None:
            nonlocal sent, last_percent
            sent += len(chunk)
            tracker.add_bytes(len(chunk))

            if size > 256 * 1024:
                percent = int((sent / size) * 100)
                if percent >= last_percent + 10 or percent == 100:
                    last_percent = percent
                    tracker.print_file_progress(sent, size)

        ftp.storbinary(f"STOR {remote_path}", handle, blocksize=64 * 1024, callback=callback)

    if size > 256 * 1024:
        tracker.end_file_progress()


def upload_json(ftp: ftplib.FTP, data: dict, remote_path: str, dry_run: bool, tracker: ProgressTracker | None = None) -> None:
    payload = json.dumps(data, ensure_ascii=False, indent=2).encode("utf-8") + b"\n"
    if dry_run:
        if tracker:
            tracker.log(f"[dry-run] upload-json {remote_path}")
            tracker.add_bytes(len(payload))
        else:
            print(f"[dry-run] upload-json {remote_path}")
        return
    ftp.storbinary(f"STOR {remote_path}", io.BytesIO(payload))
    if tracker:
        tracker.add_bytes(len(payload))


def append_remote_ndjson(ftp: ftplib.FTP, data: dict, remote_path: str, dry_run: bool, tracker: ProgressTracker | None = None) -> None:
    line = json.dumps(data, ensure_ascii=False, sort_keys=True) + "\n"
    if dry_run:
        if tracker:
            tracker.log(f"[dry-run] append-ndjson {remote_path}")
            tracker.add_bytes(len(line.encode("utf-8")))
        else:
            print(f"[dry-run] append-ndjson {remote_path}")
        return
    existing = ""
    try:
        chunks: list[bytes] = []
        ftp.retrbinary(f"RETR {remote_path}", chunks.append)
        existing = b"".join(chunks).decode("utf-8")
    except ftplib.all_errors:
        existing = ""
    payload = (existing + line).encode("utf-8")
    ftp.storbinary(f"STOR {remote_path}", io.BytesIO(payload))
    if tracker:
        tracker.add_bytes(len(payload))


def delete_remote_file(ftp: ftplib.FTP, remote_path: str, dry_run: bool, quiet: bool = False) -> bool:
    if dry_run:
        if not quiet:
            print(f"[dry-run] delete {remote_path}", flush=True)
        return True
    try:
        ftp.delete(remote_path)
        if not quiet:
            print(f"delete: {remote_path}", flush=True)
        return True
    except ftplib.error_perm as exc:
        message = str(exc)
        if not message.startswith("550"):
            raise
        if not quiet:
            print(f"skip delete introuvable: {remote_path}", flush=True)
        return False


def try_remove_empty_dirs(ftp: ftplib.FTP, remote_root: str, rel_path: str, dry_run: bool, quiet: bool = False) -> None:
    current = posixpath.dirname(posixpath.join(remote_root.rstrip("/"), rel_path))
    stop = remote_root.rstrip("/")
    while current and current != stop and current.startswith(stop):
        if dry_run:
            if not quiet:
                print(f"[dry-run] rmdir {current} (si vide)", flush=True)
        else:
            try:
                ftp.rmd(current)
                if not quiet:
                    print(f"rmdir: {current}", flush=True)
            except ftplib.all_errors:
                break
        current = posixpath.dirname(current)


def write_last_manifest(path: Path, remote_root: str, stage_dir: Path, files: dict[str, dict], release_manifest: dict | None) -> None:
    manifest = {
        "schema_version": 2,
        "remote_root": remote_root,
        "stage_dir": str(stage_dir),
        "release_id": release_manifest.get("release_id") if isinstance(release_manifest, dict) else None,
        "files": files,
    }
    write_json(path, manifest)


def file_sha256(path: Path) -> str | None:
    if not path.is_file():
        return None
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def files_fingerprint(files: dict[str, dict]) -> str:
    payload = {
        rel: {
            "sha256": str(meta.get("sha256", "")),
            "size": int(meta.get("size", 0)),
        }
        for rel, meta in sorted(files.items())
    }
    return hashlib.sha256(
        json.dumps(payload, sort_keys=True, separators=(",", ":")).encode("utf-8")
    ).hexdigest()


def build_locked_plan(
    *,
    stage_dir: Path,
    remote_root: str,
    release_manifest: dict,
    manifest_path: Path,
    current_files: dict[str, dict],
    uploads_new: list[str],
    uploads_changed: list[str],
    removals: list[str],
    protect_instance_data: bool,
    no_delete_removed: bool,
) -> dict:
    return {
        "schema_version": 1,
        "stage_dir": str(stage_dir.resolve()),
        "remote_root": remote_root,
        "release_id": release_manifest.get("release_id"),
        "stage_fingerprint": files_fingerprint(current_files),
        "previous_manifest_sha256": file_sha256(manifest_path),
        "protect_instance_data": protect_instance_data,
        "no_delete_removed": no_delete_removed,
        "delta": {
            "new": uploads_new,
            "changed": uploads_changed,
            "removed": removals,
        },
    }


def validate_locked_plan(expected: dict, current: dict) -> None:
    comparable_keys = (
        "schema_version",
        "stage_dir",
        "remote_root",
        "release_id",
        "stage_fingerprint",
        "previous_manifest_sha256",
        "protect_instance_data",
        "no_delete_removed",
        "delta",
    )
    differences = [key for key in comparable_keys if expected.get(key) != current.get(key)]
    if differences:
        raise DeployError(
            "Le staging ou le manifeste différentiel a changé depuis la simulation "
            f"({', '.join(differences)}). Relancez le point 11 pour produire un nouveau plan."
        )


def upload_deployment_markers(ftp: ftplib.FTP, remote_root: str, release_manifest: dict | None, deploy_report: dict, dry_run: bool, tracker: ProgressTracker, quiet: bool = False) -> int:
    base = posixpath.join(remote_root.rstrip("/"), REMOTE_DEPLOYMENT_DIR)
    releases = posixpath.join(base, "releases")
    if not quiet:
        print("")
        print("Publication des marqueurs de déploiement...", flush=True)
    created_dirs = ensure_remote_dirs(ftp, releases, dry_run, quiet=quiet)
    upload_json(ftp, deploy_report, posixpath.join(base, "current.json"), dry_run, tracker)
    append_remote_ndjson(ftp, deploy_report, posixpath.join(base, "history.ndjson"), dry_run, tracker)
    if isinstance(release_manifest, dict) and release_manifest.get("release_id"):
        upload_json(ftp, release_manifest, posixpath.join(releases, f"{release_manifest['release_id']}.json"), dry_run, tracker)
    return created_dirs


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Déploiement FTP différentiel d'un staging local préparé.")
    parser.add_argument("--config", default=str(DEFAULT_CONFIG), help="Fichier JSON de configuration FTP.")
    parser.add_argument("--stage-dir", default=str(DEFAULT_STAGE), help="Répertoire de staging ou archive ZIP de release à envoyer.")
    parser.add_argument("--remote-root", default="", help="Répertoire racine distant. Prioritaire sur la config.")
    parser.add_argument("--manifest", default=str(DEFAULT_MANIFEST), help="Manifest local du dernier déploiement réussi.")
    parser.add_argument("--dry-run", action="store_true", help="Affiche seulement les opérations prévues.")
    parser.add_argument("--plan-output", default="", help="Écrit le plan verrouillé produit par --dry-run.")
    parser.add_argument("--apply-plan", default="", help="Exige que l'exécution corresponde exactement à ce plan dry-run.")
    parser.add_argument("--no-delete-removed", action="store_true", help="Ne supprime pas les fichiers absents de la nouvelle version.")
    parser.add_argument("--no-server-markers", action="store_true", help="Ne téléverse pas storage/deployments/current.json/history.ndjson.")
    parser.add_argument("--protect-instance-data", action="store_true", help="Préserve bases, médias, secrets, logs, backups et modules locaux d'une instance existante.")
    parser.add_argument("--quiet", action="store_true", help="Réduit l'affichage au minimum.")
    return parser.parse_args()


def main() -> int:
    started_at = time.monotonic()
    args = parse_args()
    config_path = Path(args.config).expanduser()
    stage_source = Path(args.stage_dir).expanduser()
    manifest_path = Path(args.manifest).expanduser()
    if not config_path.is_absolute():
        config_path = ROOT / config_path
    if not stage_source.is_absolute():
        stage_source = ROOT / stage_source
    if not manifest_path.is_absolute():
        manifest_path = ROOT / manifest_path
    plan_output_path = Path(args.plan_output).expanduser() if args.plan_output else None
    apply_plan_path = Path(args.apply_plan).expanduser() if args.apply_plan else None
    if plan_output_path is not None and not plan_output_path.is_absolute():
        plan_output_path = ROOT / plan_output_path
    if apply_plan_path is not None and not apply_plan_path.is_absolute():
        apply_plan_path = ROOT / apply_plan_path
    if args.dry_run and apply_plan_path is not None:
        print("ERREUR: --apply-plan ne peut pas être combiné avec --dry-run.", file=sys.stderr)
        return 2
    if not args.dry_run and plan_output_path is not None:
        print("ERREUR: --plan-output exige --dry-run.", file=sys.stderr)
        return 2

    if not stage_source.exists():
        print(f"ERREUR: staging introuvable: {stage_source}", file=sys.stderr)
        return 2

    temporary_source: tempfile.TemporaryDirectory[str] | None = None
    try:
        if stage_source.is_file():
            temporary_source = tempfile.TemporaryDirectory(prefix="amcms-ftp-release-")
            stage_dir = web_update.resolve_source(stage_source, Path(temporary_source.name))
        else:
            stage_dir = stage_source
    except RuntimeError as exc:
        print(f"ERREUR: {exc}", file=sys.stderr)
        return 2

    release_manifest = read_release_manifest(stage_dir)
    if not isinstance(release_manifest, dict):
        print("ERREUR: release-manifest.json introuvable dans le staging. Relancez d2_package_release.py.", file=sys.stderr)
        return 2

    try:
        config = read_config(config_path)
    except DeployError as exc:
        print(f"ERREUR: {exc}", file=sys.stderr)
        return 2

    remote_root = args.remote_root or config.get("remote_root", "")
    if not remote_root:
        print("ERREUR: remote_root manquant.", file=sys.stderr)
        return 2

    current_files = collect_files(stage_dir)
    previous_manifest = read_json(manifest_path, default={})
    previous_files = previous_manifest.get("files", {}) if isinstance(previous_manifest, dict) else {}
    protected_prefixes = tuple(sorted(set(PROTECTED_PREFIXES + (INSTANCE_DATA_PREFIXES if args.protect_instance_data else ()))))
    uploads_new, uploads_changed, removals = compute_delta(previous_files, current_files, protected_prefixes)
    locked_plan = build_locked_plan(
        stage_dir=stage_dir,
        remote_root=remote_root,
        release_manifest=release_manifest,
        manifest_path=manifest_path,
        current_files=current_files,
        uploads_new=uploads_new,
        uploads_changed=uploads_changed,
        removals=removals,
        protect_instance_data=bool(args.protect_instance_data),
        no_delete_removed=bool(args.no_delete_removed),
    )
    if apply_plan_path is not None:
        expected_plan = read_json(apply_plan_path, default=None)
        if not isinstance(expected_plan, dict):
            print(f"ERREUR: plan FTP introuvable ou invalide: {apply_plan_path}", file=sys.stderr)
            return 2
        try:
            validate_locked_plan(expected_plan, locked_plan)
        except DeployError as exc:
            print(f"ERREUR: {exc}", file=sys.stderr)
            return 2
    if plan_output_path is not None:
        write_json(plan_output_path, locked_plan)
    effective_removals = [] if args.no_delete_removed else removals
    upload_queue = uploads_new + uploads_changed
    total_upload_size = sum((stage_dir / rel).stat().st_size for rel in upload_queue if (stage_dir / rel).exists())
    total_operations = len(upload_queue) + (0 if args.no_delete_removed else len(removals))
    tracker = ProgressTracker(total_operations=total_operations, quiet=args.quiet)

    if not args.quiet:
        print("")
        print("────────────────────────────────────────")
        print("Déploiement FTP")
        print("────────────────────────────────────────")
        print(f"Release ID: {release_manifest.get('release_id')}")
        print(f"Stage local: {stage_dir}")
        print(f"Remote root: {remote_root}")
        print(f"Mode: {'simulation dry-run' if args.dry_run else 'déploiement réel'}")
        print("")
        print(f"Nouveaux fichiers: {len(uploads_new)}")
        print(f"Fichiers modifiés: {len(uploads_changed)}")
        print(f"Fichiers à supprimer: {0 if args.no_delete_removed else len(removals)}")
        print(f"Volume prévu en upload: {format_bytes(total_upload_size)}")
        print("Protection active: " + ", ".join(protected_prefixes) + " ne sont jamais modifiés automatiquement.")
        print("────────────────────────────────────────")
        print("")

    ftp = None
    deploy_report = build_deploy_report(
        release_manifest=release_manifest,
        remote_root=remote_root,
        stage_dir=stage_dir,
        new_files=uploads_new,
        changed_files=uploads_changed,
        removed_files=effective_removals,
        dry_run=args.dry_run,
        status="planned" if args.dry_run else "running",
    )
    created_dirs_count = 0
    deleted_count = 0

    try:
        ftp = connect_ftp(config, quiet=args.quiet)
        created_dirs_count += ensure_remote_dirs(ftp, remote_root, args.dry_run, quiet=args.quiet)

        if upload_queue and not args.quiet:
            print("")
            print("Transfert des fichiers...", flush=True)

        for rel in upload_queue:
            local_path = stage_dir / rel
            remote_path = posixpath.join(remote_root.rstrip("/"), rel)
            size = local_path.stat().st_size
            tracker.start_operation("upload", rel, size)
            created_dirs_count += ensure_remote_dirs(ftp, posixpath.dirname(remote_path), args.dry_run, quiet=True)
            upload_file(ftp, local_path, remote_path, args.dry_run, tracker)

        if not args.no_delete_removed:
            if removals and not args.quiet:
                print("")
                print("Suppression des fichiers retirés...", flush=True)
            for rel in removals:
                tracker.start_operation("delete", rel)
                remote_path = posixpath.join(remote_root.rstrip("/"), rel)
                if args.dry_run or remote_exists(ftp, remote_path):
                    if delete_remote_file(ftp, remote_path, args.dry_run, quiet=args.quiet):
                        deleted_count += 1
                    try_remove_empty_dirs(ftp, remote_root, rel, args.dry_run, quiet=args.quiet)
                elif not args.quiet:
                    print(f"skip delete introuvable: {remote_path}", flush=True)

        final_status = "planned" if args.dry_run else "deployed"
        deploy_report = build_deploy_report(
            release_manifest=release_manifest,
            remote_root=remote_root,
            stage_dir=stage_dir,
            new_files=uploads_new,
            changed_files=uploads_changed,
            removed_files=effective_removals,
            dry_run=args.dry_run,
            status=final_status,
        )
        write_local_deployment_markers(ROOT, release_manifest, deploy_report)
        if not args.no_server_markers:
            created_dirs_count += upload_deployment_markers(ftp, remote_root, release_manifest, deploy_report, args.dry_run, tracker, quiet=args.quiet)

        if not args.dry_run:
            write_last_manifest(manifest_path, remote_root, stage_dir, current_files, release_manifest)
            if not args.quiet:
                print("")
                print(f"Manifest local mis à jour: {manifest_path}", flush=True)
        elif not args.quiet:
            print("")
            print(f"[dry-run] manifest local non écrit: {manifest_path}", flush=True)
            if plan_output_path is not None:
                print(f"[dry-run] plan verrouillé écrit: {plan_output_path}", flush=True)

        tracker.summary(deleted_count=deleted_count, created_dirs=created_dirs_count, dry_run=args.dry_run)
        return 0

    except Exception as exc:  # noqa: BLE001
        elapsed = time.monotonic() - started_at
        deploy_report = build_deploy_report(
            release_manifest=release_manifest,
            remote_root=remote_root,
            stage_dir=stage_dir,
            new_files=uploads_new,
            changed_files=uploads_changed,
            removed_files=effective_removals,
            dry_run=args.dry_run,
            status="failed",
            error=str(exc),
        )
        write_local_deployment_markers(ROOT, release_manifest, deploy_report)
        print("", file=sys.stderr)
        print("────────────────────────────────────────", file=sys.stderr)
        print("ERREUR de déploiement FTP", file=sys.stderr)
        print("────────────────────────────────────────", file=sys.stderr)
        print(f"Après: {format_duration(elapsed)}", file=sys.stderr)
        print(f"Erreur: {exc}", file=sys.stderr)
        print("────────────────────────────────────────", file=sys.stderr)
        return 1

    finally:
        if ftp is not None:
            try:
                ftp.quit()
            except Exception:
                try:
                    ftp.close()
                except Exception:
                    pass


if __name__ == "__main__":
    raise SystemExit(main())
