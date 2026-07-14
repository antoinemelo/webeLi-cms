#!/usr/bin/env python3
"""Orchestrateur unique de qualification DEC CMS.

Codes de retour:
- 0: toutes les étapes requises ont réussi;
- 1: au moins une étape a échoué;
- 2: aucune erreur, mais au moins une étape requise n'a pas pu être exécutée;
- 3: erreur interne de l'orchestrateur.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import shlex
import shutil
import signal
import subprocess
import sys
import tempfile
import time
import zipfile
from dataclasses import asdict, dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Callable

from tools.python.cms.runtime import resolve_php_binary
from tools.python.lib.change_cache import fingerprint_paths, read_success, write_success
from tools.python.lib.release_metadata import load_release_metadata
from tools.python.qualification.omnichannel_gate import validate_report_file
from tools.python.qualification.usability_commerce_gate import validate_report_files as validate_usability_reports

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
REPORT_DIR = ROOT / "storage" / "qualification"
CACHE_DIR = REPORT_DIR / "cache"
USE_CACHE = True

FRONTEND_BUILD_INPUTS = (
    "frontend/admin-vue/package.json",
    "frontend/admin-vue/package-lock.json",
    "frontend/admin-vue/tsconfig.json",
    "frontend/admin-vue/vite.config.ts",
    "frontend/admin-vue/index.html",
    "frontend/admin-vue/src",
)

E2E_INPUTS = (
    "backend/config",
    "backend/public",
    "backend/routes",
    "backend/src",
    "database",
    "frontend/admin-vue/package.json",
    "frontend/admin-vue/package-lock.json",
    "frontend/admin-vue/playwright.config.ts",
    "frontend/admin-vue/src",
    "frontend/admin-vue/tests/e2e",
    "tools/cms.py",
    "tools/python/cms",
    "tools/python/commands/e2e.py",
    "tools/python/operations/database",
    "tools/python/operations/testing/run_playwright_e2e.py",
    "tools/python/qualification/omnichannel_gate.py",
    "tools/python/qualification/usability_commerce_gate.py",
    "docs/evaluation/machine-readable/usability-commerce-foundations.json",
    "tools/python/qualification/payment_provider_gate.py",
    "tools/python/qualification/inventory_ledger_gate.py",
    "tools/python/qualification/reservation_availability_gate.py",
    "tools/python/qualification/bundle_stock_strategy_gate.py",
    "tools/python/qualification/crm_guest_order_gate.py",
)


def _display_path(path: Path) -> str:
    try:
        return path.relative_to(ROOT).as_posix()
    except ValueError:
        return str(path)


def _as_text(value: object) -> str:
    """Normalise les sorties subprocess en texte exploitable dans les rapports.

    subprocess.TimeoutExpired peut exposer stdout/stderr sous forme de bytes,
    même lorsque la commande a été lancée avec text=True. La qualification doit
    alors afficher l'erreur initiale sans provoquer une erreur interne.
    """
    if value is None:
        return ""
    if isinstance(value, bytes):
        return value.decode("utf-8", errors="replace")
    return str(value)


@dataclass
class Result:
    id: str
    label: str
    status: str
    duration_ms: int
    command: list[str]
    returncode: int | None
    stdout: str
    stderr: str
    reason: str = ""


@dataclass(frozen=True)
class Step:
    id: str
    label: str
    profiles: tuple[str, ...]
    command: tuple[str, ...] = ()
    cwd: str = "."
    executables: tuple[str, ...] = ()
    files: tuple[str, ...] = ()
    env_vars: tuple[str, ...] = ()
    timeout: int = 900
    action: Callable[[], tuple[int, str, str]] | None = None
    available_in: tuple[str, ...] = ("source",)
    critical: bool = True


def _git_commit() -> str:
    proc = subprocess.run(["git", "rev-parse", "HEAD"], cwd=ROOT, text=True, capture_output=True, timeout=10)
    return proc.stdout.strip() if proc.returncode == 0 else "unknown"


def _technical_version() -> str:
    try:
        return load_release_metadata(ROOT / "config" / "release.json").technical_version
    except Exception:
        return "unknown"


def _sha256(path: Path) -> str | None:
    if not path.is_file():
        return None
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _artifact_hashes() -> dict[str, str]:
    artifacts = {
        "admin_manifest": ROOT / "admin-app/.vite/manifest.json",
        "admin_api_contract": ROOT / "docs/reference/contracts/admin-api-v1/admin.maintenance.v1.json",
        "public_openapi_json": ROOT / "docs/reference/contracts/public-api/openapi.v1.json",
        "public_openapi_yaml": ROOT / "docs/reference/contracts/public-api/openapi.v1.yaml",
        "sdk_openapi_types": ROOT / "packages/amcms-client/src/generated/openapi-types.ts",
        "qualification_performance": ROOT / "storage/qualification/performance/latest.json",
        "qualification_omnichannel": ROOT / "storage/qualification/omnichannel/latest.json",
        "qualification_usability": ROOT / "storage/qualification/usability/latest.json",
        "usability_commerce_foundations": ROOT / "docs/evaluation/machine-readable/usability-commerce-foundations.json",
        "payment_provider_interchangeability": ROOT / "docs/evaluation/machine-readable/sale-payment-provider-interchangeability.json",
        "inventory_ledger": ROOT / "docs/evaluation/machine-readable/sale-inventory-ledger.json",
        "stock_reconstruction_m6": ROOT / "docs/evaluation/machine-readable/sale-stock-reconstruction-m6.json",
        "customer_identity_m7": ROOT / "docs/evaluation/machine-readable/customer-identity-bridges-m7.json",
        "crm_event_activities_m7": ROOT / "docs/evaluation/machine-readable/crm-event-activities-m7.json",
        "crm_guest_order_m7": ROOT / "docs/evaluation/machine-readable/crm-guest-order-gate-m7.json",
        "reservation_availability": ROOT / "docs/evaluation/machine-readable/sale-reservations-availability.json",
        "bundle_stock_strategies": ROOT / "docs/evaluation/machine-readable/bundle-stock-strategies.json",
    }
    latest = _latest_archive()
    if latest is not None:
        artifacts["latest_release_archive"] = latest
    return {name: digest for name, path in artifacts.items() if (digest := _sha256(path)) is not None}


def _gate_matrix() -> list[dict[str, object]]:
    return [
        {"requirement": "validation statique", "source_steps": ["python-lint", "php-lint", "validate-core"], "release_commands": ["tools/cms.py validate"]},
        {"requirement": "tests PHP/Python/TypeScript", "source_steps": ["tests", "frontend-build"], "release_commands": []},
        {"requirement": "build back-office", "source_steps": ["frontend-build"], "release_commands": []},
        {"requirement": "reconstruction from scratch", "source_steps": ["browser-e2e", "performance-baseline"], "release_commands": ["tools/cms.py rebuild"]},
        {"requirement": "smoke HTTP et E2E", "source_steps": ["browser-e2e", "fresh-install"], "release_commands": ["tools/cms.py smoke"]},
        {"requirement": "gate E2E omnicanale storefront/POS", "source_steps": ["browser-e2e"], "release_commands": ["tools/cms.py e2e --use-built-assets --omnichannel-only"]},
        {"requirement": "gate utilisabilité Commerce M5-M7", "source_steps": ["commerce-usability-gate", "browser-e2e"], "release_commands": ["tools/python/qualification/usability_commerce_gate.py", "tools/cms.py e2e --use-built-assets --usability-only"]},
        {"requirement": "gate M5 interchangeabilité providers", "source_steps": ["payment-provider-gate", "browser-e2e"], "release_commands": ["tools/python/qualification/payment_provider_gate.py"]},
        {"requirement": "gate M6 ledger stock Sale", "source_steps": ["inventory-ledger-gate", "browser-e2e"], "release_commands": ["tools/python/qualification/inventory_ledger_gate.py"]},
        {"requirement": "gate M6.5 reconstruction et réconciliation", "source_steps": ["stock-reconstruction-gate", "backup-restore", "browser-e2e"], "release_commands": ["tools/python/qualification/stock_reconstruction_gate.py", "tools/cms.py inventory reconcile"]},
        {"requirement": "gate M7.1 identité client et dédoublonnage", "source_steps": ["customer-identity-gate", "browser-e2e"], "release_commands": ["tools/python/qualification/customer_identity_gate.py"]},
        {"requirement": "gate M7.2 activités CRM par événements", "source_steps": ["crm-event-activity-gate", "browser-e2e"], "release_commands": ["tools/python/qualification/crm_event_activity_gate.py"]},
        {"requirement": "gate M7.3 segmentation et consentements", "source_steps": ["crm-segmentation-consent-gate", "browser-e2e"], "release_commands": ["tools/python/qualification/crm_segmentation_consent_gate.py"]},
        {"requirement": "gate M7.4 commande invitée et CRM", "source_steps": ["crm-guest-order-gate", "browser-e2e"], "release_commands": ["tools/python/qualification/crm_guest_order_gate.py"]},
        {"requirement": "gate M6.2 réservations et disponibilité", "source_steps": ["reservation-availability-gate", "browser-e2e"], "release_commands": ["tools/python/qualification/reservation_availability_gate.py", "backend/bin/console worker:reservations"]},
        {"requirement": "gate M6.3 bundles et stock composé", "source_steps": ["bundle-stock-strategy-gate", "browser-e2e"], "release_commands": ["tools/python/qualification/bundle_stock_strategy_gate.py"]},
        {"requirement": "catalogue, panier, commande, paiement local, stock", "source_steps": ["tests", "performance-baseline"], "release_commands": []},
        {"requirement": "backup/restore et intégrité SQLite", "source_steps": ["backup-restore", "runtime-integrity"], "release_commands": ["tools/cms.py backup", "tools/cms.py backup --restore"]},
        {"requirement": "documentation OpenAPI SDK", "source_steps": ["docs-generate", "docs-check", "validate-core"], "release_commands": ["tools/cms.py docs check"]},
        {"requirement": "audit dépendances", "source_steps": ["php-dependencies", "frontend-dependencies"], "release_commands": []},
        {"requirement": "baseline performance", "source_steps": ["performance-baseline"], "release_commands": []},
    ]


def _latest_archive() -> Path | None:
    archives = sorted((ROOT / "storage" / "exports").glob("*/*.zip"), key=lambda p: p.stat().st_mtime, reverse=True)
    return archives[0] if archives else None


def _fresh_install_smoke() -> tuple[int, str, str]:
    archive = _latest_archive()
    if archive is None:
        return 1, "", "Aucune archive de release disponible pour l'installation neuve."
    try:
        with tempfile.TemporaryDirectory(prefix="dec-cms-install-") as tmp:
            destination = Path(tmp)
            with zipfile.ZipFile(archive) as zf:
                zf.extractall(destination)
            roots = [p for p in destination.iterdir() if p.is_dir()]
            install_root = roots[0] if len(roots) == 1 and (roots[0] / "backend").is_dir() else destination
            command = [sys.executable, str(ROOT / "tools/python/operations/deployment/d5_smoke_test_release_structure.py"), "--root", str(install_root)]
            proc = subprocess.run(command, cwd=ROOT, text=True, capture_output=True, timeout=120)
            return proc.returncode, proc.stdout, proc.stderr
    except (OSError, zipfile.BadZipFile, subprocess.TimeoutExpired) as exc:
        return 1, "", str(exc)


def steps() -> tuple[Step, ...]:
    py = sys.executable
    cms = str(ROOT / "tools/cms.py")
    all_profiles = ("quick", "complete", "release")
    return (
        Step("environment", "Environnement", all_profiles, action=_environment_check),
        Step("python-lint", "Syntaxe Python", all_profiles, (py, "-m", "compileall", "-q", "tools/python"), timeout=120),
        Step("php-lint", "Syntaxe PHP", ("complete", "release"), action=_php_lint, executables=("php",)),
        Step("validate-fast", "Validateurs structurels rapides", ("quick",), (py, cms, "validate", "--category", "configuration", "--category", "database", "--category", "content", "--category", "permissions", "--category", "api", "--category", "operations", "--category", "security", "--category", "documentation", "--category", "shared"), timeout=180),
        Step(
            "tests",
            "Tests unitaires et intégration",
            ("complete", "release"),
            (py, cms, "test", "--timeout", "400", "--target-duration", "200"),
            executables=("php",),
            timeout=480,
        ),
        Step("validate-core", "Validateurs structurels", ("complete", "release"), (py, cms, "validate", "--full", "--category", "configuration", "--category", "database", "--category", "content", "--category", "permissions", "--category", "api", "--category", "operations", "--category", "security", "--category", "documentation", "--category", "shared"), timeout=600),
        Step("runtime-integrity", "Intégrité runtime", ("complete", "release"), (py, cms, "validate", "--full", "--validator", "RUNTIME_INTEGRITY"), timeout=300),
        Step("backup-restore", "Sauvegarde/restauration", ("complete", "release"), (py, cms, "validate", "--with-slow", "--validator", "BACKUP_RESTORE_ROUNDTRIP"), timeout=600),
        Step(
            "php-dependencies",
            "Dépendances PHP",
            ("complete", "release"),
            cwd="backend",
            executables=("composer",),
            files=("backend/composer.json", "backend/composer.lock"),
            action=_php_dependencies_check,
            timeout=180,
        ),
        Step(
            "frontend-dependencies",
            "Dépendances frontend",
            ("complete", "release"),
            cwd="frontend/admin-vue",
            executables=("node", "npm"),
            files=("frontend/admin-vue/package.json", "frontend/admin-vue/package-lock.json"),
            action=_frontend_dependencies_check,
            timeout=90,
        ),
        Step(
            "frontend-build",
            "Build frontend",
            ("complete", "release"),
            ("npm", "run", "build"),
            cwd="frontend/admin-vue",
            executables=("node", "npm"),
            files=(
                "frontend/admin-vue/package.json",
                "frontend/admin-vue/node_modules/bootstrap/package.json",
                "frontend/admin-vue/node_modules/vue-tsc/bin/vue-tsc.js",
                "frontend/admin-vue/node_modules/vite/bin/vite.js",
            ),
            action=_frontend_build_check,
            timeout=600,
        ),
        Step(
            "inventory-ledger-gate",
            "Gate M6 ledger stock Sale",
            ("complete", "release"),
            (py, "tools/python/qualification/inventory_ledger_gate.py"),
            files=(
                "docs/evaluation/machine-readable/sale-inventory-ledger.json",
                "tools/python/qualification/inventory_ledger_gate.py",
                "tools/php/tests/unit/sale_inventory_reconciliation_test.php",
                "frontend/admin-vue/tests/e2e/sale-stock-ledger.spec.ts",
            ),
            timeout=60,
        ),
        Step(
            "reservation-availability-gate",
            "Gate M6.2 réservations et disponibilité",
            ("complete", "release"),
            (py, "tools/python/qualification/reservation_availability_gate.py"),
            files=(
                "docs/evaluation/machine-readable/sale-reservations-availability.json",
                "tools/python/qualification/reservation_availability_gate.py",
                "tools/php/tests/unit/sale_reservation_lifecycle_test.php",
                "frontend/admin-vue/tests/e2e/sale-reservations.spec.ts",
            ),
            timeout=60,
        ),
        Step(
            "stock-reconstruction-gate",
            "Gate M6.5 reconstruction et réconciliation stock",
            ("complete", "release"),
            (py, "tools/python/qualification/stock_reconstruction_gate.py"),
            files=(
                "docs/evaluation/machine-readable/sale-stock-reconstruction-m6.json",
                "tools/python/qualification/stock_reconstruction_gate.py",
                "tools/php/tests/unit/sale_stock_reconstruction_scenario_test.php",
                "tools/php/tests/unit/sale_inventory_reconciliation_test.php",
                "frontend/admin-vue/src/views/modules/SaleOperationsView.vue",
                "frontend/admin-vue/tests/e2e/sale-stock-reconstruction.spec.ts",
            ),
            timeout=60,
        ),
        Step(
            "customer-identity-gate",
            "Gate M7.1 identité client et dédoublonnage",
            ("complete", "release"),
            (py, "tools/python/qualification/customer_identity_gate.py"),
            files=("docs/evaluation/machine-readable/customer-identity-bridges-m7.json","tools/python/qualification/customer_identity_gate.py","tools/php/tests/unit/sale_customer_accounts_test.php","frontend/admin-vue/tests/e2e/sale-identity-review.spec.ts"),
            timeout=60,
        ),
        Step(
            "crm-event-activity-gate",
            "Gate M7.2 activités CRM par événements",
            ("complete", "release"),
            (py, "tools/python/qualification/crm_event_activity_gate.py"),
            files=("docs/evaluation/machine-readable/crm-event-activities-m7.json","tools/python/qualification/crm_event_activity_gate.py","tools/php/tests/unit/sale_crm_activity_projection_test.php","frontend/admin-vue/src/views/modules/business/RelationTimeline.vue","frontend/admin-vue/tests/e2e/business-crm-smoke.spec.ts"),
            timeout=60,
        ),
        Step(
            "crm-segmentation-consent-gate",
            "Gate M7.3 segmentation et consentements",
            ("complete", "release"),
            (py, "tools/python/qualification/crm_segmentation_consent_gate.py"),
            files=("docs/evaluation/machine-readable/crm-segmentation-consents-m7.json","tools/python/qualification/crm_segmentation_consent_gate.py","tools/php/tests/unit/business_segmentation_consent_test.php","frontend/admin-vue/src/views/modules/business/BusinessSegmentsPanel.vue","frontend/admin-vue/tests/e2e/business-crm-smoke.spec.ts"),
            timeout=60,
        ),
        Step(
            "crm-guest-order-gate",
            "Gate M7.4 commande invitée et CRM",
            ("complete", "release"),
            (py, "tools/python/qualification/crm_guest_order_gate.py"),
            files=("docs/evaluation/machine-readable/crm-guest-order-gate-m7.json","tools/python/qualification/crm_guest_order_gate.py","tools/php/tests/unit/sale_customer_accounts_test.php","tools/php/tests/unit/sale_crm_activity_projection_test.php","tools/php/tests/unit/business_segmentation_consent_test.php","frontend/admin-vue/tests/e2e/sale-identity-review.spec.ts","frontend/admin-vue/tests/e2e/business-crm-smoke.spec.ts"),
            timeout=60,
        ),
        Step(
            "bundle-stock-strategy-gate",
            "Gate M6.3 bundles et stock composé",
            ("complete", "release"),
            (py, "tools/python/qualification/bundle_stock_strategy_gate.py"),
            files=(
                "docs/evaluation/machine-readable/bundle-stock-strategies.json",
                "tools/python/qualification/bundle_stock_strategy_gate.py",
                "tools/php/tests/unit/business_bundle_stock_strategies_test.php",
                "tools/php/tests/unit/sale_inventory_service_test.php",
                "tools/php/tests/unit/sale_internal_sales_test.php",
            ),
            timeout=60,
        ),
        Step(
            "payment-provider-gate",
            "Gate M5 interchangeabilité providers",
            ("complete", "release"),
            (py, "tools/python/qualification/payment_provider_gate.py"),
            files=(
                "docs/evaluation/machine-readable/sale-payment-provider-interchangeability.json",
                "tools/python/qualification/payment_provider_gate.py",
                "tools/php/tests/unit/sale_payment_provider_interchangeability_test.php",
                "frontend/admin-vue/tests/e2e/payment-provider-interchangeability.spec.ts",
            ),
            timeout=60,
        ),
        Step(
            "commerce-usability-gate",
            "Gate d’utilisabilité Commerce M5–M7",
            ("complete", "release"),
            (py, "tools/python/qualification/usability_commerce_gate.py", "--static-only"),
            files=(
                "docs/evaluation/machine-readable/usability-commerce-foundations.json",
                "docs/evaluation/usability-commerce-foundations.md",
                "tools/python/qualification/usability_commerce_gate.py",
                "frontend/admin-vue/tests/e2e/commerce-usability-gate.spec.ts",
            ),
            timeout=60,
        ),
        Step(
            "browser-e2e",
            "Tests navigateur Playwright isolés",
            ("release",),
            (py, cms, "e2e", "--use-built-assets"),
            executables=("php", "node"),
            files=(
                "frontend/admin-vue/playwright.config.ts",
                "frontend/admin-vue/tests/e2e/webhook-ping-persistence.spec.ts",
                "frontend/admin-vue/node_modules/@playwright/test/cli.js",
                "tools/python/operations/testing/run_playwright_e2e.py",
            ),
            action=_browser_e2e_check,
            timeout=1200,
        ),
        Step(
            "performance-baseline",
            "Baseline performance M0",
            ("release",),
            (py, "tools/python/qualification/performance_baseline.py", "--use-built-assets"),
            executables=("php",),
            files=("tools/python/qualification/performance_baseline.py", "admin-app/.vite/manifest.json"),
            action=_performance_baseline_check,
            timeout=900,
        ),
        Step("docs-generate", "Génération documentaire", ("complete", "release"), (py, cms, "docs", "generate"), timeout=300),
        Step("docs-check", "Contrôle documentaire", ("complete", "release"), (py, cms, "docs", "check"), timeout=300),
        Step("static-export", "Export statique à blanc", ("complete", "release"), (py, cms, "--dry-run", "export"), executables=("php",), files=("backend/bin/console",), timeout=300),
        Step("preflight", "Préflight de production", ("release",), (py, "tools/python/operations/deployment/d1_preflight_local.py"), timeout=300),
        Step("package", "Création de la release", ("release",), (py, "tools/python/operations/deployment/d2_package_release.py"), timeout=600),
        Step("verify-archive", "Vérification de l'archive", ("release",), action=_verify_latest_archive, available_in=("source", "release")),
        Step("fresh-install", "Installation neuve et smoke test", ("release",), action=_fresh_install_smoke, available_in=("source", "release")),
    )


def _environment_check() -> tuple[int, str, str]:
    try:
        php = resolve_php_binary()
        php_error = ""
    except (FileNotFoundError, PermissionError) as exc:
        php = ""
        php_error = str(exc)
    commands = {
        "python": [sys.executable, "--version"],
        "php": [php, "-v"] if php else [],
        "node": ["node", "--version"],
        "npm": ["npm", "--version"],
    }
    lines: list[str] = []
    missing: list[str] = []
    for name, command in commands.items():
        if not command:
            missing.append(name)
            lines.append(f"{name}: {php_error}")
            continue
        executable = command[0]
        if executable != sys.executable and shutil.which(executable) is None:
            missing.append(name)
            lines.append(f"{name}: absent")
            continue
        proc = subprocess.run(command, cwd=ROOT, text=True, capture_output=True, timeout=15)
        text = (proc.stdout or proc.stderr).strip().splitlines()
        lines.append(f"{name}: {text[0] if text else 'détecté'}")
    suffix = "\nDépendances optionnelles absentes: " + ", ".join(missing) if missing else ""
    return 0, "\n".join(lines) + suffix, ""



def _playwright_chromium_status() -> tuple[int, str, str]:
    """Vérifie rapidement que Chromium Playwright est installé localement.

    Mettre à jour @playwright/test peut changer le chemin attendu dans
    ~/.cache/ms-playwright. La qualification ne télécharge pas de navigateur
    automatiquement, car cette opération est longue et dépend du réseau.
    """
    frontend = ROOT / "frontend/admin-vue"
    cli = frontend / "node_modules/@playwright/test/cli.js"
    if not cli.is_file():
        return 2, "", "Playwright absent. Exécutez d'abord: cd frontend/admin-vue && npm ci"

    probe = subprocess.run(
        [
            "node",
            "-e",
            "const { chromium } = require('@playwright/test'); process.stdout.write(chromium.executablePath());",
        ],
        cwd=frontend,
        text=True,
        capture_output=True,
        timeout=30,
    )
    if probe.returncode != 0:
        detail = "\n".join(part for part in (_as_text(probe.stdout).strip(), _as_text(probe.stderr).strip()) if part)
        return probe.returncode, detail, "Impossible de résoudre le chemin Chromium de Playwright."

    executable = Path(_as_text(probe.stdout).strip())
    if not executable.is_file():
        return (
            2,
            f"Chromium Playwright attendu: {executable}",
            "Navigateur Chromium Playwright absent. "
            "Installez-le une fois par machine après npm ci: "
            "python3 tools/cms.py e2e --install-browser",
        )
    return 0, f"Chromium Playwright détecté: {executable}", ""


def _browser_e2e_check() -> tuple[int, str, str]:
    """Exécute les E2E seulement si le navigateur Playwright est disponible."""
    browser_code, browser_stdout, browser_stderr = _playwright_chromium_status()
    if browser_code != 0:
        return browser_code, browser_stdout, browser_stderr

    fingerprint = _e2e_fingerprint()
    cache_file = CACHE_DIR / "browser-e2e.json"
    report_file = ROOT / "storage/qualification/omnichannel/latest.json"
    usability_report = ROOT / "storage/qualification/usability/latest.json"
    _report, report_errors = validate_report_file(report_file, ROOT)
    usability_errors = validate_usability_reports(runtime_path=usability_report, root=ROOT)
    if USE_CACHE and not report_errors and not usability_errors and read_success(cache_file, fingerprint) is not None:
        return (
            0,
            browser_stdout
            + "\nCache qualification: E2E Playwright inchangés, dernier succès réutilisé."
            + f"\nEmpreinte: {fingerprint}"
            + f"\nGate omnicanale: {_display_path(report_file)}"
            + f"\nGate utilisabilité: {_display_path(usability_report)}",
            "",
        )

    command = [sys.executable, str(ROOT / "tools/cms.py"), "e2e", "--use-built-assets"]
    try:
        returncode, stdout, stderr = _execute_bounded(command, cwd=ROOT, timeout=1200)
    except subprocess.TimeoutExpired as exc:
        stdout = _as_text(getattr(exc, "stdout", None) or getattr(exc, "output", None))
        stderr = _as_text(getattr(exc, "stderr", None))
        return 124, stdout, stderr + "\nTimeout après 1200s"
    if returncode == 0:
        write_success(cache_file, fingerprint=fingerprint, step_id="browser-e2e", command=command)
    return returncode, browser_stdout + "\n" + stdout, stderr


def _frontend_build_check() -> tuple[int, str, str]:
    command = ["npm", "run", "build"]
    manifest = ROOT / "admin-app/.vite/manifest.json"
    fingerprint = fingerprint_paths(ROOT, FRONTEND_BUILD_INPUTS, extra=("frontend-build-v1",))
    cache_file = CACHE_DIR / "frontend-build.json"
    if USE_CACHE and manifest.is_file() and read_success(cache_file, fingerprint) is not None:
        return (
            0,
            "Cache qualification: build frontend inchangé, assets existants réutilisés."
            + f"\nManifest: {_display_path(manifest)}"
            + f"\nEmpreinte: {fingerprint}",
            "",
        )

    try:
        returncode, stdout, stderr = _execute_bounded(command, cwd=ROOT / "frontend/admin-vue", timeout=600)
    except subprocess.TimeoutExpired as exc:
        stdout = _as_text(getattr(exc, "stdout", None) or getattr(exc, "output", None))
        stderr = _as_text(getattr(exc, "stderr", None))
        return 124, stdout, stderr + "\nTimeout après 600s"
    if returncode == 0 and manifest.is_file():
        write_success(cache_file, fingerprint=fingerprint, step_id="frontend-build", command=command)
    return returncode, stdout, stderr


def _frontend_dependencies_check() -> tuple[int, str, str]:
    """Vérifie le lockfile et l'audit sécurité sans modifier node_modules.

    La qualification ne doit pas lancer npm ci automatiquement : c'est lent,
    dépendant du réseau, et cela peut bloquer tout le profil complete/release.
    Le contrôle P0-06 porte ici sur l'absence de vulnérabilités high/critical
    dans le graphe verrouillé. Le build frontend, exécuté juste après, vérifie
    séparément que les dépendances locales installées sont réellement utilisables.
    """
    frontend = ROOT / "frontend/admin-vue"
    lockfile = frontend / "package-lock.json"
    lock_text = lockfile.read_text(encoding="utf-8")
    forbidden_registries = (
        "packages.applied-caas-gateway1.internal.api.openai.org",
        "artifactory/api/npm/npm-public",
    )
    leaked = [item for item in forbidden_registries if item in lock_text]
    if leaked:
        return (
            1,
            "",
            "package-lock.json contient un registre non public: " + ", ".join(leaked)
            + "\nRégénérez le lockfile avec un registre public puis relancez npm ci.",
        )

    audit_command = ["npm", "audit", "--audit-level=high"]
    try:
        audit_proc = subprocess.run(
            audit_command,
            cwd=frontend,
            text=True,
            capture_output=True,
            timeout=180,
        )
    except subprocess.TimeoutExpired as exc:
        stdout = _as_text(getattr(exc, "stdout", None) or getattr(exc, "output", None)).strip()
        stderr = _as_text(getattr(exc, "stderr", None)).strip()
        detail = "\n".join(part for part in (stdout, stderr) if part)
        return 124, detail, "npm audit --audit-level=high a dépassé 180s."

    output = "\n".join(part for part in (_as_text(audit_proc.stdout).strip(), _as_text(audit_proc.stderr).strip()) if part)
    if audit_proc.returncode != 0:
        return (
            audit_proc.returncode,
            output,
            "npm audit --audit-level=high a détecté au moins une vulnérabilité high/critical.",
        )

    return 0, "Audit sécurité frontend OK.\n$ npm audit --audit-level=high\n" + (output or "found 0 vulnerabilities"), ""


def _php_dependencies_check() -> tuple[int, str, str]:
    """Vérifie composer.json/lock et les advisories connues par Composer."""
    backend = ROOT / "backend"
    composer = shutil.which("composer")
    if composer is None:
        return 2, "", "Composer absent: impossible d'auditer les dépendances PHP."

    validate = subprocess.run(
        [composer, "validate", "--strict", "--no-check-publish", "--no-interaction"],
        cwd=backend,
        text=True,
        capture_output=True,
        timeout=60,
    )
    validate_output = "\n".join(part for part in (_as_text(validate.stdout).strip(), _as_text(validate.stderr).strip()) if part)
    if validate.returncode != 0:
        return validate.returncode, validate_output, "composer validate --strict a échoué."

    try:
        audit = subprocess.run(
            [composer, "audit", "--locked", "--no-interaction", "--format=json"],
            cwd=backend,
            text=True,
            capture_output=True,
            timeout=120,
        )
    except subprocess.TimeoutExpired as exc:
        stdout = _as_text(getattr(exc, "stdout", None) or getattr(exc, "output", None)).strip()
        stderr = _as_text(getattr(exc, "stderr", None)).strip()
        return 124, "\n".join(part for part in (stdout, stderr) if part), "composer audit --locked a dépassé 120s."

    output = "\n".join(part for part in (_as_text(audit.stdout).strip(), _as_text(audit.stderr).strip()) if part)
    if audit.returncode != 0:
        return audit.returncode, output, "composer audit --locked a détecté au moins une advisory non acceptée."
    return 0, "Audit sécurité PHP OK.\n$ composer validate --strict --no-check-publish\n$ composer audit --locked --format=json\n" + (output or "{}"), ""


def _performance_baseline_check() -> tuple[int, str, str]:
    command = [
        sys.executable,
        str(ROOT / "tools/python/qualification/performance_baseline.py"),
        "--use-built-assets",
        "--report",
        str(ROOT / "storage/qualification/performance/latest.json"),
    ]
    try:
        returncode, stdout, stderr = _execute_bounded(command, cwd=ROOT, timeout=900)
    except subprocess.TimeoutExpired as exc:
        stdout = _as_text(getattr(exc, "stdout", None) or getattr(exc, "output", None))
        stderr = _as_text(getattr(exc, "stderr", None))
        return 124, stdout, stderr + "\nTimeout après 900s"
    return returncode, stdout, stderr


def _e2e_fingerprint() -> str:
    build_cache = CACHE_DIR / "frontend-build.json"
    try:
        build_payload = json.loads(build_cache.read_text(encoding="utf-8"))
        build_fingerprint = str(build_payload.get("fingerprint", ""))
    except (FileNotFoundError, json.JSONDecodeError, OSError):
        build_fingerprint = ""
    return fingerprint_paths(ROOT, E2E_INPUTS, extra=("browser-e2e-v2", build_fingerprint))


def _php_lint() -> tuple[int, str, str]:
    try:
        php = resolve_php_binary()
    except (FileNotFoundError, PermissionError) as exc:
        return 2, "", str(exc)
    files = sorted((ROOT / "backend").rglob("*.php")) + sorted((ROOT / "tools/php").rglob("*.php"))
    outputs: list[str] = []
    for path in files:
        proc = subprocess.run([php, "-l", str(path)], cwd=ROOT, text=True, capture_output=True, timeout=20)
        if proc.returncode != 0:
            return proc.returncode, "\n".join(outputs), proc.stderr or proc.stdout
        outputs.append(path.relative_to(ROOT).as_posix())
    return 0, f"PHP utilisé: {php}\n{len(files)} fichiers PHP valides", ""


def _verify_latest_archive() -> tuple[int, str, str]:
    archive = _latest_archive()
    if archive is None:
        return 1, "", "Aucune archive de release produite."
    command = [sys.executable, str(ROOT / "tools/python/operations/deployment/d4_verify_release_archive.py"), "--archive", str(archive)]
    proc = subprocess.run(command, cwd=ROOT, text=True, capture_output=True, timeout=300)
    return proc.returncode, proc.stdout, proc.stderr


def _missing_requirements(step: Step) -> list[str]:
    missing: list[str] = []
    for executable in step.executables:
        if executable == "php":
            try:
                resolve_php_binary()
            except (FileNotFoundError, PermissionError) as exc:
                missing.append(str(exc))
            continue
        if shutil.which(executable) is None:
            missing.append(f"exécutable {executable}")
    for relative in step.files:
        if not (ROOT / relative).exists():
            missing.append(f"fichier {relative}")
    for name in step.env_vars:
        if not os.getenv(name):
            missing.append(f"variable {name}")
    return missing


def _execute_bounded(command: list[str], *, cwd: Path, timeout: int) -> tuple[int, str, str]:
    """Exécute une commande bornée sans pipes héritables par les descendants."""
    with tempfile.TemporaryFile(mode="w+t", encoding="utf-8") as stdout_file, tempfile.TemporaryFile(mode="w+t", encoding="utf-8") as stderr_file:
        kwargs = {"cwd": cwd, "text": True, "stdout": stdout_file, "stderr": stderr_file}
        if os.name != "nt":
            kwargs["start_new_session"] = True
        proc = subprocess.Popen(command, **kwargs)
        timed_out = False
        try:
            returncode = proc.wait(timeout=timeout)
        except subprocess.TimeoutExpired:
            timed_out = True
            if os.name != "nt":
                os.killpg(proc.pid, signal.SIGKILL)
            else:
                proc.kill()
            try:
                proc.wait(timeout=10)
            except subprocess.TimeoutExpired:
                proc.kill()
                proc.wait()
            returncode = 124
        stdout_file.seek(0)
        stderr_file.seek(0)
        stdout = stdout_file.read()
        stderr = stderr_file.read()
        if timed_out:
            raise subprocess.TimeoutExpired(command, timeout, output=stdout, stderr=stderr)
        return returncode, stdout, stderr


def _run_step(step: Step) -> Result:
    missing = _missing_requirements(step)
    if missing:
        return Result(step.id, step.label, "skipped", 0, list(step.command), None, "", "", "Prérequis manquants: " + ", ".join(missing))
    start = time.monotonic()
    try:
        if step.action is not None:
            returncode, stdout, stderr = step.action()
        else:
            returncode, stdout, stderr = _execute_bounded(list(step.command), cwd=ROOT / step.cwd, timeout=step.timeout)
        status = "passed" if returncode == 0 else ("skipped" if returncode == 2 else "failed")
        reason = stderr.strip() if status == "skipped" else ""
        return Result(step.id, step.label, status, int((time.monotonic() - start) * 1000), list(step.command), returncode, stdout, stderr, reason)
    except FileNotFoundError as exc:
        return Result(step.id, step.label, "skipped", int((time.monotonic() - start) * 1000), list(step.command), None, "", "", str(exc))
    except subprocess.TimeoutExpired as exc:
        stdout = _as_text(getattr(exc, "stdout", None) or getattr(exc, "output", None))
        stderr = _as_text(getattr(exc, "stderr", None))
        return Result(step.id, step.label, "failed", int((time.monotonic() - start) * 1000), list(step.command), 124, stdout, stderr, f"Timeout après {step.timeout}s")
    except Exception as exc:  # noqa: BLE001
        return Result(step.id, step.label, "failed", int((time.monotonic() - start) * 1000), list(step.command), 3, "", _as_text(exc), "Erreur interne")


def _markdown(profile: str, results: list[Result], exit_code: int) -> str:
    labels = {0: "SUCCÈS", 1: "ÉCHEC", 2: "INCOMPLET", 3: "ERREUR INTERNE"}
    lines = ["# Rapport de qualification", "", f"- Profil : `{profile}`", f"- Résultat : **{labels[exit_code]}**", f"- Généré : `{datetime.now(timezone.utc).isoformat()}`", "", "| Étape | Statut | Durée |", "|---|---:|---:|"]
    for result in results:
        lines.append(f"| {result.label} | `{result.status}` | {result.duration_ms} ms |")
    for result in results:
        if result.status != "passed":
            lines.extend(["", f"## {result.label}", "", f"Statut : `{result.status}`", "", result.reason or _as_text(result.stderr).strip() or "Aucun détail."])
    return "\n".join(lines) + "\n"


def _exit_code(results: list[Result]) -> int:
    if any(result.status == "failed" for result in results):
        return 1
    if any(result.status == "skipped" for result in results):
        return 2
    return 0


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Qualification globale DEC CMS.")
    parser.add_argument("--profile", choices=("quick", "complete", "release"), default="complete")
    parser.add_argument("--json-report", default=str(REPORT_DIR / "latest.json"))
    parser.add_argument("--markdown-report", default=str(REPORT_DIR / "latest.md"))
    parser.add_argument("--no-reports", action="store_true")
    parser.add_argument("--continue-on-failure", action="store_true", help="Exécute les étapes restantes après un échec.")
    parser.add_argument("--no-cache", action="store_true", help="Force les étapes cache-aware (build frontend, E2E Playwright) à se réexécuter.")
    parser.add_argument("--list", action="store_true", help="Affiche le plan sans exécuter.")
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    global USE_CACHE
    args = parse_args(argv)
    USE_CACHE = not args.no_cache
    selected = [step for step in steps() if args.profile in step.profiles]
    if args.list:
        for step in selected:
            print(f"{step.id}: {step.label}")
        return 0
    results: list[Result] = []
    started_at = time.monotonic()
    print(f"Qualification DEC CMS — profil {args.profile}")
    print("=" * 72)
    for step in selected:
        print(f"\n[RUN] {step.label}", flush=True)
        if step.command:
            print("  " + " ".join(shlex.quote(x) for x in step.command), flush=True)
        result = _run_step(step)
        results.append(result)
        marker = {"passed": "OK", "failed": "FAILED", "skipped": "SKIPPED"}[result.status]
        print(f"[{marker}] {step.label} ({result.duration_ms} ms)")
        if result.status == "failed":
            detail = "\n".join(part for part in (_as_text(result.stdout).strip(), _as_text(result.stderr).strip()) if part)
        else:
            detail = result.reason
        if detail:
            print(detail, file=sys.stderr)
        if result.status == "failed" and not args.continue_on_failure:
            break
    code = _exit_code(results)
    payload = {
        "schema_version": 2,
        "profile": args.profile,
        "status": {0: "passed", 1: "failed", 2: "incomplete", 3: "internal-error"}[code],
        "exit_code": code,
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "duration_ms": int((time.monotonic() - started_at) * 1000),
        "version": _technical_version(),
        "commit": _git_commit(),
        "environment": {
            "python": sys.version.split()[0],
            "platform": sys.platform,
            "cwd": str(ROOT),
            "cache_enabled": USE_CACHE,
        },
        "commands": [result.command for result in results if result.command],
        "m0_gate": {
            "official_command": "python3 tools/cms.py qualify --profile release",
            "source_vs_release": _gate_matrix(),
            "step_availability": {step.id: list(step.available_in) for step in selected},
            "limits": [
                "Les tests source, le build frontend, Composer, npm et Playwright exigent le dépôt source complet.",
                "Une archive distribuée expose les contrôles autonomes smoke, validate, docs check et backup/restore.",
                "La baseline performance locale qualifie la machine courante; elle ne remplace pas un test de charge externe.",
            ],
        },
        "artifact_hashes": _artifact_hashes(),
        "results": [asdict(r) for r in results],
    }
    if not args.no_reports:
        json_path = Path(args.json_report)
        md_path = Path(args.markdown_report)
        if not json_path.is_absolute(): json_path = ROOT / json_path
        if not md_path.is_absolute(): md_path = ROOT / md_path
        json_path.parent.mkdir(parents=True, exist_ok=True)
        md_path.parent.mkdir(parents=True, exist_ok=True)
        json_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        md_path.write_text(_markdown(args.profile, results, code), encoding="utf-8")
        print(f"\nRapports: {_display_path(json_path)}, {_display_path(md_path)}")
    print(f"Résultat: {payload['status']} — code {code}")
    if code == 2:
        skipped = [result for result in results if result.status == "skipped"]
        if skipped:
            print("Qualification incomplète : une ou plusieurs étapes requises n'ont pas été exécutées.", file=sys.stderr)
            for result in skipped:
                explanation = result.reason or "prérequis indisponibles"
                print(f"- {result.label} : {explanation}", file=sys.stderr)
            print("Le code 2 signale une qualification incomplète, et non un succès.", file=sys.stderr)
    return code


if __name__ == "__main__":
    raise SystemExit(main())
