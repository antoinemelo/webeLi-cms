#!/usr/bin/env python3
"""Compatibility wrapper. Use: python3 tools/cms.py validate."""
from __future__ import annotations
import sys
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path: sys.path.insert(0,str(ROOT))
from tools.python.cms.cli import main
if __name__ == '__main__':
    print('AVERTISSEMENT: tools/python/archive/c_validate.py est déprécié; utilisez python3 tools/cms.py validate.', file=sys.stderr)
    raise SystemExit(main(['validate', *sys.argv[1:]]))
