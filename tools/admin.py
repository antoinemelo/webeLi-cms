#!/usr/bin/env python3
"""
DEC CMS — menu d'administration.

Ce menu est une interface interactive au-dessus du point d'entrée canonique
``tools/cms.py``. La qualification du projet est centralisée dans la commande :

    python tools/cms.py qualify --profile <profil>

Profils disponibles :
    quick     contrôles rapides pour le développement courant ;
    complete  qualification technique approfondie sans reconstruction des bases ;
    release   validation finale locale ; les mineures/majeures ajoutent un audit reproductible obligatoire.

Les opérations de reconstruction, sauvegarde, export, préparation de release
et déploiement restent disponibles séparément pour les interventions
administratives explicites.
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
        "Recrée les cinq bases SQLite, applique les seeds et reconstruit les projections.",
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
        "Prépare patch, mineure ou majeure. Les mineures/majeures exigent un audit reproductible et lient la release à ses preuves; les patchs conservent la qualification standard.",
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
        "Sauvegarde les bases SQLite natives et les ressources prévues par le manifeste.",
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
        "Vérifications essentielles pour le travail courant, sans contrôles lourds.",
    ),
    "b": Action(
        "2b",
        "Qualification complète",
        ("qualify", "--profile", "complete"),
        "Qualification technique approfondie du projet, sans reconstruction des bases ni création de release.",
    ),
    "c": Action(
        "2c",
        "Qualification de release",
        ("qualify", "--profile", "release"),
        "Validation finale avant livraison : préflight, package, archive, installation neuve et tests navigateur.",
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
    print("      patch : qualification standard")
    print("      mineure/majeure : audit reproductible obligatoire + preuves")
    print()
    print("• Reconstruction explicite des bases de données")
    print("  1 — opération indépendante et destructive")
    print()
    print("Aucun profil de qualification ne reconstruit les bases de données.")
    print()


def print_menu() -> None:
    print("Opérations disponibles")
    print("-" * 78)
    print(" 1. Reconstruire les bases de données")
    print(" 2. Lancer une qualification (pre-release)")
    print(" 3. Générer la documentation et les fichiers dérivés")
    print(" 4. Vérifier la documentation générée")
    print(" 5. Préparer le prochain patch ou la prochaine release")
    print(" 6. Déployer la release par FTP")
    print(" 7. Créer une sauvegarde")
    print(" 8. Lancer l'export statique")
    print(" 9. Créer un clone local d'instance")
    print(" 0. Quitter")
    print()


def print_qualification_menu() -> None:
    print()
    print("Profil de qualification")
    print("-" * 78)
    print(" a. Rapide")
    print("    Vérifications essentielles pour le travail courant.")
    print("    Retour rapide, sans contrôles lourds.")
    print()
    print(" b. Complet")
    print("    Qualification technique approfondie du projet local.")
    print("    Inclut tests, validateurs complets, build, documentation,")
    print("    sauvegarde/restauration et export à blanc.")
    print("    Ne reconstruit jamais les bases et ne crée pas de release.")
    print()
    print(" c. Release")
    print("    Validation finale avant livraison ou déploiement.")
    print("    Ajoute préflight, package, vérification d'archive,")
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
