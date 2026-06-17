#!/usr/bin/env python3
"""Point d'entrée unique pour la chaîne `d_...`.

Usage courant, depuis la racine `mod/` :

    python3 tools/python/operations/deployment/d_deploy.py

Sans argument, le script exécute la chaîne de livraison :
1. `tools/cms.py qualify --profile release`
2. `d1_preflight_local.py`
3. `d2_package_release.py`

`d12_quality_gate.py` reste uniquement un alias de compatibilité externe et
n'est plus appelé par la chaîne native.

Les validations `c_...` restent volontairement séparées :

    python3 tools/cms.py validate

`d_deploy.py` ne reconstruit pas les bases, ne lance aucun script `a_...`,
aucun script `b_...` et aucun script `c_...`. Les scripts `a_...` restent
réservés explicitement à l'initialisation locale from scratch.
"""
from __future__ import annotations

import argparse
import os
import subprocess
import sys
from dataclasses import dataclass
from pathlib import Path


ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from tools.python.lib.release_metadata import load_release_metadata
PYTHON_DIR = ROOT / "tools" / "python"


@dataclass(frozen=True)
class Step:
    label: str
    script: str
    args: tuple[str, ...] = ()

    @property
    def path(self) -> Path:
        if self.script == "cms.py":
            return ROOT / "tools" / "cms.py"
        if self.script in {"d6_backup_sqlite.py", "d7_restore_sqlite.py"}:
            return PYTHON_DIR / "operations" / "backup" / self.script
        if self.script in {"d9_migrate_sqlite.py", "d10_rebase_sqlite_data.py"}:
            return PYTHON_DIR / "operations" / "database" / self.script
        return PYTHON_DIR / "operations" / "deployment" / self.script

    def command(self) -> list[str]:
        return [sys.executable, str(self.path), *self.args]


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description=(
            "Orchestrateur de livraison DEC CMS. Sans argument, lance "
            "tools/cms.py qualify --profile release, puis le préflight et le packaging."
        )
    )
    parser.add_argument(
        "mode",
        nargs="?",
        choices=["release", "preflight", "package", "verify", "smoke", "backup", "restore", "web-plan", "web-apply", "web-rollback", "migrate-plan", "migrate", "rebase-plan", "rebase", "ftp-dry-run", "ftp-deploy"],
        default="release",
        help="Action d_ à exécuter. Par défaut: release = d1 puis d2.",
    )
    parser.add_argument("--plan-only", action="store_true", help="Affiche les commandes prévues sans les exécuter.")
    parser.add_argument("--prepare", action="store_true", help="Lance d0_prepare_release.py avant d1 et d2 dans la même chaîne de release.")
    parser.add_argument("--skip-preflight", action="store_true", help="Saute d1_preflight_local.py dans le mode release.")
    parser.add_argument("--exclude-databases", action="store_true", help="Passe --exclude-databases à d2_package_release.py. Par défaut, les bases SQLite sont incluses.")
    parser.add_argument("--include-vendor", action="store_true", help="Passe --include-vendor à d2_package_release.py.")
    parser.add_argument("--no-zip", action="store_true", help="Passe --no-zip à d2_package_release.py.")
    parser.add_argument("--clean-stage", action="store_true", help="Passe --clean-stage à d2_package_release.py.")
    parser.add_argument("--ftp-config", default=None, help="Fichier de configuration FTP à passer à d3_deploy_ftp.py.")
    parser.add_argument("--archive", default=None, help="Archive ZIP à vérifier ou restaurer, selon le mode.")
    parser.add_argument("--backup-output", default=None, help="Chemin de sortie pour le mode backup.")
    parser.add_argument("--yes", action="store_true", help="Confirme les actions destructrices ou sensibles (restore, web-apply, web-rollback, migrate).")
    parser.add_argument("--source", default=None, help="Source release pour web-plan/web-apply: dossier racine ou archive ZIP.")
    parser.add_argument("--target-path", default=None, help="Cible pour web-plan/web-apply/web-rollback, par exemple ../eve.")
    parser.add_argument("--delete-obsolete", action="store_true", help="Supprime les fichiers absents de la release en mode web-apply, hors chemins protégés.")
    parser.add_argument("--rollback-archive", default=None, help="Archive rollback-web-update-*.zip pour web-rollback.")
    parser.add_argument("--database", choices=["core", "iam", "forms", "cookies"], default=None, help="Base à migrer avec migrate-plan/migrate. Sans valeur avec migrate: --all.")
    parser.add_argument("--backup", action="store_true", help="Crée une sauvegarde SQLite avant migrate.")
    parser.add_argument("--source-data", default=None, help="Base SQLite contenant les données vivantes pour rebase-plan/rebase.")
    parser.add_argument("--target-structure", default=None, help="Base SQLite servant de structure de référence pour rebase-plan/rebase.")
    parser.add_argument("--output", default=None, help="Base SQLite produite par rebase-plan/rebase.")
    parser.add_argument("--overwrite", action="store_true", help="Autorise rebase à écraser --output.")
    parser.add_argument("--include-technical", action="store_true", help="Inclut les tables techniques reconstruisibles dans rebase. Déconseillé.")
    parser.add_argument("--copy-native", action="store_true", help="Remplace aussi les tables natives lors du rebase. Déconseillé.")
    return parser.parse_args()


def package_args(args: argparse.Namespace) -> tuple[str, ...]:
    values: list[str] = []
    if args.exclude_databases:
        values.append("--exclude-databases")
    if args.include_vendor:
        values.append("--include-vendor")
    if args.no_zip:
        values.append("--no-zip")
    if args.clean_stage:
        values.append("--clean-stage")
    return tuple(values)


def ftp_args(args: argparse.Namespace, dry_run: bool) -> tuple[str, ...]:
    values: list[str] = []
    if args.ftp_config:
        values.extend(["--config", args.ftp_config])
    if dry_run:
        values.append("--dry-run")
    return tuple(values)


def is_reproducible_audit_required() -> bool:
    """Les preuves reproductibles sont obligatoires uniquement pour minor/major."""
    if os.getenv("DEC_CMS_AUDIT_INTERNAL", "").strip().lower() in {"1", "true", "yes"}:
        return False
    return load_release_metadata().release_type in {"minor", "major"}


def release_steps(args: argparse.Namespace) -> list[Step]:
    """Construit la chaîne après l'éventuelle préparation interactive."""
    metadata = load_release_metadata()
    if is_reproducible_audit_required():
        quality = Step(
            "Audit reproductible obligatoire",
            "cms.py",
            ("audit", "--profile", "release", "--build"),
        )
    else:
        quality = Step(
            "Porte de qualité patch",
            "cms.py",
            ("qualify", "--profile", "release"),
        )

    steps = [quality]
    if not args.skip_preflight:
        steps.append(Step("Préflight local", "d1_preflight_local.py"))
    steps.append(Step("Package release", "d2_package_release.py", package_args(args)))
    steps.append(Step("Vérification archive", "d4_verify_release_archive.py"))
    if metadata.release_type in {"minor", "major"} and not os.getenv("DEC_CMS_AUDIT_INTERNAL"):
        steps.append(Step("Association release et preuves", "d13_bind_release_evidence.py"))
    return steps


def build_steps(args: argparse.Namespace) -> list[Step]:
    prepare = Step("Préparation interactive de la release", "d0_prepare_release.py", ("--signal-cancel",))
    quality = Step("Porte de qualité release", "cms.py", ("qualify", "--profile", "release"))
    preflight = Step("Préflight local", "d1_preflight_local.py")
    package = Step("Package release", "d2_package_release.py", package_args(args))

    if args.mode == "preflight":
        return [preflight]
    if args.mode == "package":
        return [package]
    if args.mode == "verify":
        values = []
        if args.archive:
            values.extend(["--archive", args.archive])
        return [Step("Vérification archive", "d4_verify_release_archive.py", tuple(values))]
    if args.mode == "smoke":
        return [Step("Smoke test structure", "d5_smoke_test_release_structure.py")]
    if args.mode == "backup":
        values = []
        if args.backup_output:
            values.extend(["--output", args.backup_output])
        return [Step("Backup SQLite", "d6_backup_sqlite.py", tuple(values))]
    if args.mode == "restore":
        values = []
        if args.archive:
            values.extend(["--archive", args.archive])
        if args.yes:
            values.append("--yes")
        return [Step("Restore SQLite", "d7_restore_sqlite.py", tuple(values))]

    if args.mode == "web-plan":
        values = []
        if args.source:
            values.extend(["--source", args.source])
        if args.target_path:
            values.extend(["--target", args.target_path])
        if args.delete_obsolete:
            values.append("--delete-obsolete")
        return [Step("Plan mise à jour web", "d8_deploy_web_update.py", tuple(["plan", *values]))]
    if args.mode == "web-apply":
        values = []
        if args.source:
            values.extend(["--source", args.source])
        if args.target_path:
            values.extend(["--target", args.target_path])
        if args.delete_obsolete:
            values.append("--delete-obsolete")
        if args.yes:
            values.append("--yes")
        return [Step("Application mise à jour web", "d8_deploy_web_update.py", tuple(["apply", *values]))]
    if args.mode == "web-rollback":
        values = []
        if args.target_path:
            values.extend(["--target", args.target_path])
        if args.rollback_archive:
            values.extend(["--archive", args.rollback_archive])
        if args.yes:
            values.append("--yes")
        return [Step("Rollback mise à jour web", "d8_deploy_web_update.py", tuple(["rollback", *values]))]
    if args.mode == "migrate-plan":
        values = ["--plan"]
        if args.database:
            values.extend(["--database", args.database])
        else:
            values.append("--all")
        return [Step("Plan migrations SQLite", "d9_migrate_sqlite.py", tuple(values))]
    if args.mode == "migrate":
        values = []
        if args.database:
            values.extend(["--database", args.database])
        else:
            values.append("--all")
        if args.backup:
            values.append("--backup")
        if args.yes:
            values.append("--yes")
        return [Step("Migrations SQLite", "d9_migrate_sqlite.py", tuple(values))]
    if args.mode in {"rebase-plan", "rebase"}:
        values = []
        values.extend(["--database", args.database or "core"])
        if args.source_data:
            values.extend(["--source-data", args.source_data])
        if args.target_structure:
            values.extend(["--target-structure", args.target_structure])
        if args.output:
            values.extend(["--output", args.output])
        values.append("--plan" if args.mode == "rebase-plan" else "--apply")
        if args.overwrite:
            values.append("--overwrite")
        if args.include_technical:
            values.append("--include-technical")
        if args.copy_native:
            values.append("--copy-native")
        return [Step("Rebase SQLite données prod vers structure cible", "d10_rebase_sqlite_data.py", tuple(values))]
    if args.mode == "ftp-dry-run":
        return [Step("Simulation FTP", "d3_deploy_ftp.py", ftp_args(args, dry_run=True))]
    if args.mode == "ftp-deploy":
        return [Step("Déploiement FTP", "d3_deploy_ftp.py", ftp_args(args, dry_run=False))]

    return [prepare] if args.prepare else []


def assert_release_steps(steps: list[Step]) -> None:
    allowed_non_d = {"cms.py"}
    forbidden = [
        step.script
        for step in steps
        if not step.script.startswith("d") and step.script not in allowed_non_d
    ]
    if forbidden:
        raise RuntimeError(
            "Étapes non autorisées dans la chaîne de release : " + ", ".join(forbidden)
        )


def run_step(step: Step) -> int:
    if not step.path.exists():
        print(f"ERREUR: script introuvable: {step.path}", file=sys.stderr)
        return 2
    print(f"\n=== {step.label} ===", flush=True)
    print(f">>> {' '.join(step.command())}", flush=True)
    return subprocess.run(step.command(), cwd=str(ROOT)).returncode


def explain_failure(step: Step, code: int) -> None:
    if step.script == "cms.py" and code == 2:
        print(
            "\nChaîne interrompue sur cms.py avec le code 2 : un contrôle requis "
            "n'a pas pu être démontré. Consultez storage/qualification ou "
            "storage/audit-results.",
            file=sys.stderr,
        )
    else:
        print(
            f"\nChaîne de release interrompue sur {step.script} avec le code {code}.",
            file=sys.stderr,
        )


def main() -> int:
    args = parse_args()

    if args.mode != "release":
        steps = build_steps(args)
        assert_release_steps(steps)
        if args.plan_only:
            print("Plan de livraison d_ :")
            for step in steps:
                print(" ".join(step.command()))
            return 0
        for step in steps:
            code = run_step(step)
            if code != 0:
                explain_failure(step, code)
                return code
        print("\nOpération d_ terminée avec succès.")
        return 0

    if args.prepare:
        prepare = Step("Préparation interactive de la release", "d0_prepare_release.py", ("--signal-cancel",))
        if args.plan_only:
            print("Plan de livraison d_ :")
            print(" ".join(prepare.command()))
            print("# Les étapes suivantes dépendent du type choisi (patch/minor/major).")
            return 0
        code = run_step(prepare)
        if code == 130:
            print("\nPréparation de release annulée. Aucun audit, préflight ni package exécuté.")
            return 0
        if code != 0:
            explain_failure(prepare, code)
            return code

    steps = release_steps(args)
    assert_release_steps(steps)
    if args.plan_only:
        print("Plan de livraison d_ :")
        for step in steps:
            print(" ".join(step.command()))
        return 0

    metadata = load_release_metadata()
    if metadata.release_type in {"minor", "major"}:
        print(f"\nRelease {metadata.release_type}: audit reproductible obligatoire.")
    else:
        print("\nPatch: qualification locale standard, sans preuve reproductible automatique.")

    for step in steps:
        code = run_step(step)
        if code != 0:
            explain_failure(step, code)
            return code

    print("\nChaîne d_ terminée avec succès.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
