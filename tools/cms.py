#!/usr/bin/env python3
"""Point d’entrée stable DEC CMS : maintenance, audit reproductible et releases."""
from __future__ import annotations
import sys
from pathlib import Path
PROJECT_ROOT = Path(__file__).resolve().parents[1]
CLI_MODULE = PROJECT_ROOT / "tools" / "python" / "cms" / "cli.py"
if not CLI_MODULE.is_file():
    raise SystemExit(
        "DEC CMS CLI installation incomplete.\n"
        f"Missing file: {CLI_MODULE}\n"
        "Deploy tools/python together with tools/cms.py."
    )
if str(PROJECT_ROOT) not in sys.path:
    sys.path.insert(0, str(PROJECT_ROOT))
from tools.python.cms.cli import main
if __name__ == "__main__":
    raise SystemExit(main())
