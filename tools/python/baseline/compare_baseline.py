#!/usr/bin/env python3
"""Semantic comparison of two deterministic baseline snapshots."""
from __future__ import annotations
import argparse,json
from pathlib import Path

def index(seq,key): return {x[key]:x for x in seq}
def main()->int:
 ap=argparse.ArgumentParser(); ap.add_argument('before',type=Path); ap.add_argument('after',type=Path); ap.add_argument('--output',type=Path); a=ap.parse_args()
 left=json.loads(a.before.read_text()); right=json.loads(a.after.read_text()); report=[]
 ldb=index(left['databases'],'file'); rdb=index(right['databases'],'file')
 for name in sorted(set(ldb)|set(rdb)):
  if name not in ldb: report.append(f'+ database {name}'); continue
  if name not in rdb: report.append(f'- database {name}'); continue
  if ldb[name]['schema_checksum']!=rdb[name]['schema_checksum']: report.append(f'~ schema {name}')
  if ldb[name]['focus_checksum']!=rdb[name]['focus_checksum']: report.append(f'~ reference data {name}')
 lr={(x['method'],x['path']) for x in left['routes']}; rr={(x['method'],x['path']) for x in right['routes']}
 report += [f'+ route {m} {p}' for m,p in sorted(rr-lr)] + [f'- route {m} {p}' for m,p in sorted(lr-rr)]
 text='# Baseline comparison\n\n'+ ('No semantic difference detected.\n' if not report else '\n'.join(f'- {x}' for x in report)+'\n')
 if a.output: a.output.write_text(text,encoding='utf-8')
 else: print(text,end='')
 return 1 if report else 0
if __name__=='__main__': raise SystemExit(main())
