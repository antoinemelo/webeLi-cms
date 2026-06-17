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
    Action(
        "9",
        "Exécuter uniquement les tests",
        ("test",),
        "Lance uniquement les suites de tests, sans qualification complète.",
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
    print(" 9. Exécuter uniquement les tests")
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
