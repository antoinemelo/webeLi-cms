#!/usr/bin/env python3
from __future__ import annotations

import argparse
import subprocess
import sys
from pathlib import Path


def main() -> int:
    parser = argparse.ArgumentParser(description="Verify the bundled DEC CMS PHP runtime.")
    parser.add_argument("runtime", help="Runtime directory containing bin/php")
    args = parser.parse_args()

    runtime = Path(args.runtime).expanduser().resolve()
    php = runtime / "bin" / "php"
    if not php.is_file():
        print(f"Runtime PHP introuvable: {php}", file=sys.stderr)
        return 2

    probe = "echo PHP_VERSION . \"\\n\"; echo in_array('sqlite', PDO::getAvailableDrivers(), true) ? 'sqlite-ok' : 'sqlite-missing';"
    completed = subprocess.run([str(php), "-r", probe], text=True, capture_output=True, timeout=15)
    if completed.returncode != 0:
        print(completed.stderr.strip() or "PHP runtime probe failed", file=sys.stderr)
        return completed.returncode

    lines = completed.stdout.strip().splitlines()
    version = lines[0] if lines else ""
    sqlite = lines[1] if len(lines) > 1 else ""
    if not version.startswith("8.4."):
        print(f"Runtime PHP attendu 8.4.x, reçu {version}", file=sys.stderr)
        return 3
    if sqlite != "sqlite-ok":
        print("Runtime PHP sans PDO sqlite", file=sys.stderr)
        return 4

    print(f"Runtime OK: PHP {version}, PDO sqlite disponible")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
