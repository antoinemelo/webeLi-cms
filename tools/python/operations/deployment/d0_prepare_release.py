#!/usr/bin/env python3
from __future__ import annotations
import sys as _dec_sys
from pathlib import Path as _DecPath
_DEC_CMS_PROJECT_ROOT = next(parent for parent in _DecPath(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
if str(_DEC_CMS_PROJECT_ROOT) not in _dec_sys.path:
    _dec_sys.path.insert(0, str(_DEC_CMS_PROJECT_ROOT))

import argparse

from tools.python.lib.release_metadata import (
    load_release_metadata,
    next_major,
    next_minor,
    next_patch_letter,
    save_release_metadata,
    validate_release_metadata,
)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Prépare config/release.json en mode interactif ou CI non interactif.")
    parser.add_argument("--bump", choices=["rename", "patch", "minor", "major", "none"], default=None, help="Mode non interactif. none valide et réécrit seulement technical_version si nécessaire.")
    parser.add_argument("--release-name", default="", help="Nom lisible à appliquer en mode non interactif.")
    parser.add_argument("--product-name", default="", help="Nom commercial à appliquer en mode non interactif.")
    parser.add_argument("--product-code", default="", help="Code technique à appliquer en mode non interactif.")
    parser.add_argument("--package-root", default="", help="Dossier racine de package à appliquer en mode non interactif.")
    parser.add_argument("--yes", action="store_true", help="Écrit sans confirmation en mode non interactif.")
    parser.add_argument("--signal-cancel", action="store_true", help=argparse.SUPPRESS)
    return parser.parse_args()


def apply_non_interactive(args: argparse.Namespace) -> int:
    metadata = load_release_metadata()
    print_current(metadata)

    if args.product_name:
        metadata.product_name = args.product_name.strip()
    if args.product_code:
        metadata.product_code = args.product_code.strip()
    if args.package_root:
        metadata.package_root = args.package_root.strip()
    if args.release_name:
        metadata.release_name = args.release_name.strip()

    if args.bump == "patch":
        metadata.release_type = "patch"
        metadata.patch = next_patch_letter(metadata.patch)
    elif args.bump == "minor":
        metadata.release_type = "minor"
        metadata.minor = next_minor(metadata.minor)
        metadata.patch = "a"
    elif args.bump == "major":
        metadata.release_type = "major"
        metadata.major = next_major(metadata.major)
        metadata.minor = "e01"
        metadata.patch = "a"
    elif args.bump == "rename":
        metadata.release_type = "rename"
    elif args.bump == "none":
        pass

    metadata.refresh_technical_version()
    try:
        validate_release_metadata(metadata)
    except ValueError as exc:
        print(f"\nERREUR: {exc}")
        return 2

    print_summary(metadata)
    if not args.yes:
        print("Mode non interactif sans --yes: aucune modification écrite.")
        return 0

    save_release_metadata(metadata)
    print("config/release.json mis à jour.")
    return 0


def ask(prompt: str, default: str = "") -> str:
    suffix = f" [{default}]" if default else ""
    value = input(f"{prompt}{suffix}: ").strip()
    return value if value else default


def ask_non_empty(prompt: str, default: str = "") -> str:
    while True:
        value = ask(prompt, default).strip()
        if value:
            return value
        print("Valeur obligatoire.")


def confirm() -> bool:
    value = input("Confirmer l'écriture de config/release.json ? [o/N]: ").strip().lower()
    return value in {"o", "oui", "y", "yes"}


def print_current(metadata) -> None:
    print("\nRelease courante")
    print(f"- Nom commercial : {metadata.product_name}")
    print(f"- Nom de code    : {metadata.product_code}")
    print(f"- Dossier package: {metadata.package_root}/")
    print(f"- Version        : {metadata.technical_version}")
    print(f"- Type           : {metadata.release_type}")
    print(f"- Nom lisible    : {metadata.release_name}")


def print_summary(metadata) -> None:
    metadata.refresh_technical_version()
    print("\nNouvelle configuration")
    print(f"- Nom commercial : {metadata.product_name}")
    print(f"- Nom de code    : {metadata.product_code}")
    print(f"- Dossier package: {metadata.package_root}/")
    print(f"- Version        : {metadata.technical_version}")
    print(f"- Type           : {metadata.release_type}")
    print(f"- Nom lisible    : {metadata.release_name}")


def main() -> int:
    args = parse_args()
    if args.bump is not None:
        return apply_non_interactive(args)

    metadata = load_release_metadata()
    print_current(metadata)

    print("\nStandardisation  : CodeProduit_ReleaseMajeure#_ReleaseMineure#Patch")
    print("\nÀ partir de là, que souhaitez-vous faire ?")
    print("a. Renommer seulement la release courante")
    print("b. Préparer le prochain patch")
    print("c. Préparer la prochaine release mineure")
    print("d. Préparer la prochaine release majeure")
    print("e. Modifier les paramètres du produit")
    print("0. Annuler")

    choice = ask("Votre choix", "0").lower()

    if choice == "0":
        print("Annulé.")
        return 130 if args.signal_cancel else 0

    if choice == "a":
        metadata.release_type = "rename"
        metadata.release_name = ask_non_empty("Nouveau nom lisible", metadata.release_name)

    elif choice == "b":
        metadata.release_type = "patch"
        metadata.patch = next_patch_letter(metadata.patch)
        metadata.release_name = ask_non_empty("Nom lisible du patch", metadata.release_name)

    elif choice == "c":
        metadata.release_type = "minor"
        metadata.minor = next_minor(metadata.minor)
        metadata.patch = "a"
        metadata.release_name = ask_non_empty("Nom lisible de la release mineure", metadata.release_name)

    elif choice == "d":
        metadata.release_type = "major"
        metadata.major = next_major(metadata.major)
        metadata.minor = "e01"
        metadata.patch = "a"
        metadata.release_name = ask_non_empty("Nom lisible de la release majeure", metadata.release_name)

    elif choice == "e":
        metadata.product_name = ask_non_empty("Nom commercial", metadata.product_name)
        metadata.product_code = ask_non_empty("Nom de code technique", metadata.product_code)
        metadata.package_root = ask_non_empty("Dossier racine du package", metadata.package_root)
        metadata.release_name = ask_non_empty("Nom lisible de la release", metadata.release_name)

    else:
        print("Choix inconnu.")
        return 2

    metadata.refresh_technical_version()
    try:
        validate_release_metadata(metadata)
    except ValueError as exc:
        print(f"\nERREUR: {exc}")
        return 2

    print_summary(metadata)
    if not confirm():
        print("Aucune modification écrite.")
        return 130 if args.signal_cancel else 0

    save_release_metadata(metadata)
    print("config/release.json mis à jour.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
