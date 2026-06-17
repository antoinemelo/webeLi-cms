from __future__ import annotations
import json, re, sqlite3
from pathlib import Path
from .model import ValidationReport

ROOT = Path(__file__).resolve().parents[3]

def require_paths(report: ValidationReport, paths: list[str], code: str = "CFG-001") -> None:
    for rel in paths:
        report.checked()
        if not (ROOT / rel).exists(): report.add(code, "Ressource obligatoire absente", path=rel)

def forbid_globs(report: ValidationReport, patterns: list[str], code: str = "CFG-002") -> None:
    for pattern in patterns:
        for path in ROOT.glob(pattern):
            report.checked(); report.add(code, "Artefact interdit présent", path=path.relative_to(ROOT).as_posix())

def load_json(report: ValidationReport, rel: str, code: str) -> object | None:
    report.checked(); path=ROOT/rel
    if not path.is_file(): report.add(code,"Fichier JSON absent",path=rel); return None
    try: return json.loads(path.read_text(encoding="utf-8"))
    except Exception as exc: report.add(code,"JSON invalide",path=rel,error=str(exc)); return None

def require_tokens(report: ValidationReport, rel: str, tokens: list[str], code: str) -> None:
    report.checked(); path=ROOT/rel
    if not path.is_file(): report.add(code,"Fichier absent",path=rel); return
    text=path.read_text(encoding="utf-8",errors="ignore")
    for token in tokens:
        report.checked()
        if token not in text: report.add(code,"Contrat structurel absent",path=rel,token=token)

def sql_compiles(report: ValidationReport, rel: str, code: str="DB-002") -> None:
    report.checked(); path=ROOT/rel
    if not path.is_file(): report.add("DB-001","Schéma SQL absent",path=rel); return
    try:
        db=sqlite3.connect(":memory:")
        db.executescript(path.read_text(encoding="utf-8")); db.execute("PRAGMA foreign_key_check").fetchall(); db.close()
    except Exception as exc: report.add(code,"Schéma SQL non exécutable depuis zéro",path=rel,error=str(exc))

def php_route_entries(rel: str) -> list[tuple[str,str,str]]:
    text=(ROOT/rel).read_text(encoding="utf-8",errors="ignore")
    return re.findall(r"\['(GET|POST|PUT|PATCH|DELETE)',\s*'([^']+)',\s*'([^']+)'\]",text)

def local_markdown_links(report: ValidationReport, roots: list[str]) -> None:
    pattern=re.compile(r"\[[^\]]+\]\(([^)]+)\)")
    for start in roots:
        for path in (ROOT/start).rglob("*.md") if (ROOT/start).exists() else []:
            text=path.read_text(encoding="utf-8",errors="ignore")
            for raw in pattern.findall(text):
                target=raw.split('#',1)[0].strip()
                if not target or target.startswith(("http://","https://","mailto:","#")): continue
                report.checked(); resolved=(path.parent/target).resolve()
                if not resolved.exists(): report.add("DOC-002","Lien local cassé",severity="warning",path=path.relative_to(ROOT).as_posix(),target=target)
