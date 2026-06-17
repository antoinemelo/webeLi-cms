#!/usr/bin/env python3
"""Lie une release mineure/majeure à son archive de preuves reproductibles."""
from __future__ import annotations

import argparse
import hashlib
import json
import shutil
import sys
import zipfile
from datetime import datetime, timezone
from pathlib import Path

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from tools.python.lib.release_metadata import load_release_metadata

EXPORTS = ROOT / "storage" / "exports"
AUDIT_RESULTS = ROOT / "storage" / "audit-results"
DOC_EVALUATION = ROOT / "docs" / "evaluation"
MACHINE_READABLE = DOC_EVALUATION / "machine-readable"


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def newest(pattern: str, directory: Path) -> Path:
    candidates = [path for path in directory.glob(pattern) if path.is_file()]
    if not candidates:
        raise FileNotFoundError(f"Aucun fichier correspondant à {pattern} dans {directory}")
    return max(candidates, key=lambda path: path.stat().st_mtime)


def read_evidence_summary(archive: Path) -> dict:
    with zipfile.ZipFile(archive) as bundle:
        names = [name for name in bundle.namelist() if name.endswith("/summary.json")]
        if len(names) != 1:
            raise RuntimeError(
                f"Archive de preuves invalide: un unique summary.json est attendu, trouvé {len(names)}"
            )
        summary = json.loads(bundle.read(names[0]).decode("utf-8"))
    steps = summary.get("steps", [])
    failures = [step for step in steps if step.get("status") != "PASS"]
    if not steps:
        raise RuntimeError("Archive de preuves invalide: aucune étape enregistrée")
    if failures:
        labels = ", ".join(str(step.get("step", "?")) for step in failures[:10])
        raise RuntimeError(f"Audit non valide: {len(failures)} étape(s) non réussie(s): {labels}")
    return summary


def write_sha_file(path: Path, digest: str) -> Path:
    checksum = path.with_suffix(path.suffix + ".sha256")
    checksum.write_text(f"{digest}  {path.name}\n", encoding="utf-8")
    return checksum


def markdown(manifest: dict) -> str:
    verified_on = str(manifest["bound_at"])[:10]
    return f"""---
title: Dernier audit de release validé
document_type: evaluation
audience:
  - evaluator
  - administrator
  - developer
status: stable
version: 1.0
last_verified: {verified_on}
source_of_truth: generated
source_paths:
  - storage/exports/{manifest['technical_version']}/{manifest['technical_version']}.release-evidence.json
  - tools/python/operations/deployment/d13_bind_release_evidence.py
owners:
  - core
generated: true
generator: tools/python/operations/deployment/d13_bind_release_evidence.py
---
# Dernier audit de release validé

Ce document est généré automatiquement pour une release **mineure ou majeure**. Les patchs ne déclenchent pas ce processus et conservent la qualification locale standard.

- Version : `{manifest['technical_version']}`
- Type : `{manifest['release_type']}`
- Nom : {manifest['release_name']}
- Audit : `{manifest['audit_status']}`
- Profil : `{manifest['audit_profile']}`
- Date UTC : `{manifest['bound_at']}`
- Archive de release : `{manifest['release_archive']}`
- SHA-256 release : `{manifest['release_sha256']}`
- Archive de preuves : `{manifest['evidence_archive']}`
- SHA-256 preuves : `{manifest['evidence_sha256']}`
- Étapes démontrées : {manifest['audit_steps_passed']}

## Vérification indépendante

Depuis le dossier contenant les artefacts :

```bash
sha256sum -c {manifest['release_checksum_file']}
sha256sum -c {manifest['evidence_checksum_file']}
```

L’archive de preuves reste séparée de l’archive exécutable. Le manifeste JSON associé permet de vérifier qu’elles appartiennent au même processus de livraison.
"""


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Associe une release mineure/majeure au dernier audit reproductible réussi.")
    parser.add_argument(
        "--release-archive",
        default="",
        help=(
            "Archive de release explicite. Par défaut: "
            "storage/exports/<technical_version>/<technical_version>.zip"
        ),
    )
    parser.add_argument("--evidence-archive", default="", help="Archive de preuves explicite. Par défaut: la plus récente dans storage/audit-results")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    metadata = load_release_metadata()
    if metadata.release_type not in {"minor", "major"}:
        raise RuntimeError(
            "L’association des preuves est réservée aux releases mineures et majeures; "
            f"type courant: {metadata.release_type}."
        )

    release_dir = EXPORTS / metadata.technical_version
    release_archive = (
        Path(args.release_archive).expanduser()
        if args.release_archive
        else release_dir / f"{metadata.technical_version}.zip"
    )
    if not release_archive.is_absolute():
        release_archive = ROOT / release_archive
    if not release_archive.is_file():
        raise FileNotFoundError(f"Archive de release introuvable: {release_archive}")

    evidence_source = Path(args.evidence_archive).expanduser() if args.evidence_archive else newest("cms-audit-evidence-*.zip", AUDIT_RESULTS)
    if not evidence_source.is_absolute():
        evidence_source = ROOT / evidence_source
    if not evidence_source.is_file():
        raise FileNotFoundError(f"Archive de preuves introuvable: {evidence_source}")

    summary = read_evidence_summary(evidence_source)
    evidence_target = release_dir / f"{metadata.technical_version}-audit-evidence.zip"
    release_dir.mkdir(parents=True, exist_ok=True)
    if evidence_source.resolve() != evidence_target.resolve():
        shutil.copy2(evidence_source, evidence_target)

    release_digest = sha256(release_archive)
    evidence_digest = sha256(evidence_target)
    release_checksum = write_sha_file(release_archive, release_digest)
    evidence_checksum = write_sha_file(evidence_target, evidence_digest)

    manifest = {
        "schema_version": 1,
        "technical_version": metadata.technical_version,
        "release_type": metadata.release_type,
        "release_name": metadata.release_name,
        "bound_at": datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z"),
        "audit_profile": "release",
        "audit_status": "PASS",
        "audit_steps_passed": len(summary.get("steps", [])),
        "release_archive": release_archive.name,
        "release_sha256": release_digest,
        "release_checksum_file": release_checksum.name,
        "evidence_archive": evidence_target.name,
        "evidence_sha256": evidence_digest,
        "evidence_checksum_file": evidence_checksum.name,
        "policy": {
            "applies_to": ["minor", "major"],
            "patches_require_reproducible_audit": False,
            "evidence_embedded_in_runtime_archive": False,
        },
    }

    manifest_path = release_dir / f"{metadata.technical_version}.release-evidence.json"
    manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    MACHINE_READABLE.mkdir(parents=True, exist_ok=True)
    latest_json = MACHINE_READABLE / "latest-release-audit.json"
    latest_md = DOC_EVALUATION / "latest-release-audit.md"
    latest_json.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    latest_md.write_text(markdown(manifest), encoding="utf-8")

    print(f"Release liée aux preuves: {release_archive}")
    print(f"Preuves associées: {evidence_target}")
    print(f"Manifeste: {manifest_path}")
    print(f"Documentation: {latest_md}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
