#!/usr/bin/env python3
"""Rebuild explicite de la recherche publique.

Le moteur de recherche est volontairement reconstruit via le projecteur public
officiel (`projections:rebuild`) afin de garder une seule source de vérité pour
les routes, snapshots, SEO et `search_documents`. Le rebuild public purge et
réécrit les documents de recherche depuis les révisions publiées ; les résultats
publics restent ensuite filtrés par site, langue, type, taxonomie et meta robots.
"""
from __future__ import annotations

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
CLEANUP = ROOT / "tools" / "python" / "operations" / "database" / "b2_cleanup_noindex_search_documents.py"


def run(command: list[str]) -> int:
    print(">>> " + " ".join(command), flush=True)
    return subprocess.run(command, cwd=str(ROOT), env=cms_subprocess_env()).returncode


def main() -> int:
    if not CONSOLE.exists():
        print(f"ERREUR: console introuvable: {CONSOLE}", file=sys.stderr)
        return 2
    try:
        php = resolve_php_binary()
    except (FileNotFoundError, PermissionError) as exc:
        print(f"ERREUR: {exc}", file=sys.stderr)
        return 2
    code = run([php, str(CONSOLE), "projections:rebuild"])
    if code != 0:
        return code
    if CLEANUP.exists():
        code = run([sys.executable, str(CLEANUP)])
        if code != 0:
            return code
    print("Search documents rebuilt through public projections. Run c21_validate_native_search.py for strict contract checks.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
