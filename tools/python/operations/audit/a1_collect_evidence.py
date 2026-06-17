#!/usr/bin/env python3
"""Collecte déterministe et compacte des preuves d'audit.

Le collecteur applique une liste blanche : il ne copie jamais les archives,
les sauvegardes, les bases SQLite, les dépendances ou les résultats d'audits
antérieurs. Les artefacts volumineux sont représentés par des métadonnées et
des empreintes SHA-256, ce qui conserve la preuve sans duplication récursive.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import shutil
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Iterable

ARCHIVE_SUFFIXES = (".zip", ".tar", ".tar.gz", ".tgz", ".7z", ".gz")
DATABASE_SUFFIXES = (".sqlite", ".sqlite3", ".db")
MAX_LOG_BYTES = 5 * 1024 * 1024


@dataclass(frozen=True)
class EvidenceFile:
    source: Path
    destination: Path
    category: str


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def is_forbidden_payload(path: Path) -> bool:
    lowered = path.name.lower()
    return lowered.endswith(ARCHIVE_SUFFIXES) or lowered.endswith(DATABASE_SUFFIXES)


def copy_file(item: EvidenceFile, root: Path) -> dict[str, object]:
    destination = root / item.destination
    destination.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(item.source, destination)
    size = destination.stat().st_size
    return {
        "path": item.destination.as_posix(),
        "category": item.category,
        "size_bytes": size,
        "sha256": sha256(destination),
    }


def iter_machine_readable(project: Path) -> Iterable[EvidenceFile]:
    directory = project / "docs" / "evaluation" / "machine-readable"
    if not directory.is_dir():
        return
    for source in sorted(directory.glob("*.json")):
        yield EvidenceFile(source, Path("evaluation/machine-readable") / source.name, "evaluation")


def iter_runtime_metadata(project: Path) -> Iterable[EvidenceFile]:
    explicit = (
        ("storage/deployments/release-manifest.json", "release/release-manifest.json"),
        ("storage/qualification/latest.json", "qualification/latest.json"),
    )
    for source_rel, destination_rel in explicit:
        source = project / source_rel
        if source.is_file():
            yield EvidenceFile(source, Path(destination_rel), "runtime-metadata")

    exports = project / "storage" / "exports"
    if exports.is_dir():
        for source in sorted(exports.rglob("*.json")):
            relative = source.relative_to(exports)
            # Uniquement les manifestes JSON; aucune archive ou payload exporté.
            yield EvidenceFile(source, Path("release/exports") / relative, "release-metadata")


def iter_current_logs(project: Path) -> Iterable[EvidenceFile]:
    logs = project / "storage" / "logs"
    if not logs.is_dir():
        return
    for source in sorted(logs.glob("*.log")):
        if source.stat().st_size <= MAX_LOG_BYTES:
            yield EvidenceFile(source, Path("runtime-logs") / source.name, "runtime-log")


def describe_external_artifacts(project: Path) -> list[dict[str, object]]:
    """Inventorie les artefacts lourds sans les incorporer à la preuve."""
    roots = (
        project / "storage" / "exports",
        project / "storage" / "backups",
        project / "storage" / "audit-results",
        project / "storage" / "deployments" / "releases",
    )
    records: list[dict[str, object]] = []
    for root in roots:
        if not root.is_dir():
            continue
        for path in sorted(root.rglob("*")):
            if not path.is_file() or not is_forbidden_payload(path):
                continue
            records.append(
                {
                    "path": path.relative_to(project).as_posix(),
                    "size_bytes": path.stat().st_size,
                    "sha256": sha256(path),
                    "embedded": False,
                    "reason": "heavy-or-recursive-payload",
                }
            )
    return records


def collect(project: Path, run_dir: Path) -> dict[str, object]:
    artifacts = run_dir / "artifacts"
    artifacts.mkdir(parents=True, exist_ok=True)

    candidates = [
        *iter_machine_readable(project),
        *iter_runtime_metadata(project),
        *iter_current_logs(project),
    ]

    # Déduplication déterministe par destination; une collision est une erreur.
    destinations: set[Path] = set()
    copied: list[dict[str, object]] = []
    for item in candidates:
        if is_forbidden_payload(item.source):
            raise RuntimeError(f"Payload interdit dans les preuves: {item.source}")
        if item.destination in destinations:
            raise RuntimeError(f"Destination de preuve dupliquée: {item.destination}")
        destinations.add(item.destination)
        copied.append(copy_file(item, artifacts))

    external = describe_external_artifacts(project)
    manifest = {
        "schema_version": 1,
        "generated_at": datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z"),
        "policy": {
            "collection_mode": "allowlist",
            "archives_embedded": False,
            "databases_embedded": False,
            "backups_embedded": False,
            "previous_audits_embedded": False,
            "external_artifacts_are_hashed": True,
            "max_runtime_log_bytes": MAX_LOG_BYTES,
        },
        "files": copied,
        "external_artifacts": external,
        "totals": {
            "embedded_files": len(copied),
            "embedded_bytes": sum(int(item["size_bytes"]) for item in copied),
            "external_files": len(external),
            "external_bytes": sum(int(item["size_bytes"]) for item in external),
        },
    }
    manifest_path = artifacts / "evidence-manifest.json"
    manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    return manifest


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Collecte compacte des preuves d'audit.")
    parser.add_argument("--project", type=Path, required=True)
    parser.add_argument("--run-dir", type=Path, required=True)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    project = args.project.resolve()
    run_dir = args.run_dir.resolve()
    if not (project / "tools" / "cms.py").is_file():
        raise FileNotFoundError(f"Racine CMS invalide: {project}")
    manifest = collect(project, run_dir)
    totals = manifest["totals"]
    print(
        "Preuves collectées: "
        f"{totals['embedded_files']} fichier(s), {totals['embedded_bytes']} octet(s); "
        f"{totals['external_files']} artefact(s) lourd(s) référencé(s) par empreinte."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
