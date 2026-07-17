#!/usr/bin/env python3
"""Run Playwright against an isolated CMS rebuilt from native sources."""
from __future__ import annotations

import argparse
import json
import os
import secrets
import shutil
import socket
import sqlite3
import subprocess
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))
from tools.python.qualification.omnichannel_gate import validate_report_file
from tools.python.qualification.usability_commerce_gate import validate_report_files as validate_usability_reports
from tools.python.qualification.admin_convergence_gate import validate_report_files as validate_admin_convergence_reports

FRONTEND = ROOT / "frontend" / "admin-vue"
PLAYWRIGHT_CLI = FRONTEND / "node_modules" / "@playwright" / "test" / "cli.js"
OMNICHANNEL_REPORT = ROOT / "storage/qualification/omnichannel/latest.json"
USABILITY_REPORT = ROOT / "storage/qualification/usability/latest.json"
ADMIN_CONVERGENCE_REPORT = ROOT / "storage/qualification/admin-convergence/latest.json"


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Exécute les E2E Playwright sur une instance CMS isolée.")
    parser.add_argument("--install-browser", action="store_true", help="Installe le navigateur Chromium attendu par Playwright puis quitte.")
    parser.add_argument("--headed", action="store_true", help="Affiche Chromium pendant les tests.")
    parser.add_argument("--keep-instance", action="store_true", help="Conserve l’instance temporaire et affiche son chemin pour diagnostic.")
    parser.add_argument("--use-built-assets", action="store_true", help="Utilise les assets déjà compilés (réservé à la qualification après son étape de build).")
    parser.add_argument("--omnichannel-only", action="store_true", help="Exécute uniquement la gate storefront/POS.")
    parser.add_argument("--usability-only", action="store_true", help="Exécute uniquement la gate d’utilisabilité Commerce M5–M7.")
    parser.add_argument("--admin-convergence-only", action="store_true", help="Exécute uniquement la gate UX de convergence admin 38e.")
    parser.add_argument(
        "--spec",
        action="append",
        default=[],
        help="Exécute uniquement ce fichier sous frontend/admin-vue/tests/e2e; option répétable.",
    )
    return parser.parse_args()


def normalize_specs(values: list[str] | tuple[str, ...]) -> tuple[str, ...]:
    specs: list[str] = []
    for raw in values:
        name = str(raw).strip().replace("\\", "/")
        if name.startswith("tests/e2e/"):
            name = name.removeprefix("tests/e2e/")
        if not name.endswith(".spec.ts") or "/" in name or name in {".", ".."}:
            raise RuntimeError(f"Spec E2E invalide: {raw}")
        path = FRONTEND / "tests/e2e" / name
        if not path.is_file():
            raise RuntimeError(f"Spec E2E introuvable: tests/e2e/{name}")
        specs.append(f"tests/e2e/{name}")
    return tuple(dict.fromkeys(specs))


def source_commit(root: Path = ROOT) -> str:
    git = shutil.which("git")
    if git and (root / ".git").exists():
        completed = subprocess.run(
            [git, "rev-parse", "HEAD"], cwd=root, text=True, capture_output=True, timeout=15,
        )
        value = completed.stdout.strip().lower()
        if completed.returncode == 0 and 7 <= len(value) <= 40 and all(char in "0123456789abcdef" for char in value):
            return value
    manifest = root / "docs/evaluation/machine-readable/cms-evaluation-manifest.json"
    if manifest.is_file():
        try:
            value = str(json.loads(manifest.read_text(encoding="utf-8")).get("commit", "")).strip().lower()
        except (OSError, json.JSONDecodeError):
            value = ""
        if 7 <= len(value) <= 40 and all(char in "0123456789abcdef" for char in value):
            return value
    raise RuntimeError(
        "Commit source E2E indéterminable: fournissez E2E_BUILD_COMMIT ou conservez le manifeste d’évaluation."
    )


def require_executable(name: str) -> str:
    executable = shutil.which(name)
    if not executable:
        raise RuntimeError(f"Exécutable requis introuvable: {name}")
    return executable


def free_port() -> int:
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return int(sock.getsockname()[1])


def wait_for_http(url: str, process: subprocess.Popen[bytes], timeout: float = 30.0) -> None:
    deadline = time.monotonic() + timeout
    last_error = ""
    while time.monotonic() < deadline:
        if process.poll() is not None:
            raise RuntimeError(f"Le serveur PHP s’est arrêté avec le code {process.returncode}.")
        try:
            with urllib.request.urlopen(url, timeout=1.0) as response:
                if response.status < 500:
                    return
        except (urllib.error.URLError, TimeoutError, ConnectionError) as exc:
            last_error = str(exc)
        time.sleep(0.15)
    raise RuntimeError(f"Le serveur CMS n’est pas prêt après {timeout:.0f}s: {last_error}")


def copy_isolated_instance(destination: Path) -> None:
    ignored_names = {".git", "storage", "node_modules", "vendor", "test-results", "playwright-report", "__pycache__"}

    def ignore(_directory: str, names: list[str]) -> set[str]:
        return {name for name in names if name in ignored_names or name == ".env" or name.startswith(".env.")}

    shutil.copytree(ROOT, destination, ignore=ignore, dirs_exist_ok=True)
    for relative in (Path("vendor"), Path("backend/vendor")):
        source = ROOT / relative
        target = destination / relative
        if source.exists():
            target.parent.mkdir(parents=True, exist_ok=True)
            target.symlink_to(source, target_is_directory=True)
    source_modules = FRONTEND / "node_modules"
    if source_modules.is_dir():
        target_modules = destination / "frontend/admin-vue/node_modules"
        target_modules.symlink_to(source_modules, target_is_directory=True)


def build_frontend(instance: Path) -> None:
    node = require_executable("node")
    frontend = instance / "frontend/admin-vue"
    commands = (
        [node, str(frontend / "node_modules/vue-tsc/bin/vue-tsc.js"), "--noEmit"],
        [node, str(frontend / "node_modules/vite/bin/vite.js"), "build"],
    )
    for command in commands:
        completed = subprocess.run(command, cwd=frontend, text=True, capture_output=True, timeout=240)
        if completed.returncode != 0:
            raise RuntimeError("Compilation du back-office E2E impossible.\n" + completed.stdout + completed.stderr)


def require_built_frontend(instance: Path) -> None:
    manifest = instance / "admin-app/.vite/manifest.json"
    if not manifest.is_file():
        raise RuntimeError("Assets frontend absents: l’étape de build doit réussir avant --use-built-assets.")


def rebuild_databases(instance: Path) -> None:
    command = [sys.executable, str(instance / "tools/python/operations/database/a_db_init.py"), "--seed-skip-projections"]
    completed = subprocess.run(command, cwd=instance, text=True, capture_output=True, timeout=180)
    if completed.returncode != 0:
        raise RuntimeError("Reconstruction E2E impossible.\n" + completed.stdout + completed.stderr)


def normalize_e2e_site(instance: Path) -> None:
    database = instance / "storage/database/core.sqlite"
    with sqlite3.connect(database) as connection:
        cursor = connection.execute(
            """
            UPDATE site_domains
            SET host='127.0.0.1', base_path='', scheme='http', enforce_https=0,
                canonical_host_strategy='none', updated_at=CURRENT_TIMESTAMP
            WHERE site_id=(SELECT id FROM sites WHERE site_key='main') AND is_primary=1
            """
        )
        if cursor.rowcount != 1:
            raise RuntimeError("Le domaine primaire du site main est absent de la base E2E reconstruite.")
        connection.commit()


def create_blueprint_e2e_fixtures(instance: Path) -> None:
    """Add deterministic Blueprint Designer data to the disposable E2E database."""
    database = instance / "storage/database/core.sqlite"
    fields = (
        ("e2e_title", "text", "Titre E2E", {}, {"max": 160}, {"maxlength": 160}),
        ("e2e_summary", "textarea", "Résumé E2E", {}, {"max": 500}, {"rows": 5}),
        (
            "e2e_choice",
            "select",
            "Choix E2E",
            {"choices": {"standard": "Standard", "featured": "Mis en avant"}},
            {},
            {},
        ),
        (
            "e2e_media",
            "media",
            "Média E2E",
            {},
            {"alt_policy": "required", "allowed_mimes": ["image/jpeg"]},
            {"kind": "image", "max_files": 1},
        ),
    )

    with sqlite3.connect(database) as connection:
        connection.execute("PRAGMA foreign_keys = ON")
        main_site = connection.execute("SELECT id FROM sites WHERE site_key='main' LIMIT 1").fetchone()
        if main_site is None:
            raise RuntimeError("Le site main est absent de la base E2E reconstruite.")
        main_site_id = int(main_site[0])

        connection.execute(
            """
            INSERT OR IGNORE INTO sites(site_key, name, default_language_code, is_active)
            VALUES('e2e_secondary', 'Site secondaire E2E', 'fr', 1)
            """
        )
        secondary_site = connection.execute("SELECT id FROM sites WHERE site_key='e2e_secondary'").fetchone()
        if secondary_site is None:
            raise RuntimeError("Impossible de créer le second site de la fixture E2E.")
        secondary_site_id = int(secondary_site[0])
        connection.execute(
            """
            INSERT OR IGNORE INTO site_languages(
                site_id, language_code, locale, url_prefix, hreflang_code,
                fallback_language_code, is_default, is_active, is_rtl, sort_order
            ) VALUES(?, 'fr', 'fr-CH', '', 'fr-CH', NULL, 1, 1, 0, 10)
            """,
            (secondary_site_id,),
        )
        connection.execute(
            """
            INSERT OR IGNORE INTO site_domains(
                site_id, host, base_path, scheme, is_primary, is_active,
                enforce_https, canonical_host_strategy
            ) VALUES(?, 'e2e-secondary.test', '', 'http', 1, 1, 0, 'none')
            """,
            (secondary_site_id,),
        )

        def insert_blueprint(key: str, label: str, site_id: int | None) -> None:
            cursor = connection.execute(
                """
                INSERT INTO blueprints(
                    blueprint_key, resource_type, site_id, label, description, is_active
                ) VALUES(?, 'block', ?, ?, 'Fixture isolée du Blueprint Designer E2E.', 1)
                """,
                (key, site_id, label),
            )
            blueprint_id = int(cursor.lastrowid)
            version = connection.execute(
                """
                INSERT INTO blueprint_versions(
                    blueprint_id, version, version_label, status, schema_json,
                    ui_schema_json, validation_json, seo_policy_json,
                    routing_policy_json, workflow_policy_json,
                    translation_policy_json, permissions_policy_json,
                    checksum_sha256, is_active, activated_at
                ) VALUES(?, 1, 'Fixture E2E', 'active', ?, ?, ?, ?, ?, ?, ?, ?, NULL, 1, CURRENT_TIMESTAMP)
                """,
                (blueprint_id,) + tuple(json.dumps({}) for _ in range(8)),
            )
            connection.execute(
                "UPDATE blueprints SET active_version_id=? WHERE id=?",
                (int(version.lastrowid), blueprint_id),
            )
            section = connection.execute(
                """
                INSERT INTO blueprint_sections(
                    blueprint_id, section_key, label, description, layout, sort_order
                ) VALUES(?, 'content', 'Contenu E2E', 'Champs déterministes pour Playwright.', 'tab', 10)
                """,
                (blueprint_id,),
            )
            section_id = int(section.lastrowid)
            for index, (handle, field_type, field_label, options, validation, config) in enumerate(fields, 1):
                connection.execute(
                    """
                    INSERT INTO blueprint_fields(
                        blueprint_id, section_id, field_handle, field_type, label,
                        field_purpose, width, is_required, is_localized, is_system,
                        is_deletable, sort_order, options_json, validation_json,
                        conditions_json, config_json
                    ) VALUES(?, ?, ?, ?, ?, 'content', 100, 0, 1, 0, 1, ?, ?, ?, '[]', ?)
                    """,
                    (
                        blueprint_id,
                        section_id,
                        handle,
                        field_type,
                        field_label,
                        index * 10,
                        json.dumps(options, ensure_ascii=False),
                        json.dumps(validation, ensure_ascii=False),
                        json.dumps(config, ensure_ascii=False),
                    ),
                )

        insert_blueprint("000_e2e_fixture", "Fixture Blueprint E2E locale", main_site_id)
        insert_blueprint("000_e2e_fixture", "Fixture Blueprint E2E globale", None)
        insert_blueprint("001_e2e_secondary", "Seconde fixture Blueprint E2E", main_site_id)
        connection.commit()


def create_php_router(instance: Path) -> Path:
    router = instance / ".e2e-router.php"
    router.write_text(
        "<?php\n"
        "$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';\n"
        "$file = __DIR__ . '/' . ltrim($path, '/');\n"
        "if ($path !== '/' && is_file($file)) { return false; }\n"
        "$_SERVER['SCRIPT_NAME'] = '/index.php';\n"
        "$_SERVER['PHP_SELF'] = '/index.php';\n"
        "require __DIR__ . '/index.php';\n",
        encoding="utf-8",
    )
    return router


def php_password_hash(php: str, password: str) -> str:
    completed = subprocess.run(
        [php, "-r", "echo password_hash(trim(stream_get_contents(STDIN)), PASSWORD_DEFAULT);"],
        input=password,
        text=True,
        capture_output=True,
        timeout=15,
    )
    if completed.returncode != 0 or not completed.stdout.startswith("$2"):
        raise RuntimeError("Impossible de générer le hash du compte E2E: " + completed.stderr)
    return completed.stdout.strip()


def create_e2e_admin(instance: Path, php: str, email: str, password: str) -> None:
    database = instance / "storage/database/iam.sqlite"
    password_hash = php_password_hash(php, password)
    with sqlite3.connect(database) as connection:
        cursor = connection.execute(
            """
            INSERT INTO iam_users(email, email_normalized, password_hash, first_name, last_name, locale, is_active, login_mode, totp_enabled, totp_required)
            VALUES(?, ?, ?, 'E2E', 'Administrator', 'fr-CH', 1, 'password', 0, 0)
            """,
            (email, email.lower(), password_hash),
        )
        user_id = int(cursor.lastrowid)
        connection.execute(
            """
            INSERT INTO iam_user_roles(user_id, role_id)
            SELECT ?, id FROM iam_roles WHERE role_key='super_admin'
            """,
            (user_id,),
        )
        assigned = connection.execute(
            "SELECT 1 FROM iam_user_roles WHERE user_id=? LIMIT 1",
            (user_id,),
        ).fetchone()
        if assigned is None:
            raise RuntimeError("Le rôle super_admin est absent de la base E2E reconstruite.")
        connection.commit()


class WebhookReceiver(BaseHTTPRequestHandler):
    def do_POST(self) -> None:  # noqa: N802 - BaseHTTPRequestHandler API
        length = int(self.headers.get("Content-Length", "0") or 0)
        if length:
            self.rfile.read(length)
        self.send_response(204)
        self.end_headers()

    def log_message(self, _format: str, *_args: object) -> None:
        return


def playwright_chromium_executable() -> Path:
    node = require_executable("node")
    if not PLAYWRIGHT_CLI.is_file():
        raise RuntimeError("Playwright absent. Exécutez npm ci dans frontend/admin-vue.")
    completed = subprocess.run(
        [
            node,
            "-e",
            "const { chromium } = require('@playwright/test'); process.stdout.write(chromium.executablePath());",
        ],
        cwd=FRONTEND,
        text=True,
        capture_output=True,
        timeout=30,
    )
    if completed.returncode != 0:
        raise RuntimeError("Impossible de résoudre Chromium Playwright.\n" + completed.stdout + completed.stderr)
    return Path(completed.stdout.strip())


def require_playwright_chromium() -> None:
    executable = playwright_chromium_executable()
    if not executable.is_file():
        raise RuntimeError(
            "Navigateur Chromium Playwright absent.\n"
            f"Chemin attendu: {executable}\n"
            "Installez-le une fois par machine après npm ci: python3 tools/cms.py e2e --install-browser"
        )


def run_playwright(
    environment: dict[str, str], *, headed: bool, omnichannel_only: bool = False, usability_only: bool = False,
    admin_convergence_only: bool = False, specs: tuple[str, ...] = (),
) -> int:
    if sum((omnichannel_only, usability_only, admin_convergence_only)) > 1:
        raise RuntimeError("Ces modes E2E ciblés sont mutuellement exclusifs.")
    if specs and any((omnichannel_only, usability_only, admin_convergence_only)):
        raise RuntimeError("--spec et les modes E2E de gate sont mutuellement exclusifs.")
    node = require_executable("node")
    if not PLAYWRIGHT_CLI.is_file():
        raise RuntimeError("Playwright absent. Exécutez npm ci dans frontend/admin-vue.")
    require_playwright_chromium()
    omnichannel_report = Path(environment.get("E2E_OMNICHANNEL_REPORT", str(OMNICHANNEL_REPORT))).resolve()
    usability_report = Path(environment.get("E2E_USABILITY_REPORT", str(USABILITY_REPORT))).resolve()
    convergence_report = Path(environment.get("E2E_ADMIN_CONVERGENCE_REPORT", str(ADMIN_CONVERGENCE_REPORT))).resolve()
    for report in (omnichannel_report, usability_report, convergence_report):
        report.parent.mkdir(parents=True, exist_ok=True)
    run_omnichannel = not specs and not usability_only and not admin_convergence_only
    run_usability = not specs and not omnichannel_only and not admin_convergence_only
    run_convergence = not specs and not omnichannel_only and not usability_only
    if run_omnichannel:
        omnichannel_report.unlink(missing_ok=True)
        environment["E2E_OMNICHANNEL_REPORT"] = str(omnichannel_report)
    if run_usability:
        usability_report.unlink(missing_ok=True)
        environment["E2E_USABILITY_REPORT"] = str(usability_report)
    if run_convergence:
        convergence_report.unlink(missing_ok=True)
        environment["E2E_ADMIN_CONVERGENCE_REPORT"] = str(convergence_report)
    command = [node, str(PLAYWRIGHT_CLI), "test"]
    if omnichannel_only:
        command.append("tests/e2e/omnichannel-release-gate.spec.ts")
    elif usability_only:
        command.append("tests/e2e/commerce-usability-gate.spec.ts")
    elif admin_convergence_only:
        command.append("tests/e2e/admin-convergence-gate-38e.spec.ts")
    else:
        command.extend(specs)
    if headed:
        command.append("--headed")
    returncode = subprocess.run(command, cwd=FRONTEND, env=environment, timeout=900).returncode
    if returncode != 0:
        return returncode
    if run_omnichannel:
        _payload, errors = validate_report_file(omnichannel_report, ROOT)
        if errors:
            print("Gate E2E omnicanale échouée:\n- " + "\n- ".join(errors), file=sys.stderr)
            return 1
        print(f"Gate E2E omnicanale validée: {omnichannel_report}")
    if run_usability:
        errors = validate_usability_reports(runtime_path=usability_report, root=ROOT)
        if errors:
            print("Gate E2E d’utilisabilité Commerce échouée:\n- " + "\n- ".join(errors), file=sys.stderr)
            return 1
        print(f"Gate E2E d’utilisabilité Commerce validée: {usability_report}")
    if run_convergence:
        errors = validate_admin_convergence_reports(runtime_path=convergence_report, root=ROOT)
        if errors:
            print("Gate E2E UX de convergence admin échouée:\n- " + "\n- ".join(errors), file=sys.stderr)
            return 1
        print(f"Gate E2E UX de convergence admin validée: {convergence_report}")
    return 0


def run_external(
    *, headed: bool, omnichannel_only: bool = False, usability_only: bool = False,
    admin_convergence_only: bool = False, specs: tuple[str, ...] = (),
) -> int | None:
    names = ("E2E_BASE_URL", "E2E_ADMIN_EMAIL", "E2E_ADMIN_PASSWORD")
    provided = [name for name in names if os.environ.get(name, "").strip()]
    if not provided:
        return None
    if len(provided) != len(names):
        missing = ", ".join(name for name in names if name not in provided)
        raise RuntimeError(f"Configuration E2E externe incomplète; variables manquantes: {missing}")
    print("E2E: utilisation de l’environnement externe configuré.")
    environment = os.environ.copy()
    environment.setdefault("E2E_BUILD_COMMIT", source_commit())
    return run_playwright(
        environment, headed=headed, omnichannel_only=omnichannel_only, usability_only=usability_only,
        admin_convergence_only=admin_convergence_only, specs=specs,
    )


def run_isolated(
    *, headed: bool, keep_instance: bool, use_built_assets: bool,
    omnichannel_only: bool = False, usability_only: bool = False, admin_convergence_only: bool = False,
    specs: tuple[str, ...] = (),
) -> int:
    php = require_executable("php")
    require_executable("node")
    email = "e2e-admin@example.test"
    password = secrets.token_urlsafe(24)
    cms_port = free_port()
    base_url = f"http://127.0.0.1:{cms_port}"
    temporary = Path(tempfile.mkdtemp(prefix="dec-cms-e2e-"))
    instance = temporary / "instance"
    php_log = temporary / "php-server.log"
    php_process: subprocess.Popen[bytes] | None = None
    log_handle = None
    receiver: ThreadingHTTPServer | None = None
    receiver_started = False
    try:
        receiver = ThreadingHTTPServer(("127.0.0.1", 0), WebhookReceiver)
        webhook_port = int(receiver.server_address[1])
        webhook_url = f"http://127.0.0.1:{webhook_port}/webhook"
        receiver_thread = threading.Thread(target=receiver.serve_forever, daemon=True)
        print(f"E2E: préparation de l’instance isolée {instance}")
        copy_isolated_instance(instance)
        if use_built_assets:
            print("E2E: utilisation des assets produits par l’étape de build précédente.")
            require_built_frontend(instance)
        else:
            build_frontend(instance)
        rebuild_databases(instance)
        normalize_e2e_site(instance)
        create_blueprint_e2e_fixtures(instance)
        create_e2e_admin(instance, php, email, password)
        router = create_php_router(instance)
        receiver_thread.start()
        receiver_started = True
        runtime_env = os.environ.copy()
        runtime_env.update({"APP_ENV": "test", "APP_DEBUG": "1", "APP_BASE_PATH": ""})
        log_handle = php_log.open("wb")
        php_process = subprocess.Popen(
            [php, "-S", f"127.0.0.1:{cms_port}", router.name],
            cwd=instance,
            env=runtime_env,
            stdout=log_handle,
            stderr=subprocess.STDOUT,
            start_new_session=True,
        )
        wait_for_http(base_url + "/admin/login", php_process)
        test_env = os.environ.copy()
        test_env.update({
            "E2E_BASE_URL": base_url,
            "E2E_ADMIN_EMAIL": email,
            "E2E_ADMIN_PASSWORD": password,
            "E2E_WEBHOOK_URL": webhook_url,
            "E2E_BUILD_COMMIT": os.environ.get("E2E_BUILD_COMMIT", "").strip() or source_commit(),
        })
        print(f"E2E: CMS {base_url}; récepteur webhook {webhook_url}")
        return run_playwright(
            test_env, headed=headed, omnichannel_only=omnichannel_only, usability_only=usability_only,
            admin_convergence_only=admin_convergence_only, specs=specs,
        )
    finally:
        if receiver is not None and receiver_started:
            receiver.shutdown()
        if receiver is not None:
            receiver.server_close()
        if php_process is not None:
            php_process.terminate()
            try:
                php_process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                php_process.kill()
                php_process.wait(timeout=5)
        if log_handle is not None:
            log_handle.close()
        if php_log.exists() and (php_process is None or php_process.returncode not in (0, -15)):
            print(php_log.read_text(encoding="utf-8", errors="replace"), file=sys.stderr)
        if keep_instance:
            print(f"Instance E2E conservée: {temporary}")
        else:
            shutil.rmtree(temporary, ignore_errors=True)


def main() -> int:
    args = parse_args()
    specs = normalize_specs(args.spec)
    if args.install_browser:
        node = require_executable("node")
        if not PLAYWRIGHT_CLI.is_file():
            raise RuntimeError("Playwright absent. Exécutez npm ci dans frontend/admin-vue.")
        return subprocess.run([node, str(PLAYWRIGHT_CLI), "install", "chromium"], cwd=FRONTEND).returncode
    external = run_external(
        headed=args.headed, omnichannel_only=args.omnichannel_only, usability_only=args.usability_only,
        admin_convergence_only=args.admin_convergence_only, specs=specs,
    )
    return external if external is not None else run_isolated(
        headed=args.headed,
        keep_instance=args.keep_instance,
        use_built_assets=args.use_built_assets,
        omnichannel_only=args.omnichannel_only,
        usability_only=args.usability_only,
        admin_convergence_only=args.admin_convergence_only,
        specs=specs,
    )


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (RuntimeError, OSError, subprocess.SubprocessError) as exc:
        print(f"ERREUR E2E: {exc}", file=sys.stderr)
        raise SystemExit(1)
