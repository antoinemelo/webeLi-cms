#!/usr/bin/env python3
"""Compatibility viewer for tools/python/tool-manifest.json."""
from __future__ import annotations
import argparse,json
from pathlib import Path
ROOT=next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file()); MANIFEST=ROOT/'tools/python/tool-manifest.json'
p=argparse.ArgumentParser();p.add_argument('--json',action='store_true');args=p.parse_args()
data=json.loads(MANIFEST.read_text())
if args.json: print(json.dumps(data,ensure_ascii=False,indent=2))
else:
 print('CLI publique: python3 tools/cms.py')
 for item in data['tools']:
  if item['public'] or item['status']=='archived': print(f"{item['path']}: {item['status']} -> {item['replacement'] or '-'}")
