#!/usr/bin/env python3
"""Capture a deterministic, secret-free CMS baseline snapshot."""
from __future__ import annotations
import argparse, hashlib, json, re, sqlite3
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[3]
DB_DIR = ROOT / 'storage' / 'database'
VOLATILE_COLUMNS = {'created_at','updated_at','published_at','last_login_at','last_used_at','expires_at','started_at','finished_at','requested_at','processed_at'}
SENSITIVE_RX = re.compile(r'(password|secret|token|credential|private|api[_-]?key)', re.I)
ROUTE_RX = re.compile(r"['\"](GET|POST|PUT|PATCH|DELETE|OPTIONS)['\"]\s*,?\s*(?:=>|,)?\s*['\"](/[^'\"]+)['\"]|['\"]path['\"]\s*=>\s*['\"](/[^'\"]+)['\"]")


def canonical(value: Any) -> Any:
    if isinstance(value, dict): return {k: canonical(value[k]) for k in sorted(value)}
    if isinstance(value, list): return [canonical(v) for v in value]
    return value

def sha(value: Any) -> str:
    data=json.dumps(canonical(value),ensure_ascii=False,separators=(',',':')).encode()
    return hashlib.sha256(data).hexdigest()

def table_schema(con: sqlite3.Connection, name: str) -> dict[str, Any]:
    cols=[dict(r) for r in con.execute(f'PRAGMA table_info("{name}")')]
    fks=[dict(r) for r in con.execute(f'PRAGMA foreign_key_list("{name}")')]
    idx=[]
    for row in con.execute(f'PRAGMA index_list("{name}")'):
        d=dict(row); d['columns']=[dict(x) for x in con.execute(f'PRAGMA index_info("{d["name"]}")')]; idx.append(d)
    return {'columns':cols,'foreign_keys':fks,'indexes':idx}

def safe_rows(con: sqlite3.Connection, table: str, limit: int=500) -> list[dict[str,Any]]:
    cols=[r['name'] for r in con.execute(f'PRAGMA table_info("{table}")')]
    selected=[c for c in cols if c not in VOLATILE_COLUMNS and not SENSITIVE_RX.search(c)]
    if not selected: return []
    order='id' if 'id' in cols else selected[0]
    q='SELECT '+','.join('"'+c+'"' for c in selected)+f' FROM "{table}" ORDER BY "{order}" LIMIT ?'
    out=[]
    for row in con.execute(q,(limit,)):
        d={k:row[k] for k in row.keys()}
        for k,v in list(d.items()):
            if isinstance(v,bytes): d[k]=hashlib.sha256(v).hexdigest()
            elif isinstance(v,str) and len(v)>2000: d[k]={'sha256':hashlib.sha256(v.encode()).hexdigest(),'length':len(v)}
        out.append(d)
    return out

def capture_db(path: Path) -> dict[str,Any]:
    con=sqlite3.connect(path); con.row_factory=sqlite3.Row
    try:
        objects=[]
        for r in con.execute("SELECT type,name,tbl_name,sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type,name"):
            d=dict(r); d['sql']=' '.join((d.get('sql') or '').split()); objects.append(d)
        tables={r['name']:table_schema(con,r['name']) for r in con.execute("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")}
        focus=['blueprints','blueprint_versions','blueprint_sections','blueprint_fields','blueprint_fieldsets','fieldsets','content_types','fields','field_groups','routes','modules','module_routes','module_permissions','revisions','public_content_snapshots','content_entry_publications','iam_roles','iam_permissions','iam_role_permissions']
        data={t:safe_rows(con,t) for t in focus if t in tables}
        return {'file':path.name,'objects':objects,'tables':tables,'focus_data':data,'schema_checksum':sha(objects),'focus_checksum':sha(data)}
    finally: con.close()

def capture_routes() -> list[dict[str,str]]:
    found=set()
    for p in sorted((ROOT/'backend').rglob('*.php')):
        text=p.read_text(encoding='utf-8',errors='ignore')
        for m in ROUTE_RX.finditer(text):
            method=m.group(1) or 'UNKNOWN'; path=m.group(2) or m.group(3)
            if path and any(x in path for x in ['/admin','/api','/preview','/export','/sitemap','/robots']): found.add((method,path,str(p.relative_to(ROOT))))
    return [{'method':a,'path':b,'source':c} for a,b,c in sorted(found)]

def capture_files(paths:list[Path]) -> list[dict[str,Any]]:
    out=[]
    for base in paths:
        if not base.exists(): continue
        for p in sorted(base.rglob('*')):
            if p.is_file() and p.suffix.lower() in {'.twig','.json','.yaml','.yml','.ts','.vue','.php','.html'}:
                data=p.read_bytes(); out.append({'path':str(p.relative_to(ROOT)),'sha256':hashlib.sha256(data).hexdigest(),'size':len(data)})
    return out

def main()->int:
    ap=argparse.ArgumentParser(); ap.add_argument('--output',type=Path,default=ROOT/'storage'/'baseline'/'current'); args=ap.parse_args()
    out=args.output.resolve(); out.mkdir(parents=True,exist_ok=True)
    dbs=[capture_db(p) for p in sorted(DB_DIR.glob('*.sqlite'))]
    snapshot={'format_version':1,'databases':dbs,'routes':capture_routes(),'contracts_and_views':capture_files([ROOT/'backend'/'src',ROOT/'frontend',ROOT/'admin-app']),'static_exports':capture_files([ROOT/'storage'/'exports'/'static'])}
    snapshot['snapshot_checksum']=sha(snapshot)
    (out/'baseline.json').write_text(json.dumps(canonical(snapshot),ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
    summary=['# CMS baseline snapshot','',f"Checksum: `{snapshot['snapshot_checksum']}`",'',f"Databases: {len(dbs)}",f"Routes detected: {len(snapshot['routes'])}",f"Tracked contract/view files: {len(snapshot['contracts_and_views'])}",'','Generated data excludes secret-looking and volatile columns.']
    (out/'README.md').write_text('\n'.join(summary)+'\n',encoding='utf-8')
    print(out/'baseline.json'); return 0
if __name__=='__main__': raise SystemExit(main())
