from __future__ import annotations

from pathlib import Path

from tools.python.validation.model import ValidationReport

VALIDATOR_ID = "CAPABILITY_REGISTRY"
DOMAIN = "shared"
MODES = ("fast", "full")

ROOT = Path(__file__).resolve().parents[4]
REGISTRY = ROOT / "backend/src/Application/Capability/CapabilityRegistry.php"
DEFINITION = ROOT / "backend/src/Application/Capability/CapabilityDefinition.php"
EXECUTOR = ROOT / "backend/src/Application/Capability/CapabilityExecutor.php"
SALE_PROVIDER = ROOT / "backend/src/Modules/Sale/SaleModuleProvider.php"
DOC = ROOT / "docs/development/extending/capabilities.md"

REQUIRED_KEYS = (
    "core.context.describe",
    "catalog.product.read",
    "catalog.product.extend",
    "pricing.calculate",
    "cart.validate",
    "checkout.validate",
    "order.after_place",
    "payment.provider",
    "fulfillment.provider",
    "notification.provider",
    "crm.activity.consume",
)

SALE_DECLARATIONS = (
    "catalog.product.read",
    "pricing.calculate",
    "cart.validate",
    "checkout.validate",
    "payment.provider",
    "order.after_place",
)


def _read(path: Path) -> str:
    return path.read_text(encoding="utf-8", errors="ignore") if path.is_file() else ""


def validate(mode: str = "fast") -> ValidationReport:
    report = ValidationReport(VALIDATOR_ID, DOMAIN, mode)
    registry = _read(REGISTRY)
    definition = _read(DEFINITION)
    executor = _read(EXECUTOR)
    sale = _read(SALE_PROVIDER)
    doc = _read(DOC)

    for path, text in (
        (REGISTRY, registry),
        (DEFINITION, definition),
        (EXECUTOR, executor),
        (SALE_PROVIDER, sale),
        (DOC, doc),
    ):
        report.checked()
        if not text:
            report.add("CAP-001", "Fichier capacité absent ou vide", path=path.relative_to(ROOT).as_posix())

    for key in REQUIRED_KEYS:
        report.checked(2)
        if key not in registry:
            report.add("CAP-002", "Clé de capacité absente du catalogue core", path=REGISTRY.relative_to(ROOT).as_posix(), key=key)
        if key not in doc:
            report.add("CAP-003", "Clé de capacité non documentée", path=DOC.relative_to(ROOT).as_posix(), key=key)

    for token in ("version", "type", "contract", "config", "active", "priority"):
        report.checked()
        if token not in definition:
            report.add("CAP-004", "Champ de définition capacité absent", path=DEFINITION.relative_to(ROOT).as_posix(), field=token)

    for token in ("Unknown capability key", "Capability collision", "diagnostics"):
        report.checked()
        if token not in registry:
            report.add("CAP-005", "Garde de registre absent", path=REGISTRY.relative_to(ROOT).as_posix(), token=token)

    for token in ("incompatible_contract", "validator_apply_forbidden", "capability_inactive"):
        report.checked()
        if token not in executor:
            report.add("CAP-006", "Refus d'exécution absent", path=EXECUTOR.relative_to(ROOT).as_posix(), token=token)

    for key in SALE_DECLARATIONS:
        report.checked()
        if key not in sale:
            report.add("CAP-007", "Déclaration capacité Vente absente", path=SALE_PROVIDER.relative_to(ROOT).as_posix(), key=key)

    for token in ("ModuleCapabilityProvider", "ModuleCapabilityHandlerProvider", "'foreign_tables' => []", "'transport' => 'outbox'"):
        report.checked()
        if token not in sale:
            report.add("CAP-008", "Exemple Vente incomplet", path=SALE_PROVIDER.relative_to(ROOT).as_posix(), token=token)

    forbidden_sql = ("UPDATE business_", "INSERT INTO business_", "DELETE FROM business_", "UPDATE crm_", "INSERT INTO crm_", "DELETE FROM crm_")
    for token in forbidden_sql:
        report.checked()
        if token in sale:
            report.add("CAP-009", "Le provider Vente ne doit pas modifier directement les tables étrangères", path=SALE_PROVIDER.relative_to(ROOT).as_posix(), token=token)

    return report
