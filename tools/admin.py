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

import os
import shlex
import subprocess
import sys
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path
from typing import Sequence


TOOLS_DIR = Path(__file__).resolve().parent
PROJECT_ROOT = TOOLS_DIR.parent
CMS_ENTRYPOINT = TOOLS_DIR / "cms.py"


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
    print("  11 — plan fichiers/migrations, backup, chemins protégés et journal JSON")
    print()
    print("• Reconstruction explicite des bases de données")
    print("  1 — opération indépendante, destructive, réservée au développement/test/récupération")
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
    print("11. Mettre à jour une instance client depuis une release")
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


def maybe_commit_after_ftp() -> None:
    if not is_git_repository():
        print()
        print("Git non détecté dans ce répertoire : commit/push ignorés.")
        return

    print()
    print("Synchronisation Git optionnelle")
    print("-" * 31)
    print_git_status()

    if git_has_changes():
        if confirm_optional("Créer un commit Git avec les changements actuels"):
            default_message = f"Mise à jour après déploiement FTP {datetime.now().strftime('%Y-%m-%d %H:%M')}"
            try:
                message = input(f"Message de commit [{default_message}] : ").strip()
            except (EOFError, KeyboardInterrupt):
                print()
                message = ""
            message = message or default_message

            add_result = git_command("add", "-A", capture=False)
            if add_result.returncode != 0:
                print("Commit annulé : impossible d'ajouter les fichiers à l'index Git.")
                return

            commit_result = git_command("commit", "-m", message, capture=False)
            if commit_result.returncode != 0:
                print("Commit non créé. Vérifiez l'état Git avant de pousser.")
                return
        else:
            print("Commit Git ignoré.")
    else:
        print("Aucun changement local à commiter.")

    upstream = git_upstream()
    if upstream is None:
        print("Aucune branche upstream configurée : push automatique non proposé.")
        return

    ahead = git_ahead_count()
    if ahead is None:
        print("Impossible de calculer les commits en avance : push non proposé.")
        return
    if ahead <= 0:
        print(f"Aucun commit à pousser vers {upstream}.")
        return

    print(f"{ahead} commit(s) local(aux) en avance sur {upstream}.")
    if confirm_optional(f"Pousser maintenant vers {upstream}"):
        push_result = git_command("push", capture=False)
        if push_result.returncode == 0:
            print("Push Git terminé.")
        else:
            print("Push Git en échec. Relancez manuellement après correction.")
    else:
        print("Push Git ignoré.")


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


def run_instance_update() -> int:
    print()
    print("Mettre à jour une instance client")
    print("-" * 37)
    print("La cible conserve storage/database, médias, logs, backups, ops/.env, ops/modules.local.json et local/modules.")
    print()

    source = prompt_text("Release source ZIP ou dossier", "")
    if not source:
        print("Opération annulée.")
        return 0

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
        print("\nOK — instance client mise à jour")
    else:
        print(f"\nERREUR — code de retour {result.returncode}")
    return result.returncode


def run_instance_clone() -> int:
    print()
    print("Créer un clone local d'instance")
    print("-" * 34)
    print("Le dossier de destination et le APP_BASE_PATH cible sont indépendants.")
    print("Exemple: destination ../mod2 avec APP_BASE_PATH /mod.")
    print()

    source = prompt_text("Répertoire source", str(PROJECT_ROOT))
    if not source:
        print("Opération annulée.")
        return 0

    destination = prompt_text("Répertoire destination", str(default_sibling_destination()))
    if not destination:
        print("Opération annulée.")
        return 0

    target_base_path = prompt_text(
        "APP_BASE_PATH cible (vide = dérivé du dossier destination)",
        "",
    )
    public_url = prompt_text("APP_PUBLIC_BASE_URL cible optionnel", "")
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
    if target_base_path:
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
            maybe_commit_after_ftp()
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

    while True:
        print_menu()
        try:
            choice = input("Votre choix : ").strip()
        except (EOFError, KeyboardInterrupt):
            print()
            return 130

        if choice == "0":
            print("Fin de l'administration DEC CMS.")
            return 0

        if choice == "9":
            run_instance_clone()
            if not pause_before_menu():
                return 0
            continue

        if choice == "10":
            run_existing_database_update()
            if not pause_before_menu():
                return 0
            continue

        if choice == "11":
            run_instance_update()
            if not pause_before_menu():
                return 0
            continue

        if choice == "2":
            action = choose_qualification()
            if action is None:
                print()
                continue
        else:
            action = actions_by_key.get(choice)

        if action is None:
            print("\nChoix invalide.\n")
            continue

        run_action(action)

        if not pause_before_menu():
            return 0


if __name__ == "__main__":
    raise SystemExit(main())
