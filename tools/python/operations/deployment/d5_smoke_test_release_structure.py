#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
REQUIRED_PATHS = [
    "backend/public/index.php",
    "backend/routes/web.php",
    "backend/routes/admin.php",
    "backend/routes/api.php",
    "backend/src/Core/App.php",
    "tools/cms.py",
    "tools/python/commands/smoke.py",
    "admin-app/.vite/manifest.json",
    "storage/.htaccess",
    "storage/media/.htaccess",
    "storage/database/.htaccess",
    "storage/logs/.htaccess",
    "storage/backups/.htaccess",
    "storage/exports/.gitkeep",
    "storage/exports/.htaccess",
    "storage/exports/static/.gitkeep",
    "storage/exports/static/.htaccess",
    "storage/database/core.sqlite",
    "storage/database/iam.sqlite",
    "storage/database/forms.sqlite",
    "storage/database/cookies.sqlite",
    "storage/database/ai.sqlite",
    "ops/_env.example",
    "docs/installation/install-release.md",
    "docs/reference/generated/cli-commands.md",
    "docs/operations/deployment.md",
]
WRITABLE_DIRS = [
    "storage/database",
    "storage/cache",
    "storage/cache/twig",
    "storage/uploads",
    "storage/logs",
    "storage/exports",
    "storage/exports/static",
    "storage/backups/sqlite",
]
RELEASE_VERIFIED_COMMANDS = [
    "python3 tools/cms.py smoke",
    "python3 tools/cms.py validate",
    "python3 tools/cms.py docs check",
]


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Smoke test structurel non destructif d'une release DEC CMS décompressée.")
    parser.add_argument("--root", default=str(ROOT), help="Racine du projet/release décompressée.")
    parser.add_argument("--json", action="store_true", help="Sortie JSON.")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    root = Path(args.root).expanduser()
    if not root.is_absolute():
        root = ROOT / root
    errors: list[str] = []
    warnings: list[str] = []
    for rel in REQUIRED_PATHS:
        if not (root / rel).exists():
            errors.append(f"Chemin requis absent: {rel}")
    for rel in WRITABLE_DIRS:
        path = root / rel
        path.mkdir(parents=True, exist_ok=True)
        probe = path / ".smoke-write-test"
        try:
            probe.write_text("ok", encoding="utf-8")
            probe.unlink()
        except OSError:
            errors.append(f"Répertoire non inscriptible: {rel}")
    exports_htaccess = root / "storage" / "exports" / ".htaccess"
    if exports_htaccess.exists():
        source = exports_htaccess.read_text(encoding="utf-8", errors="ignore")
        if "Require all denied" not in source and "Deny from all" not in source:
            errors.append("storage/exports/.htaccess ne bloque pas les exports runtime.")
    static_exports_htaccess = root / "storage" / "exports" / "static" / ".htaccess"
    if static_exports_htaccess.exists():
        source = static_exports_htaccess.read_text(encoding="utf-8", errors="ignore")
        if "Require all denied" not in source and "Deny from all" not in source:
            errors.append("storage/exports/static/.htaccess ne bloque pas les exports statiques générés.")
    public_htaccess = root / "storage" / "media" / ".htaccess"
    if public_htaccess.exists():
        source = public_htaccess.read_text(encoding="utf-8", errors="ignore")
        if "FilesMatch" not in source or "quarantine" not in source:
            errors.append("storage/media/.htaccess ne bloque pas clairement scripts/quarantaine.")
    release_manifest = root / "storage" / "deployments" / "release-manifest.json"
    if release_manifest.exists():
        try:
            data = json.loads(release_manifest.read_text(encoding="utf-8"))
        except json.JSONDecodeError as exc:
            errors.append(f"release-manifest.json invalide: {exc}")
        else:
            if data.get("include_databases") is not True:
                errors.append("release-manifest.json doit déclarer include_databases=true pour une release standard.")
            verified_commands = data.get("verified_commands")
            commands_documented = isinstance(verified_commands, list) and all(
                command in verified_commands for command in RELEASE_VERIFIED_COMMANDS
            )
            notes = data.get("notes")
            notes_documented = isinstance(notes, list) and any("smoke, validate et docs check" in str(note) for note in notes)
            if not commands_documented and not notes_documented:
                warnings.append("release-manifest.json ne documente pas les commandes release autonomes.")
    if args.json:
        print(json.dumps({"ok": not errors, "errors": errors, "warnings": warnings, "root": str(root)}, ensure_ascii=False, indent=2))
    else:
        print("[smoke release structure]")
        for warning in warnings:
            print(f"WARN {warning}")
        for error in errors:
            print(f"ERROR {error}", file=sys.stderr)
        if not errors:
            print(f"OK Structure installable: {root}")
        print(f"Résumé: {len(warnings)} avertissement(s), {len(errors)} erreur(s)")
    return 0 if not errors else 1


if __name__ == "__main__":
    raise SystemExit(main())
