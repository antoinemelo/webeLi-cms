from __future__ import annotations

import re
import subprocess
import sys
from pathlib import Path

from tools.python.validation.model import ValidationReport

VALIDATOR_ID = "DOCUMENTATION_CONTRACTS"
DOMAIN = "documentation"
MODES = ("fast", "full")
ROOT = Path(__file__).resolve().parents[4]

CRITICAL_PAGES = (
    "README.md",
    "docs/README.md",
    "docs/getting-started/README.md",
    "docs/user-guide/README.md",
    "docs/administration/README.md",
    "docs/installation/README.md",
    "docs/operations/README.md",
    "docs/api/README.md",
    "docs/development/README.md",
    "docs/reference/README.md",
    "docs/evaluation/README.md",
    "docs/evaluation/limitations.md",
    "docs/evaluation/evidence-index.md",
)
REQUIRED_FRONT_MATTER = {"title", "audience", "status", "source_of_truth", "owners", "document_type"}
ALLOWED_SOURCE_TYPES = {
    "manual",
    "code",
    "configuration",
    "database-schema",
    "contract",
    "generated",
    "procedure",
}

LINK_RE = re.compile(r"\[[^\]]+\]\(([^)]+)\)")
CLI_RE = re.compile(r"(?:python3|/usr/bin/python3)\s+tools/cms\.py\s+([a-z][a-z0-9-]*)(?:\s+([a-z][a-z0-9-]*))?")
ALLOWED_DOC_ACTIONS = {"generate", "check", "evaluation-generate", "evaluation-check"}
LEGACY_DIRS = ("docs/archive", "docs/history", "docs/internal")

ADMIN_DOC_VIEWER_REQUIRED_SNIPPETS = (
    ("backend/src/Application/Api/Admin/DocsApiController.php", "data-doc-id", "résolution serveur des liens Markdown internes"),
    ("backend/src/Application/Api/Admin/DocsApiController.php", "withResolvedMarkdownLinks", "enrichissement des liens Markdown rendus"),
    ("backend/src/Application/Api/Admin/DocsApiController.php", "resolveRequestedDocument", "résolution serveur robuste des identifiants ou chemins Markdown"),
    ("backend/src/Application/Api/Admin/DocsApiController.php", "link_path", "secours serveur avec chemin de lien Markdown"),
    ("backend/src/Application/Api/Admin/DocsApiController.php", "documentPermissions", "filtrage de chaque document selon son front matter"),
    ("backend/src/Application/Api/Admin/DocsApiController.php", "$frontMatter, $permissions, $isSuperAdmin", "application du front matter au contrôle d’accès"),
    ("frontend/admin-vue/src/views/assets/DocsView.vue", "documentIdFromRelativePath", "résolution client de secours des chemins Markdown"),
    ("frontend/admin-vue/src/views/assets/DocsView.vue", "explicitDocumentIdFromHref", "navigation client via les liens #docs/<id>"),
    ("frontend/admin-vue/src/views/assets/DocsView.vue", "from_id", "transmission du document source pour les liens relatifs"),
    ("backend/routes/api.php", "/admin/api/docs/resolve", "endpoint stable de résolution des liens Markdown internes"),
    ("backend/routes/api.php", "/admin/api/docs/{id:.+}", "compatibilité avec les anciens liens Markdown encodés dans le chemin"),
)


def _front_matter(text: str) -> dict[str, object] | None:
    if not text.startswith("---\n"):
        return None
    end = text.find("\n---\n", 4)
    if end < 0:
        return None
    data: dict[str, object] = {}
    current_list: str | None = None
    for line in text[4:end].splitlines():
        if line.startswith("  - ") and current_list:
            data.setdefault(current_list, [])
            assert isinstance(data[current_list], list)
            data[current_list].append(line[4:].strip())
            continue
        if ":" not in line:
            continue
        key, value = line.split(":", 1)
        key, value = key.strip(), value.strip()
        current_list = key if value == "" else None
        data[key] = [] if value == "" else value
    return data


def _available_cli_commands() -> set[str]:
    completed = subprocess.run(
        [sys.executable, str(ROOT / "tools/cms.py"), "--help"],
        cwd=ROOT,
        text=True,
        capture_output=True,
        timeout=20,
    )
    text = completed.stdout + completed.stderr
    match = re.search(r"\{([^}]+)\}", text)
    return set(match.group(1).split(",")) if match else set()


def validate(mode: str = "fast") -> ValidationReport:
    report = ValidationReport(VALIDATOR_ID, DOMAIN, mode)

    for rel in CRITICAL_PAGES:
        report.checked()
        if not (ROOT / rel).is_file():
            report.add("DOC-001", "Page critique absente", path=rel)

    markdown: list[Path] = []
    root_readme = ROOT / "README.md"
    if root_readme.is_file():
        markdown.append(root_readme)
    if (ROOT / "docs").exists():
        markdown.extend(sorted((ROOT / "docs").rglob("*.md")))
    for path in markdown:
        rel = path.relative_to(ROOT).as_posix()
        text = path.read_text(encoding="utf-8", errors="ignore")
        requires_front_matter = path != root_readme
        meta = _front_matter(text) if requires_front_matter else None
        report.checked()
        if requires_front_matter and meta is None:
            report.add("DOC-003", "Front matter absent ou invalide", path=rel)
        elif meta is not None:
            missing = sorted(REQUIRED_FRONT_MATTER - set(meta))
            if missing:
                report.add("DOC-003", "Clés de front matter absentes", path=rel, missing=missing)
            source_type = str(meta.get("source_of_truth", ""))
            if source_type == "mixed":
                report.add("DOC-007", "Source canonique trop vague", path=rel)
            elif source_type in ALLOWED_SOURCE_TYPES:
                source_paths = meta.get("source_paths", [])
                if source_paths and not isinstance(source_paths, list):
                    report.add("DOC-008", "source_paths doit être une liste", path=rel)
            if meta.get("generated") == "true" and not meta.get("generator"):
                report.add("DOC-004", "Page générée sans générateur déclaré", path=rel)

        for raw in LINK_RE.findall(text):
            target = raw.split("#", 1)[0].strip()
            if not target or target.startswith(("http://", "https://", "mailto:", "#")):
                continue
            report.checked()
            resolved = (path.parent / target).resolve()
            if not resolved.exists():
                report.add("DOC-002", "Lien local cassé", path=rel, target=target)

    for legacy in LEGACY_DIRS:
        report.checked()
        if (ROOT / legacy).exists():
            report.add("DOC-005", "Ancien espace documentaire présent", path=legacy)

    for rel, snippet, expected in ADMIN_DOC_VIEWER_REQUIRED_SNIPPETS:
        report.checked()
        source_path = ROOT / rel
        if not source_path.is_file():
            report.add("DOC-009", "Source du viewer Docs absente", path=rel, expected=expected)
            continue
        if snippet not in source_path.read_text(encoding="utf-8", errors="ignore"):
            report.add("DOC-009", "Protection des liens Markdown du viewer Docs incomplète", path=rel, expected=expected)

    available = _available_cli_commands()
    report.checked()
    if not available:
        report.add("DOC-006", "Impossible d’inventorier les commandes CLI")
    for path in markdown:
        rel = path.relative_to(ROOT).as_posix()
        text = path.read_text(encoding="utf-8", errors="ignore")
        for command, action in CLI_RE.findall(text):
            report.checked()
            if command not in available:
                report.add("DOC-006", "Commande CLI documentée inexistante", path=rel, command=command)
            elif command == "docs" and action and action not in ALLOWED_DOC_ACTIONS:
                report.add("DOC-006", "Sous-commande docs inexistante", path=rel, command=f"docs {action}")

    return report
