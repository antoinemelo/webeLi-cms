#!/usr/bin/env python3
"""Run Playwright against an isolated CMS rebuilt from native sources."""
from __future__ import annotations

import argparse
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
FRONTEND = ROOT / "frontend" / "admin-vue"
PLAYWRIGHT_CLI = FRONTEND / "node_modules" / "@playwright" / "test" / "cli.js"


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Exécute les E2E Playwright sur une instance CMS isolée.")
    parser.add_argument("--install-browser", action="store_true", help="Installe le navigateur Chromium attendu par Playwright puis quitte.")
    parser.add_argument("--headed", action="store_true", help="Affiche Chromium pendant les tests.")
    parser.add_argument("--keep-instance", action="store_true", help="Conserve l’instance temporaire et affiche son chemin pour diagnostic.")
    parser.add_argument("--use-built-assets", action="store_true", help="Utilise les assets déjà compilés (réservé à la qualification après son étape de build).")
    return parser.parse_args()


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


def create_php_router(instance: Path) -> Path:
    router = instance / ".e2e-router.php"
    router.write_text(
        "<?php\n"
        "$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';\n"
        "$file = __DIR__ . '/' . ltrim($path, '/');\n"
        "if ($path !== '/' && is_file($file)) { return false; }\n"
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
            INSERT INTO iam_users(email, email_normalized, password_hash, first_name, last_name, locale, is_active, totp_enabled, totp_required)
            VALUES(?, ?, ?, 'E2E', 'Administrator', 'fr-CH', 1, 0, 0)
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


def run_playwright(environment: dict[str, str], *, headed: bool) -> int:
    node = require_executable("node")
    if not PLAYWRIGHT_CLI.is_file():
        raise RuntimeError("Playwright absent. Exécutez npm ci dans frontend/admin-vue.")
    command = [node, str(PLAYWRIGHT_CLI), "test"]
    if headed:
        command.append("--headed")
    return subprocess.run(command, cwd=FRONTEND, env=environment, timeout=900).returncode


def run_external(*, headed: bool) -> int | None:
    names = ("E2E_BASE_URL", "E2E_ADMIN_EMAIL", "E2E_ADMIN_PASSWORD")
    provided = [name for name in names if os.environ.get(name, "").strip()]
    if not provided:
        return None
    if len(provided) != len(names):
        missing = ", ".join(name for name in names if name not in provided)
        raise RuntimeError(f"Configuration E2E externe incomplète; variables manquantes: {missing}")
    print("E2E: utilisation de l’environnement externe configuré.")
    return run_playwright(os.environ.copy(), headed=headed)


def run_isolated(*, headed: bool, keep_instance: bool, use_built_assets: bool) -> int:
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
        })
        print(f"E2E: CMS {base_url}; récepteur webhook {webhook_url}")
        return run_playwright(test_env, headed=headed)
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
    if args.install_browser:
        node = require_executable("node")
        if not PLAYWRIGHT_CLI.is_file():
            raise RuntimeError("Playwright absent. Exécutez npm ci dans frontend/admin-vue.")
        return subprocess.run([node, str(PLAYWRIGHT_CLI), "install", "chromium"], cwd=FRONTEND).returncode
    external = run_external(headed=args.headed)
    return external if external is not None else run_isolated(
        headed=args.headed,
        keep_instance=args.keep_instance,
        use_built_assets=args.use_built_assets,
    )


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (RuntimeError, OSError, subprocess.SubprocessError) as exc:
        print(f"ERREUR E2E: {exc}", file=sys.stderr)
        raise SystemExit(1)
