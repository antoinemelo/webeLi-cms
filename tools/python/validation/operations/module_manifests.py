from __future__ import annotations

import json
from pathlib import Path
from typing import Any

from tools.python.validation.model import ValidationReport

VALIDATOR_ID = "MODULE_MANIFESTS"
DOMAIN = "operations"
MODES = ("fast", "full")

ROOT = Path(__file__).resolve().parents[4]
SYSTEM_MANIFESTS = (
    "backend/src/Modules/Forms/module.json",
    "backend/src/Modules/Business/module.json",
    "backend/src/Modules/AiAssistant/module.json",
)
REQUIRED_FIELDS = ("key", "version", "type", "provider_class", "provider_file")
EXAMPLE_MANIFESTS_ROOT = "examples/modules"


def _rel(path: Path) -> str:
    return path.relative_to(ROOT).as_posix()


def _load_json(report: ValidationReport, rel: str) -> dict[str, Any] | None:
    report.checked()
    path = ROOT / rel
    if not path.is_file():
        report.add("MOD-001", "Manifeste module absent", path=rel)
        return None
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except Exception as exc:
        report.add("MOD-002", "Manifeste module JSON invalide", path=rel, error=str(exc))
        return None
    if not isinstance(data, dict):
        report.add("MOD-003", "Manifeste module non objet", path=rel)
        return None
    return data


def _is_local_module_path(value: str) -> bool:
    normalized = value.replace("\\", "/").strip()
    return normalized == "local/modules" or normalized.startswith("local/modules/")


def _is_example_module_path(value: str) -> bool:
    normalized = value.replace("\\", "/").strip()
    return normalized == EXAMPLE_MANIFESTS_ROOT or normalized.startswith(EXAMPLE_MANIFESTS_ROOT + "/")


def _validate_manifest(report: ValidationReport, rel: str, *, expected_type: str | None = None, allow_example_paths: bool = False) -> None:
    data = _load_json(report, rel)
    if data is None:
        return

    for field in REQUIRED_FIELDS:
        report.checked()
        if not isinstance(data.get(field), str) or not str(data.get(field)).strip():
            report.add("MOD-004", "Champ obligatoire absent ou vide", path=rel, field=field)

    report.checked()
    module_type = str(data.get("type", "")).strip()
    if module_type not in {"system", "client"}:
        report.add("MOD-005", "Type de module invalide", path=rel, value=module_type)
    elif expected_type and module_type != expected_type:
        report.add("MOD-006", "Type de module inattendu", path=rel, expected=expected_type, actual=module_type)

    provider_file = str(data.get("provider_file", "")).strip()
    if provider_file:
        report.checked()
        if module_type == "client" and not _is_local_module_path(provider_file):
            if not (allow_example_paths and _is_example_module_path(provider_file)):
                report.add("MOD-007", "Provider client hors local/modules", path=rel, provider_file=provider_file)
        if module_type == "system" and not (ROOT / provider_file).is_file():
            report.add("MOD-008", "Provider système référencé absent", path=rel, provider_file=provider_file)

    for item in data.get("databases", []):
        if not isinstance(item, dict):
            continue
        migrations = str(item.get("migrations", "")).strip()
        if migrations:
            report.checked()
            if module_type == "client" and not _is_local_module_path(migrations):
                if not (allow_example_paths and _is_example_module_path(migrations)):
                    report.add("MOD-009", "Migrations client hors local/modules", path=rel, migrations=migrations)
        schema = str(item.get("schema", "")).strip()
        if schema:
            report.checked()
            if allow_example_paths and _is_example_module_path(schema) and not (ROOT / schema).is_file():
                report.add("MOD-016", "Schéma exemple introuvable", path=rel, schema=schema)


def _declared_local_manifest_paths(report: ValidationReport, rel: str) -> list[str]:
    path = ROOT / rel
    if not path.is_file():
        return []
    data = _load_json(report, rel)
    if data is None:
        return []
    manifests: list[str] = []
    modules = data.get("modules", [])
    if not isinstance(modules, list):
        report.add("MOD-010", "modules doit être une liste", path=rel)
        return []
    for entry in modules:
        if not isinstance(entry, dict):
            continue
        manifest = str(entry.get("manifest", "")).strip()
        if not manifest:
            continue
        report.checked()
        if not _is_local_module_path(manifest):
            report.add("MOD-011", "Manifeste client déclaré hors local/modules", path=rel, manifest=manifest)
            continue
        manifests.append(manifest)
    return manifests


def _validate_deployment_protection(report: ValidationReport) -> None:
    rel = "tools/python/operations/deployment/d8_deploy_web_update.py"
    path = ROOT / rel
    report.checked()
    text = path.read_text(encoding="utf-8", errors="ignore") if path.is_file() else ""
    for token in ('"local/modules/"', '"ops/modules.local.json"'):
        report.checked()
        if token not in text:
            report.add("MOD-012", "Préfixe protégé absent du déploiement web", path=rel, token=token)


def validate(mode: str = "fast") -> ValidationReport:
    report = ValidationReport(VALIDATOR_ID, DOMAIN, mode)

    for rel in SYSTEM_MANIFESTS:
        _validate_manifest(report, rel, expected_type="system")

    # Le fichier réel est propre à l'instance; l'exemple documente le contrat.
    for rel in ("ops/modules.local.json.example", "ops/modules.local.json"):
        for manifest in _declared_local_manifest_paths(report, rel):
            if (ROOT / manifest).is_file():
                _validate_manifest(report, manifest, expected_type="client")

    # Si des modules clients existent localement, ils doivent rester sous la convention.
    local_root = ROOT / "local/modules"
    if local_root.exists():
        for manifest in local_root.glob("*/module.json"):
            _validate_manifest(report, _rel(manifest), expected_type="client")

    # Les exemples documentés ne sont pas chargés en production. Ils peuvent
    # vivre sous examples/modules, mais doivent rester non activés.
    examples_root = ROOT / EXAMPLE_MANIFESTS_ROOT
    if examples_root.exists():
        for manifest in examples_root.glob("*/module.json"):
            rel = _rel(manifest)
            _validate_manifest(report, rel, expected_type="client", allow_example_paths=True)
            data = _load_json(report, rel)
            if isinstance(data, dict):
                report.checked(2)
                if data.get("enabled_by_default") is not False:
                    report.add("MOD-013", "Un module exemple client ne doit pas être activé par défaut", path=rel)
                if data.get("protected_on_core_update") is not True:
                    report.add("MOD-014", "Un module exemple client doit documenter la protection core update", path=rel)

    _validate_deployment_protection(report)
    return report
