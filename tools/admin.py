#!/usr/bin/env python3
"""
DEC CMS — menu d'administration.

Ce menu est une interface interactive au-dessus du point d'entrée canonique
``tools/cms.py``. La qualification du projet est centralisée dans la commande :

    python tools/cms.py qualify --profile <profil>

Profils disponibles :
    quick     contrôles rapides pour le développement courant ;
    complete  qualification technique approfondie cache-aware, sans reconstruction des bases ;
    release   validation finale locale stricte ; les mineures/majeures ajoutent un audit reproductible obligatoire.

Les opérations de sauvegarde, migration incrémentale, mise à jour d’instance, export, préparation de release
et déploiement restent disponibles séparément pour les interventions
administratives explicites. La reconstruction reste un outil destructif de développement, de test ou de récupération contrôlée.
"""

from __future__ import annotations

import hashlib
import json
import os
import shlex
import subprocess
import sys
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path
from typing import Sequence
from urllib.parse import urlsplit


TOOLS_DIR = Path(__file__).resolve().parent
PROJECT_ROOT = TOOLS_DIR.parent
CMS_ENTRYPOINT = TOOLS_DIR / "cms.py"
FTP_DEPLOY_SCRIPT = TOOLS_DIR / "python" / "operations" / "deployment" / "d3_deploy_ftp.py"
RELEASE_PACKAGE_SCRIPT = TOOLS_DIR / "python" / "operations" / "deployment" / "d2_package_release.py"
DEFAULT_UPDATE_STAGE = PROJECT_ROOT / "storage" / "exports" / "release_stage"


@dataclass(frozen=True)
class Action:
    key: str
    title: str
    command: tuple[str, ...]
    purpose: str
    destructive: bool = False


ACTIONS: tuple[Action, ...] = (
    Action(
        "1",
        "Reconstruire les bases de données",
        ("rebuild",),
        "Recrée les bases SQLite natives, applique les seeds et reconstruit les projections. Réservé au développement, aux tests ou à une récupération contrôlée.",
        True,
    ),
    Action(
        "3",
        "Générer la documentation et les fichiers dérivés",
        ("docs", "generate"),
        "Régénère la documentation, OpenAPI et les types SDK.",
    ),
    Action(
        "4",
        "Vérifier la documentation générée",
        ("docs", "check"),
        "Vérifie que les fichiers générés sont synchronisés avec leurs sources.",
    ),
    Action(
        "5",
        "Préparer la release",
        ("release", "--interactive-prepare"),
        "Prépare patch, mineure ou majeure. Les patchs conservent une qualification locale cache-aware; les mineures/majeures exigent un audit reproductible et lient la release à ses preuves.",
    ),
    Action(
        "6",
        "Déployer la release par FTP",
        ("release", "--deploy", "ftp"),
        "Transfère une release déjà qualifiée vers le serveur FTP configuré.",
        True,
    ),
    Action(
        "7",
        "Créer une sauvegarde",
        ("backup",),
        "Sauvegarde les bases SQLite de l’inventaire unifié et les ressources prévues par le manifeste.",
    ),
    Action(
        "8",
        "Lancer l'export statique",
        ("export",),
        "Génère l'export statique du site.",
    ),
)


QUALIFICATION_ACTIONS: dict[str, Action] = {
    "a": Action(
        "2a",
        "Qualification rapide",
        ("qualify", "--profile", "quick"),
        "Vérifications essentielles pour le travail courant, sans contrôles lourds ni cache build/E2E.",
    ),
    "b": Action(
        "2b",
        "Qualification complète",
        ("qualify", "--profile", "complete"),
        "Qualification technique approfondie du projet, cache-aware pour le build frontend, sans reconstruction des bases ni création de release.",
    ),
    "c": Action(
        "2c",
        "Qualification de release",
        ("qualify", "--profile", "release", "--no-cache"),
        "Validation finale stricte avant livraison : force build/E2E, préflight, package, archive, installation neuve et tests navigateur.",
    ),
}


DATABASE_REBUILD_ACTIONS: dict[str, Action] = {
    "a": Action(
        "1a",
        "Reconstruire toutes les bases avec les données par défaut",
        ("rebuild",),
        "Profil historique complet : structures, seeds Core, Business et Vente, puis projections.",
        True,
    ),
    "b": Action(
        "1b",
        "Reconstruire sans données Business et Vente",
        ("rebuild", "--without-commerce-seed"),
        "Crée toutes les structures, conserve le seed Core et laisse Business/Sale sans données initiales.",
        True,
    ),
    "c": Action(
        "1c",
        "Reconstruire sans contenus Business, Vente et Core",
        ("rebuild", "--without-commerce-seed", "--without-core-seed"),
        (
            "Crée toutes les structures, laisse Business/Sale sans données et conserve "
            "uniquement le socle Core requis (site, langue, domaine, thèmes et registres techniques)."
        ),
        True,
    ),
}


def command_for(action: Action) -> list[str]:
    return [sys.executable, str(CMS_ENTRYPOINT), *action.command]


def format_command(command: Sequence[str]) -> str:
    return " ".join(shlex.quote(part) for part in command)


def ensure_cms() -> None:
    if not CMS_ENTRYPOINT.is_file():
        raise SystemExit(f"ERREUR: point d'entrée introuvable: {CMS_ENTRYPOINT}")


def print_intro() -> None:
    print()
    print("DEC CMS — administration")
    print("=" * 78)
    print()
    print("Le contrôle global du projet passe par trois profils de qualification.")
    print()
    print("Usages recommandés :")
    print()
    print("• Pendant le développement")
    print("  2a — qualification rapide")
    print()
    print("• Avant intégration ou après une modification importante")
    print("  2b — qualification complète")
    print()
    print("• Avant création ou déploiement d'une release")
    print("  5 — préparation complète")
    print("      patch : qualification locale cache-aware")
    print("      mineure/majeure : audit reproductible obligatoire + preuves")
    print("  2c — qualification release stricte seule, avec --no-cache")
    print()
    print("• Mise à jour d’une base existante avec contenu")
    print("  10 — plan non mutatif, migration incrémentale, backup et validation")
    print()
    print("• Mise à jour d’une instance client depuis une release")
    print("  11 — cible locale ou FTP, avec transfert des seuls fichiers différents")
    print()
    print("• Reconstruction explicite des bases de données")
    print("  1 — opération indépendante, destructive, réservée au développement/test/récupération")
    print()
    print("• Synchronisation du dépôt source")
    print("  12 — revue de l’état Git, commit explicite puis push optionnel")
    print()
    print("Aucun profil de qualification ne reconstruit les bases de données.")
    print()


def print_menu() -> None:
    print("Opérations disponibles")
    print("-" * 78)
    print(" 1. Reconstruire les bases de données — dev/test/récupération")
    print(" 2. Lancer une qualification (pre-release)")
    print(" 3. Générer la documentation et les fichiers dérivés")
    print(" 4. Vérifier la documentation générée")
    print(" 5. Préparer le prochain patch ou la prochaine release")
    print(" 6. Déployer la release par FTP")
    print(" 7. Créer une sauvegarde")
    print(" 8. Lancer l'export statique")
    print(" 9. Créer un clone local d'instance")
    print("10. Mettre à jour les bases existantes — backup + migrations")
    print("11. Mettre à jour une instance client locale ou FTP depuis une release")
    print("12. Créer un commit Git et/ou pousser les commits locaux")
    print(" 0. Quitter")
    print()


def print_qualification_menu() -> None:
    print()
    print("Profil de qualification")
    print("-" * 78)
    print(" a. Rapide")
    print("    Vérifications essentielles pour le travail courant.")
    print("    Retour rapide, sans contrôles lourds ni cache build/E2E.")
    print()
    print(" b. Complet")
    print("    Qualification technique approfondie du projet local.")
    print("    Inclut tests, validateurs complets, build, documentation,")
    print("    sauvegarde/restauration et export à blanc.")
    print("    Utilise le cache local pour le build frontend si les sources sont inchangées.")
    print("    Ne reconstruit jamais les bases et ne crée pas de release.")
    print()
    print(" c. Release")
    print("    Validation finale avant livraison ou déploiement.")
    print("    Force les étapes cache-aware avec --no-cache, puis ajoute préflight, package, vérification d'archive,")
    print("    installation neuve et tests navigateur Playwright.")
    print("    Ne reconstruit jamais les bases.")
    print()
    print(" 0. Retour au menu principal")
    print()


def print_database_rebuild_menu() -> None:
    print()
    print("Profil de reconstruction des bases")
    print("-" * 38)
    print(" a. Complet")
    print("    Structures et données par défaut de toutes les bases.")
    print()
    print(" b. Sans données Business et Vente")
    print("    Toutes les structures, seed Core conservé, Business/Sale vides.")
    print()
    print(" c. Sans données Business, Vente et Core")
    print("    Toutes les structures, Core/Business/Sale sans données initiales.")
    print()
    print(" 0. Retour au menu principal")
    print()


def choose_database_rebuild() -> Action | None:
    while True:
        print_database_rebuild_menu()
        try:
            choice = input("Votre choix : ").strip().lower()
        except (EOFError, KeyboardInterrupt):
            print()
            return None

        if choice == "0":
            return None

        action = DATABASE_REBUILD_ACTIONS.get(choice)
        if action is not None:
            return action

        print("\nChoix invalide. Saisissez a, b, c ou 0.\n")


def choose_qualification() -> Action | None:
    while True:
        print_qualification_menu()
        try:
            choice = input("Votre choix : ").strip().lower()
        except (EOFError, KeyboardInterrupt):
            print()
            return None

        if choice == "0":
            return None

        action = QUALIFICATION_ACTIONS.get(choice)
        if action is not None:
            return action

        print("\nChoix invalide. Saisissez a, b, c ou 0.\n")


def print_action_details(action: Action) -> None:
    print()
    print(action.title)
    print("-" * len(action.title))
    print(action.purpose)
    print()
    print("Commande exécutée :")
    print(f"  {format_command(command_for(action))}")
    if action.destructive:
        print()
        print("ATTENTION : cette opération peut modifier fondamentalement le système.")


def confirm_destructive() -> bool:
    try:
        answer = input("Tapez O pour confirmer : ").strip().upper()
    except (EOFError, KeyboardInterrupt):
        print()
        return False
    return answer == "O"


def confirm_optional(prompt: str) -> bool:
    try:
        answer = input(f"{prompt} [o/N] : ").strip().lower()
    except (EOFError, KeyboardInterrupt):
        print()
        return False
    return answer in {"o", "oui", "y", "yes"}


def git_command(*args: str, capture: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ("git", *args),
        cwd=str(PROJECT_ROOT),
        env=os.environ.copy(),
        check=False,
        text=True,
        stdout=subprocess.PIPE if capture else None,
        stderr=subprocess.PIPE if capture else None,
    )


def git_output(*args: str) -> str | None:
    result = git_command(*args)
    if result.returncode != 0:
        return None
    return result.stdout.strip()


def is_git_repository() -> bool:
    result = git_command("rev-parse", "--is-inside-work-tree")
    return result.returncode == 0 and result.stdout.strip() == "true"


def git_has_changes() -> bool:
    result = git_command("status", "--porcelain")
    return result.returncode == 0 and bool(result.stdout.strip())


def git_upstream() -> str | None:
    return git_output("rev-parse", "--abbrev-ref", "--symbolic-full-name", "@{u}")


def git_ahead_count() -> int | None:
    result = git_command("rev-list", "--count", "@{u}..HEAD")
    if result.returncode != 0:
        return None
    try:
        return int(result.stdout.strip())
    except ValueError:
        return None


def print_git_status() -> None:
    result = git_command("status", "--short", "--branch")
    if result.returncode == 0 and result.stdout.strip():
        print(result.stdout.rstrip())


def git_current_branch() -> str | None:
    branch = git_output("branch", "--show-current")
    return branch or None


def git_remotes() -> list[str]:
    output = git_output("remote")
    return [remote.strip() for remote in (output or "").splitlines() if remote.strip()]


def run_git_sync() -> int:
    if not is_git_repository():
        print()
        print("Git non détecté dans ce répertoire : commit/push impossibles.")
        return 0

    print()
    print("Commit et synchronisation Git")
    print("-" * 29)
    print_git_status()

    if git_has_changes():
        print()
        print("Les changements ci-dessus seront tous indexés avec git add -A.")
        if confirm_optional("Indexer tous ces changements et créer un commit Git"):
            default_message = f"Mise à jour DEC CMS {datetime.now().strftime('%Y-%m-%d %H:%M')}"
            message = prompt_text("Message de commit", default_message)
            if not message:
                print("Commit Git annulé : aucun message fourni.")
                return 0

            add_result = git_command("add", "-A", capture=False)
            if add_result.returncode != 0:
                print("Commit annulé : impossible d'ajouter les fichiers à l'index Git.")
                return add_result.returncode or 1

            commit_result = git_command("commit", "-m", message, capture=False)
            if commit_result.returncode != 0:
                print("Commit non créé. Vérifiez l'état Git avant de pousser.")
                return commit_result.returncode or 1
            print("Commit Git créé.")
        else:
            print("Commit Git ignoré. Les changements non commités ne seront pas poussés.")
    else:
        print("Aucun changement local à commiter.")

    upstream = git_upstream()
    if upstream is None:
        branch = git_current_branch()
        remotes = git_remotes()
        remote = "origin" if "origin" in remotes else (remotes[0] if len(remotes) == 1 else None)
        if not branch:
            print("Branche Git détachée ou introuvable : push non proposé.")
            return 0
        if remote is None:
            print("Aucun remote Git non ambigu disponible : push non proposé.")
            return 0
        if not confirm_optional(f"Aucune upstream configurée. Publier {branch} vers {remote}/{branch}"):
            print("Push Git ignoré.")
            return 0
        push_result = git_command("push", "--set-upstream", remote, branch, capture=False)
        if push_result.returncode == 0:
            print("Push Git terminé et upstream configurée.")
            return 0
        print("Push Git en échec. Vérifiez le remote et vos droits d'accès.")
        return push_result.returncode or 1

    ahead = git_ahead_count()
    if ahead is None:
        print("Impossible de calculer les commits en avance : push non proposé.")
        return 0
    if ahead <= 0:
        print(f"Aucun commit à pousser vers {upstream}.")
        return 0

    print(f"{ahead} commit(s) local(aux) en avance sur {upstream}.")
    if confirm_optional(f"Pousser maintenant vers {upstream}"):
        push_result = git_command("push", capture=False)
        if push_result.returncode == 0:
            print("Push Git terminé.")
            return 0
        else:
            print("Push Git en échec. Relancez manuellement après correction.")
            return push_result.returncode or 1
    else:
        print("Push Git ignoré.")
    return 0


def prompt_text(label: str, default: str = "") -> str:
    suffix = f" [{default}]" if default else ""
    try:
        value = input(f"{label}{suffix} : ").strip()
    except (EOFError, KeyboardInterrupt):
        print()
        return ""
    return value or default


def default_sibling_destination() -> Path:
    return PROJECT_ROOT.parent / f"{PROJECT_ROOT.name}2"


def read_instance_env(source: Path) -> dict[str, str]:
    env_path = source.expanduser().resolve() / "ops" / ".env"
    if not env_path.is_file():
        return {}
    values: dict[str, str] = {}
    for raw_line in env_path.read_text(encoding="utf-8", errors="replace").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip().strip("\"'")
    return values


def suggested_clone_public_url(source: Path, target_base_path: str) -> str:
    values = read_instance_env(source)
    source_public_url = values.get("APP_PUBLIC_BASE_URL", "").strip()
    parsed = urlsplit(source_public_url)
    if not parsed.scheme or not parsed.netloc:
        return ""
    normalized_path = "/" + target_base_path.strip("/")
    if normalized_path == "/":
        normalized_path = ""
    return f"{parsed.scheme}://{parsed.netloc}{normalized_path}"


def named_cms_vendor_candidate(instance_root: Path, source_root: Path) -> Path:
    for root in (instance_root, *instance_root.parents, source_root, *source_root.parents):
        if root.name == "cms":
            return root / "vendor"
    return instance_root.parent / "cms" / "vendor"


CLONE_VENDOR_CANDIDATE_LABELS = (
    "backend de l’instance",
    "racine de l’instance",
    "dossier parent",
    "cms partagé conventionnel",
    "deux niveaux au-dessus",
)


def clone_vendor_candidates(destination: Path, source: Path = PROJECT_ROOT) -> tuple[Path, Path, Path, Path, Path]:
    instance_root = destination.expanduser()
    if not instance_root.is_absolute():
        instance_root = PROJECT_ROOT / instance_root
    instance_root = instance_root.resolve()
    source_root = source.expanduser()
    if not source_root.is_absolute():
        source_root = PROJECT_ROOT / source_root
    source_root = source_root.resolve()
    return (
        instance_root / "backend" / "vendor",
        instance_root / "vendor",
        instance_root.parent / "vendor",
        named_cms_vendor_candidate(instance_root, source_root),
        instance_root.parent.parent / "vendor",
    )


def run_existing_database_update() -> int:
    print()
    print("Mettre à jour les bases existantes")
    print("-" * 39)
    print("Cette procédure conserve les données : plan non mutatif, migration incrémentale, backup et validation.")
    print("Elle amène les bases connues à la dernière version de schéma disponible dans ce code.")
    print()

    plan_command = [sys.executable, str(CMS_ENTRYPOINT), "migrate", "--plan"]
    print("Plan exécuté :")
    print(f"  {format_command(plan_command)}")
    plan = subprocess.run(plan_command, cwd=str(PROJECT_ROOT), env=os.environ.copy(), check=False)
    if plan.returncode != 0:
        print(f"\nPlan de migrations en échec — code de retour {plan.returncode}")
        return plan.returncode

    if not confirm_destructive():
        print("Mise à jour des bases annulée après le plan.")
        return 0

    apply_command = [sys.executable, str(CMS_ENTRYPOINT), "migrate", "--apply", "--backup", "--yes"]
    print()
    print("Application exécutée :")
    print(f"  {format_command(apply_command)}")
    apply = subprocess.run(apply_command, cwd=str(PROJECT_ROOT), env=os.environ.copy(), check=False)
    if apply.returncode != 0:
        print(f"\nERREUR — migration interrompue avec le code {apply.returncode}")
        return apply.returncode

    validation_commands = [
        [sys.executable, str(CMS_ENTRYPOINT), "validate", "--category", "database"],
        [sys.executable, str(CMS_ENTRYPOINT), "validate", "--category", "operations"],
    ]
    for command in validation_commands:
        print()
        print("Validation exécutée :")
        print(f"  {format_command(command)}")
        result = subprocess.run(command, cwd=str(PROJECT_ROOT), env=os.environ.copy(), check=False)
        if result.returncode != 0:
            print(f"\nERREUR — validation en échec avec le code {result.returncode}")
            return result.returncode

    print("\nOK — bases existantes mises à jour et validées")
    return 0


def normalized_ftp_remote_root(value: str) -> str:
    raw = value.strip()
    parsed = urlsplit(raw)
    if not parsed.scheme:
        return raw
    if parsed.scheme.lower() not in {"ftp", "ftps"}:
        raise ValueError("Le chemin distant doit être un chemin FTP ou une URL ftp:// / ftps://.")
    return parsed.path or "/"


def ftp_update_manifest_path(config_path: str, remote_root: str) -> Path:
    config = Path(config_path).expanduser()
    if not config.is_absolute():
        config = PROJECT_ROOT / config
    identity = f"{config.resolve()}|{remote_root}".encode("utf-8")
    fingerprint = hashlib.sha256(identity).hexdigest()[:12]
    stem = "".join(character if character.isalnum() or character in "-_" else "-" for character in config.stem)
    return PROJECT_ROOT / "storage" / "deployments" / "ftp-manifests" / f"{stem or 'ftp'}-{fingerprint}.json"


def ftp_update_plan_path(manifest_path: Path) -> Path:
    return manifest_path.with_name(f"{manifest_path.stem}-plan.json")


def read_ftp_update_plan(path: Path) -> dict | None:
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return None
    return payload if isinstance(payload, dict) else None


def ftp_plan_operation_count(plan: dict) -> int:
    delta = plan.get("delta") if isinstance(plan.get("delta"), dict) else {}
    return sum(len(delta.get(key, [])) for key in ("new", "changed", "removed") if isinstance(delta.get(key), list))


def refresh_default_update_stage(source: str) -> int:
    """Reconstruit le staging par défaut depuis /dev avant une mise à jour.

    Une source ZIP ou un autre dossier reste volontairement immuable: elle
    représente une release choisie explicitement. Le staging par défaut, lui,
    doit toujours refléter les fichiers courants du dépôt au moment du point 11.
    Les bases et dépendances restent exclues, car l'instance les protège.
    """
    source_path = Path(source).expanduser()
    if not source_path.is_absolute():
        source_path = PROJECT_ROOT / source_path
    if source_path.resolve() != DEFAULT_UPDATE_STAGE.resolve():
        return 0

    command = [
        sys.executable,
        str(RELEASE_PACKAGE_SCRIPT),
        "--stage-dir",
        str(DEFAULT_UPDATE_STAGE),
        "--exclude-databases",
        "--no-zip",
        "--skip-generated-artifacts-refresh",
    ]
    print()
    print("Actualisation de la release différentielle depuis /dev :")
    print(f"  {format_command(command)}")
    result = subprocess.run(command, cwd=str(PROJECT_ROOT), env=os.environ.copy(), check=False)
    if result.returncode != 0:
        print(f"\nPréparation de la release en échec — code de retour {result.returncode}")
        return result.returncode
    print("Stage différentiel frais prêt (bases et vendor exclus).")
    return 0


def run_local_instance_update(source: str) -> int:
    target = prompt_text("Répertoire cible de l'instance", str(PROJECT_ROOT))
    if not target:
        print("Opération annulée.")
        return 0

    delete_obsolete = confirm_optional("Supprimer les fichiers obsolètes hors chemins protégés")
    maintenance_flag = confirm_optional("Créer storage/maintenance.flag pendant la copie fichiers")

    base_command = [
        sys.executable,
        str(CMS_ENTRYPOINT),
        "instance",
        "update",
        "--source",
        source,
        "--target",
        target,
    ]
    if delete_obsolete:
        base_command.append("--delete-obsolete")
    if maintenance_flag:
        base_command.append("--maintenance-flag")

    plan_command = [*base_command, "--plan"]
    print()
    print("Plan exécuté :")
    print(f"  {format_command(plan_command)}")
    plan = subprocess.run(plan_command, cwd=str(PROJECT_ROOT), env=os.environ.copy(), check=False)
    if plan.returncode != 0:
        print(f"\nPlan de mise à jour en échec — code de retour {plan.returncode}")
        return plan.returncode

    if not confirm_destructive():
        print("Mise à jour d’instance annulée après le plan.")
        return 0

    apply_command = [*base_command, "--apply", "--backup", "--yes"]
    print()
    print("Application exécutée :")
    print(f"  {format_command(apply_command)}")
    result = subprocess.run(apply_command, cwd=str(PROJECT_ROOT), env=os.environ.copy(), check=False)
    if result.returncode == 0:
        print("\nOK — instance locale mise à jour")
    else:
        print(f"\nERREUR — code de retour {result.returncode}")
    return result.returncode


def run_ftp_instance_update(source: str) -> int:
    config = prompt_text("Configuration FTP", str(PROJECT_ROOT / "ops" / "ftp.deploy.json"))
    if not config:
        print("Opération annulée.")
        return 0
    remote_value = prompt_text("Chemin distant exact (ou URL ftp://)", "")
    if not remote_value:
        print("Opération annulée.")
        return 0
    try:
        remote_root = normalized_ftp_remote_root(remote_value)
    except ValueError as exc:
        print(f"ERREUR: {exc}")
        return 2
    manifest = ftp_update_manifest_path(config, remote_root)
    plan_path = ftp_update_plan_path(manifest)
    delete_obsolete = confirm_optional("Supprimer sur le serveur les fichiers retirés de la nouvelle release")

    base_command = [
        sys.executable,
        str(FTP_DEPLOY_SCRIPT),
        "--config",
        config,
        "--stage-dir",
        source,
        "--remote-root",
        remote_root,
        "--manifest",
        str(manifest),
        "--protect-instance-data",
    ]
    if not delete_obsolete:
        base_command.append("--no-delete-removed")

    plan_command = [*base_command, "--dry-run", "--plan-output", str(plan_path)]
    print()
    print("Simulation FTP exécutée :")
    print(f"  {format_command(plan_command)}")
    plan = subprocess.run(plan_command, cwd=str(PROJECT_ROOT), env=os.environ.copy(), check=False)
    if plan.returncode != 0:
        print(f"\nSimulation FTP en échec — code de retour {plan.returncode}")
        return plan.returncode

    locked_plan = read_ftp_update_plan(plan_path)
    if locked_plan is None:
        print(f"ERREUR: la simulation n'a pas produit de plan FTP lisible: {plan_path}")
        return 2

    print(f"Manifeste différentiel propre à cette cible : {manifest}")
    print(f"Plan FTP verrouillé : {plan_path}")
    print("Les bases, médias, secrets, logs, backups, dépendances et modules locaux sont protégés.")
    print("Le transfert FTP met à jour les fichiers ; les migrations éventuelles restent à exécuter sur le serveur.")
    if ftp_plan_operation_count(locked_plan) == 0:
        print("Aucune différence applicative à transférer : l'instance correspond déjà à ce stage.")
        return 0
    if not confirm_destructive():
        print("Mise à jour FTP annulée après la simulation.")
        return 0

    print()
    print("Transfert FTP exécuté :")
    apply_command = [*base_command, "--apply-plan", str(plan_path)]
    print(f"  {format_command(apply_command)}")
    result = subprocess.run(apply_command, cwd=str(PROJECT_ROOT), env=os.environ.copy(), check=False)
    if result.returncode == 0:
        print("\nOK — fichiers différents transférés vers l’instance FTP")
    else:
        print(f"\nERREUR — code de retour {result.returncode}")
    return result.returncode


def run_instance_update() -> int:
    print()
    print("Mettre à jour une instance client")
    print("-" * 37)
    print("La source doit être une release préparée au point 5 (dossier release_stage ou archive ZIP).")
    print("Seuls les fichiers dont le contenu diffère sont transférés.")
    print()

    source = prompt_text("Release source ZIP ou dossier", str(DEFAULT_UPDATE_STAGE))
    if not source:
        print("Opération annulée.")
        return 0

    refresh_code = refresh_default_update_stage(source)
    if refresh_code != 0:
        return refresh_code

    target_kind = prompt_text("Type de cible : local (l) ou FTP/FTPS (f)", "l").lower()
    if target_kind in {"l", "local"}:
        return run_local_instance_update(source)
    if target_kind in {"f", "ftp", "ftps"}:
        return run_ftp_instance_update(source)
    print("ERREUR: type de cible inconnu. Utilisez l ou f.")
    return 2


def run_instance_clone() -> int:
    print()
    print("Créer un clone local d'instance")
    print("-" * 34)
    print("Le dossier de destination et le APP_BASE_PATH cible sont indépendants.")
    print("Saisissez le chemin public final exact, indépendamment du nom du dossier.")
    print("Exemples: /cms/main, /new/main ou /cms2.")
    print()

    source = prompt_text("Répertoire source", str(PROJECT_ROOT))
    if not source:
        print("Opération annulée.")
        return 0

    destination = prompt_text("Répertoire destination", str(default_sibling_destination()))
    if not destination:
        print("Opération annulée.")
        return 0

    print("Vendor recherché automatiquement par la future instance :")
    candidates = clone_vendor_candidates(Path(destination), Path(source))
    for index, (label, candidate) in enumerate(zip(CLONE_VENDOR_CANDIDATE_LABELS, candidates), start=1):
        print(f"  {index}. {label} : {candidate}")

    source_values = read_instance_env(Path(source))
    source_base_path = source_values.get("APP_BASE_PATH", "<non configuré>")
    print(f"APP_BASE_PATH source détecté : {source_base_path}")
    target_base_path = prompt_text("APP_BASE_PATH cible exact (obligatoire)", "")
    if not target_base_path:
        print("Opération annulée : APP_BASE_PATH cible obligatoire.")
        return 0
    if not target_base_path.startswith("/") or "//" in target_base_path:
        print("ERREUR: APP_BASE_PATH doit commencer par '/' et ne pas contenir '//'.")
        return 2
    target_base_path = "/" + target_base_path.strip("/")
    if target_base_path == "/":
        target_base_path = "/"

    suggested_public_url = suggested_clone_public_url(Path(source), target_base_path)
    public_url = prompt_text(
        "APP_PUBLIC_BASE_URL cible",
        suggested_public_url,
    )
    force = confirm_optional("Remplacer la destination si elle existe")

    command = [
        sys.executable,
        str(CMS_ENTRYPOINT),
        "instance",
        "clone",
        "--source",
        source,
        "--destination",
        destination,
    ]
    command.extend(["--new-base-path", target_base_path])
    if public_url:
        command.extend(["--new-public-base-url", public_url])
    if force:
        command.append("--force")

    dry_run_command = [*command[:2], "--dry-run", *command[2:]]
    print()
    print("Simulation exécutée :")
    print(f"  {format_command(dry_run_command)}")
    dry_run = subprocess.run(dry_run_command, cwd=str(PROJECT_ROOT), env=os.environ.copy(), check=False)
    if dry_run.returncode != 0:
        print(f"\nSimulation en échec — code de retour {dry_run.returncode}")
        return dry_run.returncode

    if not confirm_destructive():
        print("Clone local annulé après simulation.")
        return 0

    print()
    print("Commande exécutée :")
    print(f"  {format_command(command)}")
    result = subprocess.run(command, cwd=str(PROJECT_ROOT), env=os.environ.copy(), check=False)
    if result.returncode == 0:
        print("\nOK — clone local d'instance créé")
    else:
        print(f"\nERREUR — code de retour {result.returncode}")
    return result.returncode


def run_action(action: Action) -> int:
    print_action_details(action)

    if action.destructive and not confirm_destructive():
        print("Opération annulée.")
        return 0

    ensure_cms()
    print()
    result = subprocess.run(
        command_for(action),
        cwd=str(PROJECT_ROOT),
        env=os.environ.copy(),
        check=False,
    )

    if result.returncode == 0:
        print(f"\nOK — {action.title}")
        if action.key == "6":
            git_result = run_git_sync()
            if git_result != 0:
                return git_result
    elif result.returncode == 2:
        print(
            "\nINCOMPLET — certains contrôles n'ont pas pu être exécutés. "
            "Consultez le rapport de qualification."
        )
    else:
        print(f"\nERREUR — code de retour {result.returncode}")
    return result.returncode


def pause_before_menu() -> bool:
    print()
    try:
        input("Appuyez sur Entrée pour revenir au menu...")
    except (EOFError, KeyboardInterrupt):
        print()
        return False
    print()
    return True


def main() -> int:
    ensure_cms()
    print_intro()

    actions_by_key = {action.key: action for action in ACTIONS}
    last_returncode = 0

    while True:
        print_menu()
        try:
            choice = input("Votre choix : ").strip()
        except (EOFError, KeyboardInterrupt):
            print()
            return 130

        if choice == "0":
            print("Fin de l'administration DEC CMS.")
            return last_returncode

        if choice == "9":
            last_returncode = run_instance_clone()
            if not pause_before_menu():
                return last_returncode
            continue

        if choice == "10":
            last_returncode = run_existing_database_update()
            if not pause_before_menu():
                return last_returncode
            continue

        if choice == "11":
            last_returncode = run_instance_update()
            if not pause_before_menu():
                return last_returncode
            continue

        if choice == "12":
            last_returncode = run_git_sync()
            if not pause_before_menu():
                return last_returncode
            continue

        if choice == "1":
            action = choose_database_rebuild()
            if action is None:
                print()
                continue
        elif choice == "2":
            action = choose_qualification()
            if action is None:
                print()
                continue
        else:
            action = actions_by_key.get(choice)

        if action is None:
            print("\nChoix invalide.\n")
            continue

        last_returncode = run_action(action)

        if not pause_before_menu():
            return last_returncode


if __name__ == "__main__":
    raise SystemExit(main())
