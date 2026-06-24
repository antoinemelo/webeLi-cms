#!/usr/bin/env python3
"""Generate deterministic TREE manifests for DEC CMS source and release trees."""
from __future__ import annotations

import argparse
import fnmatch
import json
import sys
from pathlib import Path
from typing import Iterable

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from tools.python.lib.database_inventory import native_database_names
from tools.python.lib.deploylib import dependency_exclude_patterns, should_exclude
from tools.python.operations.deployment.d2_package_release import DEFAULT_EXCLUDES, KEEP_FILES

SOURCE_EXCLUDES = [
    ".git",
    ".git/*",
    ".DS_Store",
    "Thumbs.db",
    ".venv",
    ".venv/*",
    ".venv/**",
    "venv",
    "venv/*",
    "venv/**",
    "**/.venv",
    "**/.venv/*",
    "**/.venv/**",
    "**/venv",
    "**/venv/*",
    "**/venv/**",
    "__pycache__",
    "__pycache__/*",
    "**/__pycache__",
    "**/__pycache__/*",
    "**/__pycache__/**",
    ".pytest_cache",
    ".pytest_cache/*",
    ".pytest_cache/**",
    "**/.pytest_cache",
    "**/.pytest_cache/*",
    "**/.pytest_cache/**",
    ".mypy_cache",
    ".mypy_cache/*",
    ".mypy_cache/**",
    "**/.mypy_cache",
    "**/.mypy_cache/*",
    "**/.mypy_cache/**",
    ".ruff_cache",
    ".ruff_cache/*",
    ".ruff_cache/**",
    "**/.ruff_cache",
    "**/.ruff_cache/*",
    "**/.ruff_cache/**",
    "node_modules",
    "node_modules/*",
    "node_modules/**",
    "**/node_modules",
    "**/node_modules/*",
    "**/node_modules/**",
    "vendor",
    "vendor/*",
    "vendor/**",
    "backend/vendor",
    "backend/vendor/*",
    "backend/vendor/**",
    "storage",
    "storage/*",
    "storage/**",
    "*.sqlite-wal",
    "**/*.sqlite-wal",
    "*.sqlite-shm",
    "**/*.sqlite-shm",
    "*.pyc",
    "**/*.pyc",
    "*.tmp",
    "**/*.tmp",
    "*.bak",
    "**/*.bak",
    "*.backup",
    "**/*.backup",
    "*.tar",
    "**/*.tar",
    "*.tgz",
    "**/*.tgz",
    "*.tar.gz",
    "**/*.tar.gz",
    "*.zip",
    "**/*.zip",
]


def _root_from(value: str | None) -> Path:
    if not value:
        return ROOT
    path = Path(value).expanduser()
    if not path.is_absolute():
        path = ROOT / path
    return path.resolve()


def _match_any(rel: str, patterns: Iterable[str]) -> bool:
    return any(fnmatch.fnmatch(rel, pattern) for pattern in patterns)


def _source_files(root: Path) -> list[str]:
    files: list[str] = []
    effective_excludes = list(SOURCE_EXCLUDES) + dependency_exclude_patterns(root)
    for path in root.rglob("*"):
        if not path.is_file():
            continue
        rel = path.relative_to(root).as_posix()
        if _match_any(rel, effective_excludes):
            continue
        files.append(rel)
    return sorted(files)


def _release_files(root: Path, include_databases: bool) -> list[str]:
    files: set[str] = set()
    effective_excludes = list(DEFAULT_EXCLUDES) + dependency_exclude_patterns(root)
    for path in root.rglob("*"):
        if not path.is_file():
            continue
        rel = path.relative_to(root).as_posix()
        if should_exclude(rel, effective_excludes) and rel not in KEEP_FILES:
            continue
        files.add(rel)

    if include_databases:
        for name in native_database_names(release=True):
            rel = f"storage/database/{name}"
            if (root / rel).is_file():
                files.add(rel)

    return sorted(files)


def default_output(mode: str) -> Path:
    return ROOT / ("TREE.release.txt" if mode == "release" else "TREE.txt")


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Génère un manifeste TREE déterministe.")
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--source", action="store_true", help="Manifeste de l’arbre source: exclut storage/, caches, dépendances et archives locales.")
    mode.add_argument("--release", action="store_true", help="Manifeste de l’arbre installable: applique les règles de packaging release.")
    parser.add_argument("--root", default="", help="Racine à scanner. Défaut: racine du projet courant.")
    parser.add_argument("--output", default="", help="Fichier de sortie. Défaut: TREE.txt en mode source, TREE.release.txt en mode release.")
    parser.add_argument("--stdout", action="store_true", help="Écrit le manifeste sur stdout au lieu de modifier un fichier.")
    parser.add_argument("--check", action="store_true", help="Compare le fichier de sortie attendu sans le modifier.")
    parser.add_argument("--exclude-databases", action="store_true", help="En mode release, n’ajoute pas storage/database/*.sqlite au manifeste.")
    parser.add_argument("--json", action="store_true", help="Sortie JSON synthétique.")
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    mode = "release" if args.release else "source"
    root = _root_from(args.root)
    if not root.is_dir():
        raise SystemExit(f"Racine introuvable: {root}")

    entries = _release_files(root, include_databases=not args.exclude_databases) if mode == "release" else _source_files(root)
    content = "\n".join(entries) + "\n"
    output = Path(args.output).expanduser() if args.output else default_output(mode)
    if not output.is_absolute():
        output = root / output

    changed = False
    if args.stdout:
        print(content, end="")
    elif args.check:
        if not output.exists() or output.read_text(encoding="utf-8") != content:
            changed = True
    else:
        output.parent.mkdir(parents=True, exist_ok=True)
        old = output.read_text(encoding="utf-8") if output.exists() else None
        changed = old != content
        output.write_text(content, encoding="utf-8")

    result = {
        "ok": not (args.check and changed),
        "mode": mode,
        "root": str(root),
        "output": str(output),
        "entries": len(entries),
        "changed": changed,
    }
    if args.json:
        print(json.dumps(result, ensure_ascii=False, indent=2))
    elif not args.stdout:
        if args.check:
            print(("TREE à jour" if not changed else "TREE obsolète") + f" ({mode}, {len(entries)} fichier(s)): {output}")
        else:
            state = "mis à jour" if changed else "déjà à jour"
            print(f"TREE {state} ({mode}, {len(entries)} fichier(s)): {output}")
    return 1 if args.check and changed else 0


if __name__ == "__main__":
    raise SystemExit(main())
