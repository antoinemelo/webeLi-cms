#!/usr/bin/env python3
"""Chaîne CI/CD minimale non interactive pour DEC CMS.

Ce script est conçu pour GitHub Actions, mais reste lançable localement.
En CI, les secrets de déploiement sont lus depuis les variables d'environnement.
En local, le déploiement FTP peut aussi utiliser ops/ftp.deploy.json, fichier
ignoré par Git et exclu des archives de release.
"""
from __future__ import annotations

import argparse
import json
import os
import shlex
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

from tools.python.lib.release_metadata import load_release_metadata

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
PYTHON_DIR = ROOT / "tools" / "python"
DATABASE_OPS = PYTHON_DIR / "operations" / "database"
DEPLOYMENT_OPS = PYTHON_DIR / "operations" / "deployment"
VALIDATORS_DIR = PYTHON_DIR / "validators"
ADMIN_DIR = ROOT / "frontend" / "admin-vue"
EXPORT_DIR = ROOT / "storage" / "exports"
DEFAULT_STAGE = EXPORT_DIR / "release_stage"
PROTECTED_RSYNC_PATTERNS = [
    "storage/database/***",
    "storage/media/***",
    "storage/uploads/***",
    "storage/security/***",
    "storage/logs/***",
    "storage/backups/***",
    "ops/.env",
    "ops/ftp.deploy.json",
    "vendor/***",
    "backend/vendor/***",
    "node_modules/***",
    "frontend/admin-vue/node_modules/***",
]


class CiError(RuntimeError):
    pass


def bool_env(name: str, default: bool = False) -> bool:
    value = os.getenv(name, "").strip().lower()
    if value in {"1", "true", "yes", "y", "on", "oui"}:
        return True
    if value in {"0", "false", "no", "n", "off", "non"}:
        return False
    return default


def run(command: list[str], *, cwd: Path = ROOT, env: dict[str, str] | None = None) -> None:
    print("\n>>> " + " ".join(shlex.quote(part) for part in command), flush=True)
    completed = subprocess.run(command, cwd=str(cwd), env=env)
    if completed.returncode != 0:
        raise CiError(f"Commande en échec ({completed.returncode}): {' '.join(command)}")


def capture(command: list[str], *, cwd: Path = ROOT) -> str:
    try:
        completed = subprocess.run(command, cwd=str(cwd), text=True, capture_output=True, check=False)
    except FileNotFoundError:
        return "introuvable"
    text = (completed.stdout or completed.stderr or "").strip().splitlines()
    return text[0] if text else f"code {completed.returncode}"


def print_versions() -> None:
    print("Versions CI détectées")
    for label, command in {
        "python": [sys.executable, "--version"],
        "php": ["php", "-v"],
        "composer": ["composer", "--version"],
        "node": ["node", "--version"],
        "npm": ["npm", "--version"],
        "rsync": ["rsync", "--version"],
        "ssh": ["ssh", "-V"],
    }.items():
        print(f"- {label}: {capture(command)}")


def install_php_dependencies() -> None:
    composer_json = ROOT / "backend" / "composer.json"
    if not composer_json.exists():
        print("backend/composer.json absent: installation Composer sautée.")
        return
    run([
        "composer",
        "install",
        "--working-dir",
        "backend",
        "--no-interaction",
        "--prefer-dist",
        "--no-progress",
    ])


def install_node_dependencies() -> None:
    package_json = ADMIN_DIR / "package.json"
    if not package_json.exists():
        print("frontend/admin-vue/package.json absent: installation Node sautée.")
        return
    lock = ADMIN_DIR / "package-lock.json"
    command = ["npm", "ci", "--no-audit", "--no-fund"] if lock.exists() else ["npm", "install", "--no-audit", "--no-fund"]
    run(command, cwd=ADMIN_DIR)


def build_admin() -> None:
    package_json = ADMIN_DIR / "package.json"
    if not package_json.exists():
        print("Back-office Vue absent: build admin sauté.")
        return
    install_node_dependencies()
    run(["npm", "run", "build"], cwd=ADMIN_DIR)


def run_from_scratch_database_chain() -> None:
    run([sys.executable, str(DATABASE_OPS / "a_db_init.py"), "--seed-skip-projections"])
    for script in ["b1_rebuild_projections.py", "b2_cleanup_noindex_search_documents.py", "b3_rebuild_search.py"]:
        path = DATABASE_OPS / script
        if path.exists():
            run([sys.executable, str(path)])
        else:
            print(f"{script} absent: étape sautée.")


def run_essential_validators(require_vue_build: bool) -> None:
    """Alias temporaire vers la qualification standard canonique."""
    if require_vue_build:
        print("Le build admin est déjà exécuté par la chaîne CI avant la qualification.")
    run([sys.executable, "-m", "tools.python.qualification.run_all", "--profile", "complete"])


def package_release(include_vendor: bool) -> Path:
    metadata = load_release_metadata()
    command = [sys.executable, str(DEPLOYMENT_OPS / "d2_package_release.py")]
    if include_vendor:
        command.append("--include-vendor")
    run(command)
    archive = EXPORT_DIR / metadata.technical_version / f"{metadata.package_name()}.zip"
    if not archive.is_file():
        raise CiError(f"Archive de release non produite: {archive}")
    return archive


def verify_archive(archive: Path) -> None:
    run([sys.executable, str(DEPLOYMENT_OPS / "d4_verify_release_archive.py"), "--archive", str(archive)])


def require_env(names: list[str]) -> dict[str, str]:
    values: dict[str, str] = {}
    missing: list[str] = []
    for name in names:
        value = os.getenv(name, "")
        if not value:
            missing.append(name)
        values[name] = value
    if missing:
        raise CiError("Secrets/variables manquants pour le déploiement: " + ", ".join(missing))
    return values


def resolve_ftp_config() -> tuple[Path | None, dict[str, object] | None]:
    required = ["FTP_HOST", "FTP_USERNAME", "FTP_PASSWORD", "FTP_REMOTE_ROOT"]
    present = [name for name in required if os.getenv(name, "")]

    if present:
        values = require_env(required)
        return None, {
            "host": values["FTP_HOST"],
            "port": int(os.getenv("FTP_PORT") or "21"),
            "username": values["FTP_USERNAME"],
            "password": values["FTP_PASSWORD"],
            "remote_root": values["FTP_REMOTE_ROOT"],
            "tls": bool_env("FTP_TLS", True),
            "passive": bool_env("FTP_PASSIVE", True),
            "timeout": int(os.getenv("FTP_TIMEOUT") or "30"),
        }

    local_config = ROOT / "ops" / "ftp.deploy.json"
    if local_config.is_file():
        print("Configuration FTP locale détectée: ops/ftp.deploy.json")
        return local_config, None

    raise CiError(
        "Configuration FTP absente. Définissez FTP_HOST, FTP_USERNAME, "
        "FTP_PASSWORD et FTP_REMOTE_ROOT, ou créez ops/ftp.deploy.json "
        "à partir de ops/ftp.deploy.example.json."
    )


def deploy_ftp() -> None:
    config_path, config = resolve_ftp_config()
    if config_path is not None:
        run([sys.executable, str(DEPLOYMENT_OPS / "d3_deploy_ftp.py"), "--config", str(config_path), "--stage-dir", str(DEFAULT_STAGE)])
        return

    assert config is not None
    with tempfile.TemporaryDirectory(prefix="am-cms-ftp-") as tmp:
        temporary_config = Path(tmp) / "ftp.deploy.json"
        temporary_config.write_text(json.dumps(config, ensure_ascii=False, indent=2), encoding="utf-8")
        run([sys.executable, str(DEPLOYMENT_OPS / "d3_deploy_ftp.py"), "--config", str(temporary_config), "--stage-dir", str(DEFAULT_STAGE)])


def deploy_sftp() -> None:
    values = require_env(["SFTP_HOST", "SFTP_USERNAME", "SFTP_PRIVATE_KEY", "SFTP_REMOTE_ROOT"])
    if not DEFAULT_STAGE.exists():
        raise CiError("Staging absent: lancez d2_package_release.py avant le déploiement SFTP.")
    rsync = shutil.which("rsync")
    ssh = shutil.which("ssh")
    if rsync is None or ssh is None:
        raise CiError("rsync et ssh sont requis pour le déploiement SFTP.")

    port = os.getenv("SFTP_PORT") or "22"
    remote = f"{values['SFTP_USERNAME']}@{values['SFTP_HOST']}:{values['SFTP_REMOTE_ROOT'].rstrip('/')}/"
    with tempfile.TemporaryDirectory(prefix="am-cms-sftp-") as tmp:
        key_path = Path(tmp) / "deploy_key"
        key_path.write_text(values["SFTP_PRIVATE_KEY"].replace("\\n", "\n"), encoding="utf-8")
        key_path.chmod(0o600)
        exclude_args: list[str] = []
        for pattern in PROTECTED_RSYNC_PATTERNS:
            exclude_args.extend(["--exclude", pattern])
        command = [
            "rsync",
            "-az",
            "--delete",
            "--delay-updates",
            *exclude_args,
            "-e",
            f"ssh -i {shlex.quote(str(key_path))} -p {shlex.quote(port)} -o StrictHostKeyChecking=accept-new",
            str(DEFAULT_STAGE) + "/",
            remote,
        ]
        run(command)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Chaîne CI/CD DEC CMS non interactive.")
    parser.add_argument("--skip-composer", action="store_true", help="N'installe pas les dépendances Composer.")
    parser.add_argument("--skip-db-init", action="store_true", help="Ne reconstruit pas les bases SQLite from scratch.")
    parser.add_argument("--build-admin", action="store_true", help="Installe les dépendances Node et build le back-office Vue.")
    parser.add_argument("--run-essential-validators", action="store_true", help="Lance la qualification standard canonique après reconstruction.")
    parser.add_argument("--package", action="store_true", help="Prépare l'archive de release avec d2_package_release.py.")
    parser.add_argument("--include-vendor", action="store_true", help="Inclut backend/vendor dans le package de release.")
    parser.add_argument("--verify-archive", action="store_true", help="Vérifie l'archive ZIP produite.")
    parser.add_argument("--deploy", choices=["ftp", "sftp"], default=None, help="Déploie le staging déjà préparé via le protocole demandé.")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    try:
        print_versions()
        if args.deploy:
            if args.deploy == "ftp":
                deploy_ftp()
            else:
                deploy_sftp()
            return 0

        if not args.skip_composer:
            install_php_dependencies()
        if not args.skip_db_init:
            run_from_scratch_database_chain()
        if args.build_admin:
            build_admin()
        if args.run_essential_validators:
            run_essential_validators(require_vue_build=args.build_admin)
        archive: Path | None = None
        if args.package:
            archive = package_release(include_vendor=args.include_vendor)
            print(f"Archive produite: {archive.relative_to(ROOT)}")
        if args.verify_archive:
            if archive is None:
                candidates = sorted(EXPORT_DIR.glob("*/*.zip"), key=lambda path: path.stat().st_mtime, reverse=True)
                if not candidates:
                    raise CiError("Aucune archive à vérifier.")
                archive = candidates[0]
            verify_archive(archive)
        print("Chaîne CI/CD terminée avec succès.")
        return 0
    except CiError as exc:
        print(f"ERREUR CI/CD: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
