#!/usr/bin/env python3
from __future__ import annotations

import json
import re
from dataclasses import dataclass, asdict
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[3]
RELEASE_CONFIG = ROOT / "config" / "release.json"

PRODUCT_CODE_RE = re.compile(r"^[a-zA-Z][a-zA-Z0-9_-]{1,31}$")
PACKAGE_ROOT_RE = re.compile(r"^[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}$")
MAJOR_RE = re.compile(r"^v\d{2,}$")
MINOR_RE = re.compile(r"^e\d{2,}$")
PATCH_RE = re.compile(r"^[a-z]$")


@dataclass
class ReleaseMetadata:
    schema_version: int
    product_name: str
    product_code: str
    package_root: str
    major: str
    minor: str
    patch: str
    release_type: str
    release_name: str
    technical_version: str
    history_policy: dict[str, Any]

    def refresh_technical_version(self) -> None:
        self.technical_version = build_technical_version(self.product_code, self.major, self.minor, self.patch)

    def package_name(self) -> str:
        return self.technical_version

    def to_dict(self) -> dict[str, Any]:
        self.refresh_technical_version()
        return asdict(self)


def build_technical_version(product_code: str, major: str, minor: str, patch: str) -> str:
    return f"{product_code}_{major}-{minor}{patch}"


def default_metadata() -> ReleaseMetadata:
    metadata = ReleaseMetadata(
        schema_version=1,
        product_name="DEC CMS",
        product_code="mod2",
        package_root="mod",
        major="v01",
        minor="e03",
        patch="i",
        release_type="patch",
        release_name="Release assistant simplifié",
        technical_version="",
        history_policy={
            "minor_resets_patch_history": True,
            "major_resets_minor_and_patch_history": True,
            "package_contains_previous_exports": False,
        },
    )
    metadata.refresh_technical_version()
    return metadata


def load_release_metadata(path: Path = RELEASE_CONFIG) -> ReleaseMetadata:
    if not path.exists():
        return default_metadata()
    raw = json.loads(path.read_text(encoding="utf-8"))
    base = default_metadata().to_dict()
    base.update(raw)
    metadata = ReleaseMetadata(**base)
    validate_release_metadata(metadata)
    metadata.refresh_technical_version()
    return metadata


def save_release_metadata(metadata: ReleaseMetadata, path: Path = RELEASE_CONFIG) -> None:
    validate_release_metadata(metadata)
    metadata.refresh_technical_version()
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(metadata.to_dict(), ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def validate_release_metadata(metadata: ReleaseMetadata) -> None:
    errors: list[str] = []
    if not metadata.product_name.strip():
        errors.append("product_name ne peut pas être vide")
    if not PRODUCT_CODE_RE.match(metadata.product_code):
        errors.append("product_code invalide: utilisez lettres/chiffres/_/-, sans espace, en commençant par une lettre")
    if not PACKAGE_ROOT_RE.match(metadata.package_root):
        errors.append("package_root invalide: utilisez un nom de dossier simple, sans slash ni espace")
    if metadata.package_root in {".", ".."} or "/" in metadata.package_root or "\\" in metadata.package_root:
        errors.append("package_root doit être un seul dossier, par exemple mod")
    if not MAJOR_RE.match(metadata.major):
        errors.append("major invalide: format attendu v01, v02, ...")
    if not MINOR_RE.match(metadata.minor):
        errors.append("minor invalide: format attendu e01, e02, ...")
    if not PATCH_RE.match(metadata.patch):
        errors.append("patch invalide: format attendu une lettre minuscule, par exemple a")
    if metadata.release_type not in {"rename", "patch", "minor", "major"}:
        errors.append("release_type invalide: rename, patch, minor ou major")
    expected = build_technical_version(metadata.product_code, metadata.major, metadata.minor, metadata.patch)
    if metadata.technical_version and metadata.technical_version != expected:
        errors.append(f"technical_version incohérente: attendu {expected}")
    if errors:
        raise ValueError("Configuration release invalide:\n- " + "\n- ".join(errors))


def next_patch_letter(value: str) -> str:
    if not PATCH_RE.match(value):
        return "a"
    if value == "z":
        raise ValueError("Impossible d'incrémenter le patch après z. Préparez une release mineure.")
    return chr(ord(value) + 1)


def next_minor(value: str) -> str:
    if not MINOR_RE.match(value):
        return "e01"
    return f"e{int(value[1:]) + 1:02d}"


def next_major(value: str) -> str:
    if not MAJOR_RE.match(value):
        return "v01"
    return f"v{int(value[1:]) + 1:02d}"
