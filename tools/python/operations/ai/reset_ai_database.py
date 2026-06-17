#!/usr/bin/env python3
"""Réinitialise explicitement la base SQLite dédiée au module IA."""
from __future__ import annotations

import argparse
import subprocess
import sys
from pathlib import Path

BASE = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
AI_DB = BASE / "storage" / "database" / "ai.sqlite"
CREATE_SCRIPT = BASE / "tools" / "python" / "operations" / "ai" / "create_ai_database.py"


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Supprime puis recrée storage/database/ai.sqlite.")
    parser.add_argument("--path", type=Path, default=AI_DB, help="Chemin de la base IA à réinitialiser.")
    parser.add_argument("--schema-only", action="store_true", help="Recrée uniquement le schéma, sans seed minimal.")
    parser.add_argument("--yes", action="store_true", help="Confirmation obligatoire pour supprimer la base existante.")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    if not args.yes:
        print("ERREUR: réinitialisation destructive. Relancez avec --yes.", file=sys.stderr)
        return 2

    command = [sys.executable, str(CREATE_SCRIPT), "--path", str(args.path), "--overwrite"]
    if args.schema_only:
        command.append("--schema-only")
    return subprocess.run(command, cwd=str(BASE)).returncode


if __name__ == "__main__":
    raise SystemExit(main())
