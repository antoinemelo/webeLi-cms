#!/usr/bin/env python3
"""Generate the exhaustive DEC CMS Python tool manifest."""
from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
PYTHON_DIR = ROOT / "tools" / "python"
TEXT_SUFFIXES = {".py", ".md", ".json", ".yml", ".yaml", ".php", ".js", ".ts", ".vue", ".sh", ".txt"}
EXCLUDED_PARTS = {".git", ".venv", "node_modules", "vendor", "storage", "admin-app", "__pycache__"}
DESTRUCTIVE_MARKERS = ("delete", "reset", "restore", "migrate", "rebase", "deploy", "cleanup", "init", "rebuild")
ARCHIVE_DATE = "2026-06-13"


def category(rel: str) -> str:
    parts = Path(rel).parts
    if rel == "tools/cms.py": return "cli"
    if rel.startswith("tools/tests/"): return "load-test"
    if "archive" in parts: return "archive"
    if "tests" in parts: return "test"
    if "validators" in parts: return "validator"
    if "generators" in parts: return "generator"
    if "commands" in parts: return "command"
    if "cms" in parts: return "cli-framework"
    if "lib" in parts: return "library"
    if "baseline" in parts: return "diagnostic-baseline"
    if "diagnostics" in parts: return "diagnostic"
    if "operations" in parts:
        try:
            index = parts.index("operations")
            return "operation-" + parts[index + 1]
        except (ValueError, IndexError):
            return "operation"
    return "internal"


def replacement_for(cat: str, name: str) -> str | None:
    if cat == "cli": return None
    if cat == "load-test": return f"python3 tools/tests/{name} --help"
    if cat == "validator": return "python3 tools/cms.py validate"
    if cat == "test": return "python3 tools/cms.py test"
    if cat == "generator": return "python3 tools/cms.py docs"
    if cat == "operation-database": return "python3 tools/cms.py init|rebuild"
    if cat == "operation-deployment": return "python3 tools/cms.py release"
    if cat == "operation-backup": return "python3 tools/cms.py backup"
    if cat == "archive" and name == "c_validate.py": return "python3 tools/cms.py validate --full"
    if cat == "archive": return "python3 tools/cms.py <commande>"
    if cat in {"command", "cli-framework"}: return "python3 tools/cms.py <commande>"
    return None


def searchable_files() -> list[Path]:
    files: list[Path] = []
    for path in ROOT.rglob("*"):
        if not path.is_file() or path.suffix.lower() not in TEXT_SUFFIXES: continue
        if any(part in EXCLUDED_PARTS for part in path.parts): continue
        try:
            if path.stat().st_size >= 2_000_000: continue
        except OSError:
            continue
        files.append(path)
    return files


def manifest_python_files() -> list[Path]:
    return [
        path
        for path in sorted(ROOT.glob("tools/**/*.py"))
        if not any(part in EXCLUDED_PARTS for part in path.parts)
    ]


def incoming_refs(path: Path, files: list[Path]) -> list[str]:
    rel = path.relative_to(ROOT).as_posix()
    module = rel[:-3].replace("/", ".") if rel.endswith(".py") else ""
    needles = {path.name, rel, module}
    found: list[str] = []
    for other in files:
        if other == path: continue
        try: text = other.read_text(encoding="utf-8", errors="ignore")
        except OSError: continue
        if any(needle and needle in text for needle in needles):
            found.append(other.relative_to(ROOT).as_posix())
    return sorted(set(found))


def main() -> int:
    pyfiles = manifest_python_files()
    files = searchable_files()
    rows = []
    for path in pyfiles:
        rel = path.relative_to(ROOT).as_posix()
        cat = category(rel)
        public = rel == "tools/cms.py"
        archived = cat == "archive"
        try: text = path.read_text(encoding="utf-8", errors="ignore")
        except OSError: text = ""
        destructive = any(marker in path.stem.lower() for marker in DESTRUCTIVE_MARKERS) or bool(
            re.search(r"\b(unlink|rmtree|DROP TABLE|DELETE FROM|--apply)\b", text, re.IGNORECASE)
        )
        rows.append({
            "path": rel,
            "category": cat,
            "public": public,
            "entrypoint": public,
            "called_by": incoming_refs(path, files),
            "replacement": replacement_for(cat, path.name),
            "status": "archived" if archived else "active",
            "destructive": destructive,
            "archived_at": ARCHIVE_DATE if archived else None,
            "reason": (
                "Point d’entrée public unique" if public else
                "Traçabilité historique uniquement; utilisation en production interdite" if archived else
                "Module interne appelé par la CLI, un test, un générateur ou le registre"
            ),
        })
    output = PYTHON_DIR / "tool-manifest.json"
    output.write_text(json.dumps({
        "schema_version": 2,
        "generated_by": "tools/python/generators/generate_tool_manifest.py",
        "public_cli": "python3 tools/cms.py",
        "tools": rows,
    }, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"{len(rows)} outils inventoriés dans {output.relative_to(ROOT)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
