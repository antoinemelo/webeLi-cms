#!/usr/bin/env python3
"""Generate deterministic technical references from the current DEC CMS tree."""
from __future__ import annotations

import argparse
import json
import re
import sqlite3
import sys
from pathlib import Path

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))
OUT = ROOT / "docs" / "reference" / "generated"
ROUTE_RE = re.compile(r"\['(GET|POST|PUT|PATCH|DELETE)',\s*'([^']+)'\s*,")
PERM_RE = re.compile(r"['\"]([a-z][a-z0-9_.-]+\.(?:read|write|create|update|delete|publish|approve|manage|run|export|restore|admin))['\"]")
ENV_RE = re.compile(r"(?:getenv|env)\(\s*['\"]([A-Z][A-Z0-9_]+)['\"]")
GENERATED_SCAN_EXCLUDED_PARTS = {
    ".git",
    ".venv",
    "__pycache__",
    "node_modules",
    "vendor",
}


def is_generated_scan_excluded(path: Path) -> bool:
    return any(part in GENERATED_SCAN_EXCLUDED_PARTS for part in path.relative_to(ROOT).parts)


def release_metadata() -> dict:
    path = ROOT / "config" / "release.json"
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {"technical_version": "unknown"}


RELEASE = release_metadata()
VERSION = str(RELEASE.get("technical_version", "unknown"))


def header(name: str) -> str:
    return (
        "---\n"
        f"title: {name}\n"
        "audience:\n  - developer\n  - installer\n  - evaluator\n"
        "status: stable\nversion: 1.0\n"
        "source_of_truth: generated\n"
        "generator: tools/python/generators/generate_documentation.py\n"
        "owners:\n  - core\n"
        "document_type: reference\n"
        "generated: true\n"
        "---\n"
        f"# {name}\n\n"
        "> Fichier généré. Ne pas modifier directement.\n\n"
    )


def routes() -> list[tuple[str, str, str]]:
    rows: list[tuple[str, str, str]] = []
    files = list((ROOT / "backend/routes").glob("*.php")) + list((ROOT / "backend/src/Modules").rglob("*Provider.php"))
    for path in files:
        text = path.read_text(encoding="utf-8", errors="ignore")
        for match in ROUTE_RE.finditer(text):
            rows.append((match.group(1), match.group(2), path.relative_to(ROOT).as_posix()))
    return sorted(set(rows))


def duplicate_route_declarations(rows: list[tuple[str, str, str]]) -> list[dict]:
    declarations: dict[tuple[str, str], set[str]] = {}
    for method, path, source in rows:
        declarations.setdefault((method, path), set()).add(source)
    return [
        {"method": method, "path": path, "sources": sorted(sources)}
        for (method, path), sources in sorted(declarations.items())
        if len(sources) > 1
    ]


def render_index() -> str:
    items = [
        ("cli-commands.md", "Commandes CLI"),
        ("routes.md", "Routes runtime et endpoints API"),
        ("permissions.md", "Permissions détectées"),
        ("configuration.md", "Variables de configuration"),
        ("database-schema.md", "Schémas des bases SQLite"),
        ("field-types.md", "Types de champs"),
        ("modules.md", "Modules runtime"),
        ("native-blueprints.md", "Blueprints natifs"),
        ("schema-versions.md", "Versions de schéma"),
        ("validators.md", "Registre des validateurs"),
        ("release-contents.md", "Règles de contenu des releases"),
    ]
    return header("Références générées") + "".join(f"- [{label}]({path})\n" for path, label in items)


def render_routes() -> str:
    body = header("Routes runtime et endpoints API") + "| Méthode | Chemin | Surface | Déclaration |\n|---|---|---|---|\n"
    for method, path, source in routes():
        surface = "API publique" if path.startswith("/api/v1") else ("API administrative" if "/api/" in path or path.startswith("/admin") else "HTML/runtime")
        body += f"| `{method}` | `{path}` | {surface} | `{source}` |\n"
    return body


def render_permissions() -> str:
    found: set[str] = set()
    for base in (ROOT / "backend", ROOT / "database", ROOT / "frontend"):
        if not base.exists():
            continue
        for path in base.rglob("*"):
            if is_generated_scan_excluded(path):
                continue
            if path.is_file() and path.suffix.lower() in {".php", ".sql", ".ts", ".vue", ".json"}:
                found.update(PERM_RE.findall(path.read_text(encoding="utf-8", errors="ignore")))
    return header("Permissions détectées") + "| Permission |\n|---|\n" + "".join(f"| `{name}` |\n" for name in sorted(found))


def render_configuration() -> str:
    found: dict[str, set[str]] = {}
    for base in (ROOT / "backend", ROOT / "config", ROOT / "tools"):
        if not base.exists():
            continue
        for path in base.rglob("*"):
            if is_generated_scan_excluded(path):
                continue
            if not path.is_file() or path.suffix.lower() not in {".php", ".py", ".json", ".yml", ".yaml", ".env", ".example"}:
                continue
            rel = path.relative_to(ROOT).as_posix()
            text = path.read_text(encoding="utf-8", errors="ignore")
            for name in ENV_RE.findall(text):
                found.setdefault(name, set()).add(rel)
    body = header("Variables de configuration") + "| Variable | Références dans le dépôt |\n|---|---|\n"
    if not found:
        body += "| — | Aucune variable d’environnement détectée par le générateur. |\n"
    for name, sources in sorted(found.items()):
        body += f"| `{name}` | {', '.join(f'`{s}`' for s in sorted(sources))} |\n"
    body += "\nLes secrets et valeurs propres à un environnement ne sont jamais inclus dans cette page.\n"
    return body


def render_db() -> str:
    body = header("Schémas des bases SQLite")
    for db in sorted((ROOT / "storage/database").glob("*.sqlite")):
        body += f"## `{db.name}`\n\n| Table | Colonnes |\n|---|---|\n"
        try:
            with sqlite3.connect(db) as con:
                tables = [row[0] for row in con.execute("select name from sqlite_master where type='table' and name not like 'sqlite_%' order by name")]
                for table in tables:
                    cols = ", ".join(row[1] for row in con.execute(f'pragma table_info("{table}")'))
                    body += f"| `{table}` | {cols} |\n"
        except sqlite3.Error as exc:
            body += f"> Lecture impossible : {exc}\n"
        body += "\n"
    return body


def render_field_types() -> str:
    names: set[str] = set()
    for path in (ROOT / "backend").rglob("*.php"):
        text = path.read_text(encoding="utf-8", errors="ignore")
        names.update(re.findall(r"['\"](text|textarea|richtext|number|integer|decimal|boolean|date|datetime|select|multiselect|media|relation|slug|url|email|json|blocks)['\"]", text))
    return header("Types de champs") + "| Type détecté |\n|---|\n" + "".join(f"| `{name}` |\n" for name in sorted(names))


def command_help(*command: str) -> str:
    from argparse import _SubParsersAction
    from tools.python.cms import cli as cms_cli

    current = cms_cli.parser()
    for part in command:
        subparsers = next(
            (action for action in current._actions if isinstance(action, _SubParsersAction)),
            None,
        )
        if subparsers is None or part not in subparsers.choices:
            raise RuntimeError(f"Sous-commande CLI introuvable pour la documentation: {' '.join(command)}")
        current = subparsers.choices[part]
    return current.format_help().strip()


def render_cli() -> str:
    body = header("Commandes CLI")
    body += "## Aide générale\n\n```text\n" + command_help() + "\n```\n\n"
    topics: tuple[tuple[str, tuple[str, ...]], ...] = (
        ("init", ("init",)),
        ("rebuild", ("rebuild",)),
        ("validate", ("validate",)),
        ("qualify", ("qualify",)),
        ("audit", ("audit",)),
        ("test", ("test",)),
        ("export", ("export",)),
        ("backup", ("backup",)),
        ("migrate", ("migrate",)),
        ("instance", ("instance",)),
        ("instance clone", ("instance", "clone")),
        ("instance update", ("instance", "update")),
        ("release", ("release",)),
        ("docs", ("docs",)),
    )
    for label, args in topics:
        body += f"## `tools/cms.py {label}`\n\n```text\n{command_help(*args)}\n```\n\n"
    return body


def render_modules() -> str:
    manifest_paths = sorted((ROOT / "backend/src/Modules").glob("*/module.json"))
    manifest_paths += sorted((ROOT / "local/modules").glob("*/module.json"))
    manifest_paths += sorted((ROOT / "examples/modules").glob("*/module.json"))
    body = header("Modules runtime")
    body += "| Module | Type | Version | Activé par défaut | Base(s) | Manifeste |\n|---|---|---|---:|---|---|\n"
    for path in manifest_paths:
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            continue
        if not isinstance(data, dict):
            continue
        key = str(data.get("key") or path.parent.name)
        module_type = str(data.get("type") or "unknown")
        version = str(data.get("version") or "")
        enabled = "oui" if data.get("enabled_by_default") is True else "non"
        databases = data.get("databases", [])
        db_keys = []
        if isinstance(databases, list):
            for item in databases:
                if isinstance(item, dict) and item.get("key"):
                    db_keys.append(f"`{item['key']}`")
        rel = path.relative_to(ROOT).as_posix()
        body += f"| `{key}` | `{module_type}` | `{version}` | {enabled} | {', '.join(db_keys) or '—'} | `{rel}` |\n"
    body += "\nLes exemples sous `examples/modules/` documentent le contrat mais ne sont pas chargés automatiquement. Les modules clients actifs doivent être copiés ou développés sous `local/modules/` puis déclarés dans `ops/modules.local.json`.\n"
    return body


def render_native_blueprints() -> str:
    path = ROOT / "database/seeds/native_blueprints.json"
    data = json.loads(path.read_text(encoding="utf-8")) if path.exists() else []
    items = data if isinstance(data, list) else data.get("blueprints", [])
    body = header("Blueprints natifs") + "| Identifiant | Libellé |\n|---|---|\n"
    for item in sorted(items, key=lambda row: str(row.get("key") or row.get("handle") or row.get("id"))):
        body += f"| `{item.get('key') or item.get('handle') or item.get('id', '')}` | {item.get('name') or item.get('label') or ''} |\n"
    return body


def render_versions() -> str:
    body = header("Versions de schéma") + "| Source | Version |\n|---|---|\n"
    for path in sorted((ROOT / "database/migrations").rglob("*.sql")):
        body += f"| `{path.relative_to(ROOT).as_posix()}` | `{path.stem}` |\n"
    return body


def render_validators() -> str:
    from tools.python.validation.registry import VALIDATORS
    body = header("Registre des validateurs") + "| Identifiant | Domaine | Modes | Module |\n|---|---|---|---|\n"
    for validator in VALIDATORS:
        body += f"| `{validator.name}` | `{validator.domain}` | {', '.join(f'`{m}`' for m in validator.modes)} | `{validator.module}` |\n"
    return body


def render_release_contents() -> str:
    from tools.python.operations.deployment.d2_package_release import DEFAULT_EXCLUDES, KEEP_FILES, REQUIRED_SQLITE_DATABASES
    body = header("Règles de contenu des releases")
    body += "## Bases SQLite attendues\n\n" + "".join(f"- `{name}`\n" for name in REQUIRED_SQLITE_DATABASES)
    body += "\n## Fichiers de garde conservés\n\n" + "".join(f"- `{path}`\n" for path in sorted(KEEP_FILES))
    body += "\n## Exclusions du package\n\n" + "".join(f"- `{pattern}`\n" for pattern in DEFAULT_EXCLUDES)
    body += "\nLe contenu exact d’une archive produite doit être vérifié par la chaîne de release ; cette page décrit les règles du packager, pas le résultat d’un build particulier.\n"
    return body


FILES = {
    "README.md": render_index,
    "routes.md": render_routes,
    "permissions.md": render_permissions,
    "configuration.md": render_configuration,
    "database-schema.md": render_db,
    "field-types.md": render_field_types,
    "cli-commands.md": render_cli,
    "modules.md": render_modules,
    "native-blueprints.md": render_native_blueprints,
    "schema-versions.md": render_versions,
    "validators.md": render_validators,
    "release-contents.md": render_release_contents,
}


def main(argv=None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true")
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args(argv)
    mismatches: list[str] = []
    OUT.mkdir(parents=True, exist_ok=True)
    for name, renderer in FILES.items():
        expected = renderer()
        path = OUT / name
        if args.check:
            if not path.exists() or path.read_text(encoding="utf-8") != expected:
                mismatches.append(name)
        else:
            path.write_text(expected, encoding="utf-8")
    duplicates = duplicate_route_declarations(routes())
    result = {"ok": not mismatches and not duplicates, "generated": sorted(FILES), "outdated": mismatches, "duplicate_routes": duplicates}
    if args.json:
        print(json.dumps(result, ensure_ascii=False, indent=2))
    else:
        if mismatches:
            print("Documentation générée obsolète: " + ", ".join(mismatches), file=sys.stderr)
        for duplicate in duplicates:
            print(f"Route déclarée plusieurs fois: {duplicate['method']} {duplicate['path']} ({', '.join(duplicate['sources'])})", file=sys.stderr)
        if not mismatches and not duplicates:
            print("Documentation générée à jour" if args.check else "Documentation générée")
    return 0 if result["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
