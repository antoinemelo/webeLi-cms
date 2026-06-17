#!/usr/bin/env python3
"""Vérifie le contrat de complétude et de compacité d'un dossier de preuves."""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path

FORBIDDEN_SUFFIXES = (".zip", ".tar", ".tar.gz", ".tgz", ".7z", ".sqlite", ".sqlite3", ".db")
REQUIRED_ROOT_FILES = ("environment.txt", "summary.tsv")

def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def verify(run_dir: Path) -> list[str]:
    errors: list[str] = []
    for name in REQUIRED_ROOT_FILES:
        if not (run_dir / name).is_file():
            errors.append(f"fichier obligatoire absent: {name}")

    logs = run_dir / "logs"
    if not logs.is_dir() or not any(logs.glob("*.log")):
        errors.append("aucun journal d'étape sous logs/")

    manifest_path = run_dir / "artifacts" / "evidence-manifest.json"
    if not manifest_path.is_file():
        errors.append("manifeste absent: artifacts/evidence-manifest.json")
    else:
        try:
            manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
            policy = manifest.get("policy", {})
            for key in ("archives_embedded", "databases_embedded", "backups_embedded", "previous_audits_embedded"):
                if policy.get(key) is not False:
                    errors.append(f"politique de compacité invalide: {key}")
            for record in manifest.get("external_artifacts", []):
                if record.get("embedded") is not False or not record.get("sha256"):
                    errors.append(f"artefact externe mal référencé: {record.get('path', '?')}")
            artifacts_root = run_dir / "artifacts"
            for record in manifest.get("files", []):
                relative = record.get("path")
                expected = record.get("sha256")
                if not isinstance(relative, str) or not isinstance(expected, str):
                    errors.append("enregistrement de fichier embarqué invalide")
                    continue
                embedded = artifacts_root / relative
                if not embedded.is_file():
                    errors.append(f"fichier embarqué absent: {relative}")
                    continue
                actual = sha256(embedded)
                if actual != expected:
                    errors.append(f"checksum invalide pour le fichier embarqué: {relative}")
        except (json.JSONDecodeError, OSError) as exc:
            errors.append(f"manifeste illisible: {exc}")

    for path in run_dir.rglob("*"):
        if not path.is_file():
            continue
        name = path.name.lower()
        if name.endswith(FORBIDDEN_SUFFIXES):
            errors.append(f"payload lourd interdit dans la preuve: {path.relative_to(run_dir)}")

    return errors


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Vérifie un dossier de preuves d'audit.")
    parser.add_argument("--run-dir", type=Path, required=True)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    run_dir = args.run_dir.resolve()
    errors = verify(run_dir)
    if errors:
        for error in errors:
            print(f"ERROR: {error}")
        return 1
    print(f"Preuves conformes: {run_dir}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
