from __future__ import annotations

import json
import os
import shutil
import subprocess
import sys
import tempfile
import time
from pathlib import Path
from typing import Any

from tools.python.cms.runtime import python_script
from tools.python.operations.deployment import d8_deploy_web_update as web_update

PROTECTED_INSTANCE_PATHS = (
    "storage/database/",
    "storage/media/",
    "storage/uploads/",
    "storage/logs/",
    "storage/backups/",
    "ops/.env",
    "ops/modules.local.json",
    "local/modules/",
)


def configure(p):
    sub = p.add_subparsers(dest="instance_action", required=True)
    clone = sub.add_parser("clone", help="Cloner une instance locale dans un autre répertoire.")
    clone.add_argument("--source", help="Répertoire source. Par défaut: racine CMS courante.")
    clone.add_argument("--destination", required=True, help="Répertoire destination à créer, par exemple ../mod2 ou ../eve.")
    clone.add_argument("--old-base-path", help="APP_BASE_PATH public source. Par défaut: valeur de ops/.env, puis nom du répertoire source.")
    clone.add_argument("--new-base-path", "--target-base-path", dest="new_base_path", required=True, help="APP_BASE_PATH public cible exact, par exemple /cms/main, /new/main ou /cms2.")
    clone.add_argument("--new-public-base-url", help="APP_PUBLIC_BASE_URL exact à écrire dans ops/.env.")
    clone.add_argument("--force", action="store_true", help="Remplace la destination si elle existe.")
    clone.add_argument("--include-dev-admin-vue", action="store_true", help="Inclut les sources frontend/admin-vue.")
    clone.add_argument("--include-docs", action="store_true", help="Inclut docs/, README.md et TREE.txt.")

    update = sub.add_parser("update", help="Mettre à jour une instance client locale depuis une release.")
    update.add_argument("--source", required=True, help="Archive ZIP ou dossier racine de release à déployer.")
    update.add_argument("--target", required=True, help="Dossier racine de l'instance client à mettre à jour.")
    mode = update.add_mutually_exclusive_group(required=True)
    mode.add_argument("--plan", action="store_true", help="Affiche le plan fichiers/migrations sans modifier la cible.")
    mode.add_argument("--apply", action="store_true", help="Applique la mise à jour après plan, backup et confirmation.")
    update.add_argument("--backup", action="store_true", help="Crée un backup SQLite avant application.")
    update.add_argument("--yes", action="store_true", help="Confirme explicitement l'application.")
    update.add_argument("--delete-obsolete", action="store_true", help="Supprime les fichiers absents de la release, hors chemins protégés.")
    update.add_argument("--maintenance-flag", action="store_true", help="Crée storage/maintenance.flag pendant la copie fichiers.")
    update.add_argument("--json", dest="instance_json", action="store_true", help="Affiche un résumé JSON stable.")


def _iso_now() -> str:
    return time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())


def _stamp() -> str:
    return time.strftime("%Y%m%d-%H%M%S", time.gmtime())


def _target_cms(target_root: Path) -> Path:
    cms = target_root / "tools" / "cms.py"
    if not cms.is_file():
        raise RuntimeError(f"Instance cible invalide: tools/cms.py absent dans {target_root}")
    if not (target_root / "backend").is_dir():
        raise RuntimeError(f"Instance cible invalide: backend/ absent dans {target_root}")
    return cms


def _source_path(value: str) -> Path:
    path = Path(value).expanduser()
    if not path.is_absolute():
        path = Path.cwd() / path
    if not path.exists():
        raise RuntimeError(f"Source absente: {path}")
    return path.resolve()


def _target_path(value: str) -> Path:
    path = Path(value).expanduser()
    if not path.is_absolute():
        path = Path.cwd() / path
    if not path.exists() or not path.is_dir():
        raise RuntimeError(f"Cible absente ou invalide: {path}")
    return path.resolve()


def _delta_summary(delta: web_update.Delta) -> dict[str, Any]:
    return {
        "added": len(delta.added),
        "changed": len(delta.changed),
        "removed": len(delta.removed),
        "protected_skipped": len(delta.protected_skipped),
        "protected_paths": list(PROTECTED_INSTANCE_PATHS),
        "samples": {
            "added": delta.added[:20],
            "changed": delta.changed[:20],
            "removed": delta.removed[:20],
            "protected_skipped": delta.protected_skipped[:20],
        },
    }


def _release_version(source_root: Path) -> str | None:
    for relative in ("config/release.json", "storage/deployments/release-manifest.json"):
        path = source_root / relative
        if not path.is_file():
            continue
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            continue
        value = data.get("technical_version") or data.get("version") or data.get("release")
        if value:
            return str(value)
    return None


def _run_target_command(target_root: Path, argv: list[str], *, timeout: int = 600) -> dict[str, Any]:
    cms = _target_cms(target_root)
    current_pythonpath = os.environ.get("PYTHONPATH", "")
    env = {**os.environ, "PYTHONPATH": os.pathsep.join([str(target_root), current_pythonpath]) if current_pythonpath else str(target_root)}
    started = time.monotonic()
    completed = subprocess.run(
        [sys.executable, str(cms), *argv],
        cwd=str(target_root),
        env=env,
        text=True,
        capture_output=True,
        timeout=timeout,
    )
    return {
        "command": [sys.executable, str(cms), *argv],
        "returncode": int(completed.returncode),
        "duration_ms": round((time.monotonic() - started) * 1000),
        "stdout": completed.stdout,
        "stderr": completed.stderr,
    }


def _run_checked(target_root: Path, argv: list[str], *, label: str, timeout: int = 600) -> dict[str, Any]:
    result = _run_target_command(target_root, argv, timeout=timeout)
    if result["returncode"] != 0:
        raise RuntimeError(
            f"{label} a échoué avec le code {result['returncode']}.\n"
            f"STDOUT:\n{result['stdout']}\nSTDERR:\n{result['stderr']}"
        )
    return result


def _copy_if_exists(source: Path, destination: Path) -> None:
    if not source.exists():
        return
    if source.is_dir():
        if destination.exists():
            shutil.rmtree(destination)
        shutil.copytree(source, destination, symlinks=True)
    else:
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(source, destination)


def _build_migration_plan_target(source_root: Path, target_root: Path, temp_root: Path) -> Path:
    simulation = temp_root / "migration-plan-target"
    ignore = shutil.ignore_patterns(
        ".git",
        ".idea",
        ".vscode",
        "__pycache__",
        "node_modules",
        "vendor",
        "backend/vendor",
        "storage/backups",
        "storage/logs",
        "storage/cache",
    )
    shutil.copytree(source_root, simulation, ignore=ignore, symlinks=True)
    # Le plan migrations doit être calculé avec le code de la release, mais sur
    # une copie des bases et modules locaux de la cible. Ces copies restent dans
    # un dossier temporaire afin que --plan demeure non mutatif pour l'instance.
    for rel in (
        "storage/database",
        "ops/.env",
        "ops/modules.local.json",
        "local/modules",
    ):
        _copy_if_exists(target_root / rel, simulation / rel)
    return simulation


def _migration_plan(source_root: Path, target_root: Path, temp_root: Path) -> dict[str, Any]:
    temp_root.mkdir(parents=True, exist_ok=True)
    simulation = _build_migration_plan_target(source_root, target_root, temp_root)
    return _run_checked(simulation, ["migrate", "--plan"], label="plan de migrations", timeout=600)


def _create_sqlite_backup(target_root: Path) -> Path:
    backup_path = target_root / "storage" / "backups" / "sqlite" / f"instance-update-{_stamp()}.zip"
    result = _run_checked(
        target_root,
        ["backup", "--output", str(backup_path)],
        label="backup SQLite",
        timeout=600,
    )
    if not backup_path.is_file():
        raise RuntimeError(f"Backup SQLite attendu introuvable: {backup_path}\n{result['stdout']}\n{result['stderr']}")
    return backup_path


def _apply_file_update(source_root: Path, target_root: Path, args: Any, delta: web_update.Delta) -> Path:
    manifest = web_update.deployment_manifest(source_root, target_root, delta)
    rollback_dir = target_root / web_update.BACKUP_DIR_NAME
    rollback_archive = web_update.create_rollback_archive(target_root, delta, manifest, rollback_dir)
    maintenance_created = False
    maintenance_flag = target_root / web_update.MAINTENANCE_FLAG_NAME
    try:
        if args.maintenance_flag:
            maintenance_flag.parent.mkdir(parents=True, exist_ok=True)
            maintenance_flag.write_text("Maintenance temporaire pendant mise à jour locale.\n", encoding="utf-8")
            maintenance_created = True
        for rel in delta.added + delta.changed:
            web_update.copy_file(source_root, target_root, rel)
        for rel in delta.removed:
            web_update.remove_file(target_root, rel)
    finally:
        if maintenance_created and maintenance_flag.exists():
            maintenance_flag.unlink()
    return rollback_archive


def _write_operation_log(target_root: Path, journal: dict[str, Any]) -> Path:
    directory = target_root / "storage" / "operations" / "instance-updates"
    directory.mkdir(parents=True, exist_ok=True)
    path = directory / f"{_stamp()}-update.json"
    path.write_text(json.dumps(journal, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    return path


def _json_output(args: Any) -> bool:
    return bool(getattr(args, "json", False) or getattr(args, "instance_json", False))


def _print_plan(plan: dict[str, Any], json_output: bool) -> None:
    if json_output:
        print(json.dumps(plan, ensure_ascii=False, indent=2))
        return
    files = plan["file_delta_summary"]
    print("Plan de mise à jour d'instance")
    print(f"Source : {plan['source']}")
    print(f"Cible  : {plan['target']}")
    print(f"Release: {plan.get('release_version') or 'inconnue'}")
    print(
        "Fichiers : "
        f"+{files['added']} / ~{files['changed']} / -{files['removed']} / "
        f"protégés {files['protected_skipped']}"
    )
    migration_stdout = plan.get("migration_summary", {}).get("stdout", "").strip()
    print("Migrations :")
    print(migration_stdout or "Aucun détail retourné par le migrateur.")


def _run_instance_update(args: Any) -> int:
    if args.apply and (not args.yes or not args.backup):
        raise RuntimeError("instance update --apply exige --backup et --yes.")

    source = _source_path(args.source)
    target_root = _target_path(args.target)
    _target_cms(target_root)

    started_at = _iso_now()
    with tempfile.TemporaryDirectory(prefix="amcms-instance-update-") as tmp:
        temp_root = Path(tmp)
        source_root = web_update.resolve_source(source, temp_root).resolve()
        delta = web_update.compute_delta(source_root, target_root, bool(args.delete_obsolete))
        file_summary = _delta_summary(delta)
        migration_summary = _migration_plan(source_root, target_root, temp_root / "plan")
        release_version = _release_version(source_root)

        if args.plan:
            plan = {
                "status": "plan",
                "source": str(source),
                "target": str(target_root),
                "release_version": release_version,
                "file_delta_summary": file_summary,
                "migration_summary": {
                    "returncode": migration_summary["returncode"],
                    "duration_ms": migration_summary["duration_ms"],
                    "stdout": migration_summary["stdout"],
                    "stderr": migration_summary["stderr"],
                },
            }
            _print_plan(plan, _json_output(args))
            return 0

        journal: dict[str, Any] = {
            "schema_version": 1,
            "source": str(source),
            "target": str(target_root),
            "started_at": started_at,
            "finished_at": None,
            "status": "running",
            "release_version": release_version,
            "file_delta_summary": file_summary,
            "backup_path": None,
            "file_rollback_archive": None,
            "migration_summary": None,
            "validation_summary": None,
            "error": None,
        }
        try:
            backup_path = _create_sqlite_backup(target_root)
            journal["backup_path"] = str(backup_path)
            rollback_archive = _apply_file_update(source_root, target_root, args, delta)
            journal["file_rollback_archive"] = str(rollback_archive)
            migration_after_update = _run_checked(
                target_root,
                ["migrate", "--plan"],
                label="plan de migrations après mise à jour fichiers",
                timeout=600,
            )
            migration_apply = _run_checked(
                target_root,
                ["migrate", "--apply", "--backup", "--yes"],
                label="application des migrations",
                timeout=600,
            )
            journal["migration_summary"] = {
                "plan_returncode": migration_after_update["returncode"],
                "plan_stdout": migration_after_update["stdout"],
                "apply_returncode": migration_apply["returncode"],
                "apply_stdout": migration_apply["stdout"],
                "apply_stderr": migration_apply["stderr"],
            }
            validation = _run_checked(
                target_root,
                [
                    "validate",
                    "--category", "configuration",
                    "--category", "database",
                    "--category", "operations",
                    "--category", "security",
                ],
                label="validations essentielles",
                timeout=600,
            )
            journal["validation_summary"] = {
                "returncode": validation["returncode"],
                "stdout": validation["stdout"],
                "stderr": validation["stderr"],
            }
            journal["status"] = "success"
            return_code = 0
        except Exception as exc:
            journal["status"] = "failed"
            journal["error"] = str(exc)
            return_code = 1
        finally:
            journal["finished_at"] = _iso_now()
            log_path = _write_operation_log(target_root, journal)
            if _json_output(args):
                payload = {**journal, "operation_log": str(log_path)}
                print(json.dumps(payload, ensure_ascii=False, indent=2))
            else:
                print(f"Journal instance update: {log_path}")
                if journal["status"] == "success":
                    print("Mise à jour d'instance terminée.")
                else:
                    print(f"ERREUR: {journal['error']}", file=sys.stderr)
        return return_code


def _run_clone(ctx, args):
    values = []
    if args.source:
        values.extend(["--source", args.source])
    values.extend(["--destination", args.destination])
    if args.old_base_path:
        values.extend(["--old-base-path", args.old_base_path])
    if args.new_base_path:
        values.extend(["--new-base-path", args.new_base_path])
    if args.new_public_base_url:
        values.extend(["--new-public-base-url", args.new_public_base_url])
    if args.force:
        values.append("--force")
    if ctx.dry_run:
        values.append("--dry-run")
    if args.include_dev_admin_vue:
        values.append("--include-dev-admin-vue")
    if args.include_docs:
        values.append("--include-docs")

    return python_script(ctx, "tools/python/operations/deployment/d14_clone_instance.py", values)


def run(ctx, args):
    if args.instance_action == "clone":
        return _run_clone(ctx, args)
    if args.instance_action == "update":
        if ctx.dry_run and args.apply:
            args.plan = True
            args.apply = False
        return _run_instance_update(args)
    raise ValueError(f"Action instance inconnue: {args.instance_action}")
