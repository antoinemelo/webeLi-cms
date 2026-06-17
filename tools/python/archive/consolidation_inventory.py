#!/usr/bin/env python3
from __future__ import annotations
import ast, json, re, sqlite3, subprocess, sys
from collections import Counter, defaultdict
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
OUT=ROOT/'storage/logs/consolidation-baseline.json'
TEXT_EXT={'.php','.py','.md','.json','.yaml','.yml','.js','.ts','.vue','.twig','.sql','.txt','.sh'}
SKIP={'vendor','node_modules','.git','storage/logs','storage/cache','storage/exports'}

def files(exts=None):
 for p in ROOT.rglob('*'):
  if not p.is_file(): continue
  rel=p.relative_to(ROOT).as_posix()
  if any(rel==s or rel.startswith(s+'/') for s in SKIP): continue
  if exts is None or p.suffix in exts: yield p

def text(p):
 try:return p.read_text('utf-8',errors='replace')
 except:return ''

def rel(p):return p.relative_to(ROOT).as_posix()

def sqlite_inventory():
 out={}
 for p in sorted((ROOT/'storage/database').glob('*.sqlite')):
  con=sqlite3.connect(p)
  objs=con.execute("select name,type,sql from sqlite_master where type in ('table','view','trigger','index') and name not like 'sqlite_%' order by type,name").fetchall()
  out[rel(p)]={'quick_check':con.execute('pragma quick_check').fetchone()[0], 'objects':[{'name':n,'type':t,'sql':s} for n,t,s in objs], 'tables':[n for n,t,s in objs if t=='table']}
  con.close()
 return out

ROUTE_RE=re.compile(r"\[\s*['\"](GET|POST|PUT|PATCH|DELETE|OPTIONS|HEAD)['\"]\s*,\s*['\"](\/[^'\"]*)['\"]\s*,\s*['\"]([^'\"]+)['\"]")
def route_inventory():
 routes=[]
 for p in files({'.php'}):
  s=text(p)
  for m in ROUTE_RE.finditer(s): routes.append({'method':m.group(1),'path':m.group(2),'handler':m.group(3).replace('\\\\','\\'),'source':rel(p),'line':s.count('\n',0,m.start())+1,'module': '/Modules/' in p.as_posix()})
 groups=defaultdict(list)
 for r in routes: groups[(r['method'],r['path'])].append(r)
 duplicates=[{'method':k[0],'path':k[1],'definitions':v} for k,v in groups.items() if len(v)>1]
 public=[r for r in routes if r['path'].startswith('/api/v1/') or r['path'].startswith('/api/v1/')]
 return {'all':routes,'public':public,'duplicates':duplicates}

CLASS_RE=re.compile(r'(?m)^\s*(?:final\s+|abstract\s+)?(?:class|interface)\s+(\w*Repository\w*)')
NS_RE=re.compile(r'(?m)^namespace\s+([^;]+);')
METHOD_RE=re.compile(r'(?m)^\s*public\s+function\s+(\w+)\s*\(')
SQL_RE=re.compile(r'(?is)(SELECT|INSERT\s+INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO)\s+([a-zA-Z_][a-zA-Z0-9_]*)')
def repository_inventory():
 php=list(files({'.php'})); corpus={rel(p):text(p) for p in php}; repos=[]
 for p in php:
  s=corpus[rel(p)]
  cm=CLASS_RE.search(s)
  if not cm: continue
  ns=(NS_RE.search(s).group(1) if NS_RE.search(s) else '')
  cls=cm.group(1); fqcn=(ns+'\\'+cls).strip('\\')
  consumers=[]
  needles=[fqcn, 'use '+fqcn+';', cls]
  for rp,rs in corpus.items():
   if rp==rel(p): continue
   if fqcn in rs or re.search(r'\b'+re.escape(cls)+r'\b',rs): consumers.append(rp)
  sql=[]
  for m in SQL_RE.finditer(s): sql.append({'operation':m.group(1).upper().replace('  ',' '),'table':m.group(2),'line':s.count('\n',0,m.start())+1})
  methods=METHOD_RE.findall(s)
  repos.append({'class':fqcn,'short_name':cls,'source':rel(p),'kind':'interface' if re.search(r'\binterface\s+'+re.escape(cls),s) else 'class','deprecated':'@deprecated' in s,'methods':methods,'sql':sql,'consumers':sorted(set(consumers))})
 meth=defaultdict(list)
 for r in repos:
  for m in r['methods']: meth[m].append(r['class'])
 duplicates={k:v for k,v in sorted(meth.items()) if len(v)>1}
 return {'classes':repos,'duplicate_method_names':duplicates}

def python_inventory():
 pys=list(files({'.py'})); by_module={}
 for p in pys:
  rp=rel(p); mod=rp[:-3].replace('/','.')
  if mod.endswith('.__init__'): mod=mod[:-9]
  by_module[mod]=rp
 incoming=defaultdict(set); details=[]
 workflow='\n'.join(text(p) for p in (ROOT/'.github/workflows').glob('*') if p.is_file()) if (ROOT/'.github/workflows').exists() else ''
 docs='\n'.join(text(p) for p in files({'.md'}))
 alltext={rel(p):text(p) for p in files(TEXT_EXT)}
 for p in pys:
  rp=rel(p); s=text(p); imports=[]; subs=[]
  try:
   tree=ast.parse(s)
   for n in ast.walk(tree):
    if isinstance(n,ast.Import): imports += [a.name for a in n.names]
    elif isinstance(n,ast.ImportFrom) and n.module: imports.append(n.module)
    elif isinstance(n,ast.Call) and isinstance(n.func,ast.Attribute) and n.func.attr in {'run','call','check_call','check_output','Popen'}:
     seg=ast.get_source_segment(s,n) or ''; subs.append(seg[:300])
  except SyntaxError: pass
  for mod,target in by_module.items():
   if target==rp: continue
   if mod in imports or any(i.startswith(mod+'.') for i in imports): incoming[target].add(rp)
  bn=p.name
  for op,os in alltext.items():
   if op==rp: continue
   if bn in os: incoming[rp].add(op)
  entry=bool(re.search(r"if\s+__name__\s*==\s*['\"]__main__['\"]",s) or rp in {'cms.py','tools/cms.py'})
  details.append({'path':rp,'entry_point':entry,'imports':sorted(set(imports)),'subprocess_calls':subs,'incoming_references':sorted(incoming[rp]),'called_by_ci':bn in workflow or rp in workflow,'documented':bn in docs or rp in docs,'validator':'validate' in p.stem.lower() or re.match(r'c\d+_',p.stem) is not None,'under_tests':'/tests/' in rp})
 return details

def refs(term):
 out=[]
 for p in files(TEXT_EXT):
  s=text(p)
  for i,l in enumerate(s.splitlines(),1):
   if term in l: out.append({'source':rel(p),'line':i,'text':l.strip()[:500]})
 return out

def run(cmd,timeout=180):
 try:
  cp=subprocess.run(cmd,cwd=ROOT,text=True,capture_output=True,timeout=timeout)
  return {'command':' '.join(cmd),'exit_code':cp.returncode,'stdout':cp.stdout[-12000:],'stderr':cp.stderr[-12000:]}
 except Exception as e:return {'command':' '.join(cmd),'exit_code':None,'error':repr(e)}

def main():
 data={'format_version':1,'generated_by':'tools/python/consolidation_inventory.py','root':str(ROOT),'sqlite':sqlite_inventory(),'routes':route_inventory(),'repositories':repository_inventory(),'python_scripts':python_inventory(),'references':{t:refs(t) for t in ['blueprints','blueprint_versions','blueprint_publications','schema_field_types','content_types','fields','fieldsets','field_groups','App\\Repository\\ContentRepository','PublishedProjectionRepository']},'validators':[]}
 checks=[['python3','tools/cms.py','qualify','--profile','standard']]
 for c in checks:data['validators'].append(run(c))
 OUT.parent.mkdir(parents=True,exist_ok=True); OUT.write_text(json.dumps(data,ensure_ascii=False,indent=2)+'\n','utf-8')
 print(OUT.relative_to(ROOT)); return 0 if all(x.get('exit_code')==0 for x in data['validators']) else 1
if __name__=='__main__': raise SystemExit(main())
