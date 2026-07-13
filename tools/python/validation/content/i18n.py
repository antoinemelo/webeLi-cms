from __future__ import annotations

import re

from tools.python.validation.checks import ROOT, require_paths
from tools.python.validation.model import ValidationReport

VALIDATOR_ID = "I18N_COVERAGE"
DOMAIN = "content"
MODES = ("fast", "full")

MESSAGES = ROOT / "frontend/admin-vue/src/i18n/messages.ts"
I18N_INDEX = ROOT / "frontend/admin-vue/src/i18n/index.ts"
I18N_DOC_FR = ROOT / "docs/development/admin-i18n.md"
I18N_DOC_EN = ROOT / "docs/development/admin-i18n.en.md"

COVERED_FRONTEND_FILES = [
    "frontend/admin-vue/src/components/layout/AdminShell.vue",
    "frontend/admin-vue/src/components/layout/GlobalSearch.vue",
    "frontend/admin-vue/src/router/navigation.ts",
    "frontend/admin-vue/src/views/modules/SaleView.vue",
    "frontend/admin-vue/src/views/modules/SalePosView.vue",
    "frontend/admin-vue/src/views/tools/MaintenanceView.vue",
    "frontend/admin-vue/src/views/system/BlueprintsView.vue",
    "frontend/admin-vue/src/components/blueprints/BlueprintFieldEditorModal.vue",
    "frontend/admin-vue/src/components/blueprints/BlueprintInspectorPanel.vue",
    "frontend/admin-vue/src/components/blueprints/BlueprintNavigationPanel.vue",
    "frontend/admin-vue/src/components/blueprints/BlueprintSectionEditorModal.vue",
    "frontend/admin-vue/src/components/blueprints/BlueprintStructureTree.vue",
]

REQUIRED_PREFIXES = ("core.", "business.", "sale.", "maintenance.", "search.", "support.", "configuration.", "blueprints.")
REQUIRED_HELPERS = ("formatNumber", "formatPercent", "formatMoney", "formatDateTime", "recordFallback")
REQUIRED_DOCS = (
    "docs/development/admin-i18n.md",
    "docs/development/admin-i18n.en.md",
)
FORBIDDEN_VISIBLE_FRAGMENTS = [
    "Session caisse ouverte.",
    "Session caisse fermée.",
    "Impossible de préparer le ticket à imprimer.",
    "Indiquer une adresse courriel.",
    "Paiement enregistré.",
    "Commande annulée.",
    "Annuler cette commande ?",
    "Recherche commande, source, paiement",
    "Aucune commande ne correspond aux filtres.",
    "Chargement de la maintenance",
    "Informations de version indisponibles.",
    "Modules installés",
    "Bases de données",
    "Environnements et dépendences",
    "Mettre à jour les versions",
    "Aucune dépendance suivie à afficher.",
    "Journal d’audit",
    "Audits récents",
    "Événements journalisés",
    "Version distante inconnue",
    "Base absente",
    "Divergence checksum",
    "Créer ",
    "Liste ",
    "Brouillon",
    "Publié",
    "Réinitialiser",
    "Nouvelle structure",
    "Modifier la section",
    "Modifier le champ",
    "Enregistrer le brouillon",
    "Activer le brouillon",
    "Aucune version enregistrée",
    "Sélectionnez ou créez",
    "Configuration guidée",
    "utilisation(s)",
]
FORBIDDEN_FR_CATALOG_FRAGMENTS = [
    "Modèle de blueprints",
    "Blueprint indisponible",
    "Fieldset supprimé",
    "Set préféré",
]


def _object_body(text: str, name: str) -> str:
    match = re.search(rf"const\s+{name}\s*[:=][^{{]*\{{(?P<body>.*?)\n\}}(?:\s+as\s+const|\s*;)", text, re.DOTALL)
    return match.group("body") if match else ""


def _catalog_keys(body: str) -> set[str]:
    return set(re.findall(r"^\s*'([^']+)'\s*:", body, re.MULTILINE))


def _catalog_values(body: str) -> dict[str, str]:
    values: dict[str, str] = {}
    for key, value in re.findall(r"^\s*'([^']+)'\s*:\s*'((?:\\'|[^'])*)'", body, re.MULTILINE):
        values[key] = value
    return values


def _params(value: str) -> set[str]:
    return set(re.findall(r"\{([A-Za-z_][A-Za-z0-9_]*)\}", value))


def _used_message_keys(text: str) -> set[str]:
    return set(re.findall(r"\b(?:t|translate)\(\s*'([^']+)'", text))


def validate(mode: str = "fast") -> ValidationReport:
    report = ValidationReport(VALIDATOR_ID, DOMAIN, mode)
    require_paths(
        report,
        ["frontend/admin-vue/src/i18n/messages.ts", "frontend/admin-vue/src/i18n/index.ts", *REQUIRED_DOCS],
        code="I18N-001",
    )
    if not MESSAGES.is_file() or not I18N_INDEX.is_file():
        return report

    text = MESSAGES.read_text(encoding="utf-8", errors="ignore")
    fr_values = _catalog_values(_object_body(text, "fr"))
    en_values = _catalog_values(_object_body(text, "en"))
    fr_keys = set(fr_values)
    en_keys = set(en_values)

    report.checked()
    if not fr_keys:
        report.add("I18N-002", "Catalogue français introuvable", path="frontend/admin-vue/src/i18n/messages.ts")
    report.checked()
    if not en_keys:
        report.add("I18N-003", "Catalogue anglais introuvable", path="frontend/admin-vue/src/i18n/messages.ts")

    for key in sorted(fr_keys - en_keys):
        report.checked()
        report.add("I18N-004", "Clé absente du catalogue anglais", path="frontend/admin-vue/src/i18n/messages.ts", key=key)
    for key in sorted(en_keys - fr_keys):
        report.checked()
        report.add("I18N-005", "Clé absente du catalogue français", path="frontend/admin-vue/src/i18n/messages.ts", key=key)

    for key in sorted(fr_keys & en_keys):
        report.checked()
        if _params(fr_values[key]) != _params(en_values[key]):
            report.add(
                "I18N-006",
                "Paramètres de traduction incohérents",
                path="frontend/admin-vue/src/i18n/messages.ts",
                key=key,
                fr=sorted(_params(fr_values[key])),
                en=sorted(_params(en_values[key])),
            )

    for key, value in sorted(fr_values.items()):
        for fragment in FORBIDDEN_FR_CATALOG_FRAGMENTS:
            report.checked()
            if fragment in value:
                report.add("I18N-012", "Fragment anglais ou jargon non publié dans le catalogue français", path="frontend/admin-vue/src/i18n/messages.ts", key=key, fragment=fragment)

    for prefix in REQUIRED_PREFIXES:
        report.checked()
        if not any(key.startswith(prefix) for key in fr_keys):
            report.add("I18N-007", "Namespace i18n obligatoire absent", path="frontend/admin-vue/src/i18n/messages.ts", prefix=prefix)

    index_text = I18N_INDEX.read_text(encoding="utf-8", errors="ignore")
    for helper in REQUIRED_HELPERS:
        report.checked()
        if helper not in index_text:
            report.add("I18N-008", "Helper i18n obligatoire absent", path="frontend/admin-vue/src/i18n/index.ts", helper=helper)

    used: set[str] = set()
    for rel in COVERED_FRONTEND_FILES + ["frontend/admin-vue/src/views/system/SystemConfigurationView.vue", "frontend/admin-vue/src/components/layout/ContentLanguageSwitcher.vue"]:
        path = ROOT / rel
        if not path.is_file():
            continue
        file_text = path.read_text(encoding="utf-8", errors="ignore")
        used |= _used_message_keys(file_text)
        if rel == "frontend/admin-vue/src/router/navigation.ts":
            continue
        for fragment in FORBIDDEN_VISIBLE_FRAGMENTS:
            report.checked()
            if fragment in file_text:
                report.add("I18N-009", "Chaîne visible codée en dur dans une zone couverte", path=rel, fragment=fragment)

    for key in sorted(used):
        if key.startswith("sale.status."):
            continue
        report.checked()
        if key not in fr_keys:
            report.add("I18N-010", "Clé i18n utilisée mais absente du catalogue", path="frontend/admin-vue/src/i18n/messages.ts", key=key)

    for rel in REQUIRED_DOCS:
        report.checked()
        text_doc = (ROOT / rel).read_text(encoding="utf-8", errors="ignore") if (ROOT / rel).is_file() else ""
        if "I18N_COVERAGE" not in text_doc or "frontend/admin-vue/src/i18n" not in text_doc:
            report.add("I18N-011", "Documentation i18n incomplète", path=rel)

    return report
