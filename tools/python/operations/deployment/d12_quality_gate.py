#!/usr/bin/env python3
"""Alias temporaire vers la qualification release canonique."""
from __future__ import annotations
import subprocess
import sys
from pathlib import Path

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())


def main() -> int:
    print("AVERTISSEMENT: d12_quality_gate.py est déprécié; utilisez `python3 tools/cms.py qualify --profile release`.", file=sys.stderr)
    return subprocess.run([sys.executable, "-m", "tools.python.qualification.run_all", "--profile", "release"], cwd=ROOT).returncode


if __name__ == "__main__":
    raise SystemExit(main())
