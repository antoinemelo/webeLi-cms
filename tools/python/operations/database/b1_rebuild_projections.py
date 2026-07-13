#!/usr/bin/env python3
"""Maintenance globale des projections publiques.

Ce script n'est pas dans le chemin critique d'une publication.
Depuis la stratégie SEO-first atomique, une publication unitaire est valide
uniquement si PHP écrit routes + seo_metadata + snapshots et, pour les contenus
indexables, search_documents dans la même transaction que content_entry_publications.

Ce script reste utile pour une maintenance explicite : reconstruction globale,
réindexation locale, audit SEO. Il délègue à la console PHP officielle, qui passe par PublishedProjectionPipeline.
"""
from __future__ import annotations

import argparse
import subprocess
import sys
from pathlib import Path

PROJECT_ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
if str(PROJECT_ROOT) not in sys.path:
    sys.path.insert(0, str(PROJECT_ROOT))

from tools.python.lib.processes import cms_subprocess_env
from tools.python.cms.runtime import resolve_php_binary

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
CONSOLE = ROOT / "backend" / "bin" / "console"


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Maintenance globale des projections. Ne pas utiliser comme étape post-publication."
    )
    parser.add_argument(
        "--command",
        default="projections:rebuild",
        choices=["projections:rebuild", "search:reindex", "seo:audit"],
        help="Commande PHP à appeler. Par défaut: projections:rebuild.",
    )
    parser.add_argument(
        "--no-capture",
        action="store_true",
        help="Laisse la commande PHP écrire directement dans le terminal.",
    )
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    try:
        php = resolve_php_binary()
    except (FileNotFoundError, PermissionError) as exc:
        print(f"ERREUR: {exc}", file=sys.stderr)
        return 2

    if not CONSOLE.exists():
        print(f"ERREUR: console PHP introuvable: {CONSOLE}", file=sys.stderr)
        return 2

    command = [php, str(CONSOLE), args.command]
    print("Maintenance globale via PHP:", " ".join(command))
    print("Rappel: la publication unitaire reconstruit déjà ses projections critiques atomiquement.")

    if args.no_capture:
        return subprocess.run(command, cwd=str(ROOT), env=cms_subprocess_env()).returncode

    proc = subprocess.run(command, cwd=str(ROOT), env=cms_subprocess_env(), text=True, capture_output=True)
    if proc.stdout:
        print(proc.stdout, end="")
    if proc.stderr:
        print(proc.stderr, end="", file=sys.stderr)
    if proc.returncode == 0:
        print("Contrat: search_documents ne contient que les publications indexables; les pages noindex restent hors index interne.")
    return proc.returncode


if __name__ == "__main__":
    raise SystemExit(main())
