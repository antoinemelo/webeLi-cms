#!/usr/bin/env python3
from __future__ import annotations
import sys as _dec_sys
from pathlib import Path as _DecPath
_DEC_CMS_PROJECT_ROOT = next(parent for parent in _DecPath(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
if str(_DEC_CMS_PROJECT_ROOT) not in _dec_sys.path:
    _dec_sys.path.insert(0, str(_DEC_CMS_PROJECT_ROOT))

import argparse
import json
import os
import re
import shutil
import sqlite3
import subprocess
import sys
import time
from pathlib import Path
from urllib.parse import urlparse

from tools.python.lib.database_inventory import native_database_names

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
CONSOLE = ROOT / "backend" / "bin" / "console"
LOG_DIR = ROOT / "storage" / "logs"
DEFAULT_LOG = LOG_DIR / "deploy_preflight.log"
DATABASE_DIR = ROOT / "storage" / "database"
REQUIRED_DATABASES = list(native_database_names(release=True))
WRITABLE_DIRS = [
    ROOT / "storage" / "database",
    ROOT / "storage" / "cache",
    ROOT / "storage" / "cache" / "twig",
    ROOT / "storage" / "uploads",
    ROOT / "storage" / "logs",
    ROOT / "storage" / "deployments",
    ROOT / "storage" / "deployments" / "releases",
    ROOT / "storage" / "exports",
    ROOT / "storage" / "backups" / "sqlite",
]
REQUIRED_PHP_EXTENSIONS = ["pdo", "pdo_sqlite", "sqlite3", "mbstring", "json", "session", "fileinfo"]
IMAGE_EXTENSIONS = ["gd", "imagick"]
SENSITIVE_PATHS = [
    ROOT / "storage" / ".htaccess",
    ROOT / "storage" / "database" / ".htaccess",
    ROOT / "storage" / "logs" / ".htaccess",
    ROOT / "storage" / "backups" / ".htaccess",
    ROOT / "database" / ".htaccess",
    ROOT / "tools" / ".htaccess",
    ROOT / "ops" / ".htaccess",
]
TWIG_MARKERS = [
    ROOT / "backend" / "vendor" / "twig" / "twig" / "src" / "Environment.php",
    ROOT / "vendor" / "twig" / "twig" / "src" / "Environment.php",
    ROOT.parent / "vendor" / "twig" / "twig" / "src" / "Environment.php",
]


def load_dotenv() -> dict[str, str]:
    values: dict[str, str] = {}
    for env_path in [ROOT / "ops" / ".env", ROOT / ".env"]:
        if not env_path.exists():
            continue
        for raw in env_path.read_text(encoding="utf-8").splitlines():
            line = raw.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, value = line.split("=", 1)
            values[key.strip()] = value.strip().strip('"\'')
    return values


def env_value(name: str, default: str = "") -> str:
    return os.getenv(name) or load_dotenv().get(name, default)


def path_is_absolute(path: str) -> bool:
    return path.startswith(os.sep) or bool(re.match(r"^[A-Z]:[\\/]", path, re.IGNORECASE))


def project_path(path: str) -> Path:
    value = path.strip()
    if not value:
        return ROOT
    candidate = Path(value).expanduser()
    if candidate.is_absolute() or path_is_absolute(value):
        return candidate
    return (ROOT / re.sub(r"^[.][\\/]", "", value)).resolve()


def twig_environment_candidates(configured_path: str) -> list[Path]:
    roots = [
        project_path(configured_path or "./vendor/twig/"),
        project_path("./vendor/twig/"),
        project_path("../vendor/twig/"),
    ]
    candidates: list[Path] = []
    seen: set[Path] = set()
    for root in roots:
        for candidate in [
            root / "src" / "Environment.php",
            root / "twig" / "src" / "Environment.php",
            root / "twig" / "twig" / "src" / "Environment.php",
        ]:
            resolved = candidate.resolve()
            if resolved not in seen:
                seen.add(resolved)
                candidates.append(candidate)
    return candidates


def run(command: list[str], *, timeout: int = 60) -> tuple[int, str]:
    try:
        proc = subprocess.run(command, cwd=str(ROOT), text=True, capture_output=True, timeout=timeout)
    except subprocess.TimeoutExpired:
        return 124, "timeout"
    except OSError as exc:
        return 127, str(exc)
    return proc.returncode, ((proc.stdout or "") + (proc.stderr or "")).strip()


def php_code(code: str) -> tuple[int, str]:
    php = shutil.which("php")
    if php is None:
        return 127, "PHP n'est pas disponible dans le PATH."
    return run([php, "-d", "display_errors=1", "-r", code])


def php_version_tuple() -> tuple[int, int, int] | None:
    code, output = php_code("echo PHP_VERSION;")
    if code != 0:
        return None
    parts = re.findall(r"\d+", output.splitlines()[0] if output else "")[:3]
    if len(parts) < 2:
        return None
    while len(parts) < 3:
        parts.append("0")
    return tuple(int(p) for p in parts)  # type: ignore[return-value]


def php_has_extension(extension: str) -> bool:
    code, _ = php_code(f"exit(extension_loaded('{extension}') ? 0 : 1);")
    return code == 0


def php_has_pdo_sqlite() -> bool:
    code, _ = php_code("exit(in_array('sqlite', PDO::getAvailableDrivers(), true) ? 0 : 1);")
    return code == 0


def php_project_class_load_error() -> str | None:
    code = """
require 'backend/bootstrap/runtime.php';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('backend/src'));
foreach ($it as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') {
        require_once $f->getPathname();
    }
}
"""
    status, output = php_code(code)
    if status == 0:
        return None
    first_line = output.splitlines()[0] if output else "Erreur PHP inconnue."
    return "Chargement des classes PHP impossible: " + first_line


def is_writable_directory(path: Path) -> bool:
    path.mkdir(parents=True, exist_ok=True)
    probe = path / f".preflight-{os.getpid()}.tmp"
    try:
        probe.write_text("ok", encoding="utf-8")
        probe.unlink()
        return True
    except OSError:
        return False


def sqlite_access_errors() -> list[str]:
    errors: list[str] = []
    for name in REQUIRED_DATABASES:
        path = DATABASE_DIR / name
        if not path.exists():
            errors.append(f"Base SQLite manquante: storage/database/{name}")
            continue
        try:
            with sqlite3.connect(path) as con:
                integrity = con.execute("PRAGMA integrity_check").fetchone()[0]
                if integrity != "ok":
                    errors.append(f"storage/database/{name}: integrity_check={integrity}")
                mode = con.execute("PRAGMA journal_mode").fetchone()[0]
                if not mode:
                    errors.append(f"storage/database/{name}: journal_mode illisible")
        except sqlite3.Error as exc:
            errors.append(f"storage/database/{name}: ouverture impossible ({exc})")
        if not os.access(path, os.R_OK | os.W_OK):
            errors.append(f"storage/database/{name}: droits lecture/écriture insuffisants")
    return errors


def vite_manifest_missing_assets(manifest_path: Path, asset_root: Path) -> list[str]:
    if not manifest_path.exists():
        return []
    try:
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    except Exception as exc:  # noqa: BLE001
        return [f"Manifest Vite illisible: {manifest_path} ({exc})"]
    missing: list[str] = []
    for entry in manifest.values():
        if not isinstance(entry, dict):
            continue
        for rel in [entry.get("file"), *entry.get("css", [])]:
            if not rel:
                continue
            candidate = asset_root / str(rel).lstrip("/")
            if not candidate.exists():
                missing.append(f"Asset Vite référencé mais absent: {candidate}")
    return missing


def run_step(command: list[str], cwd: Path, log_file: Path, capture: bool = True, echo_output: bool = True) -> int:
    stamp = time.strftime("%Y-%m-%d %H:%M:%S")
    header = f"\n[{stamp}] >>> {' '.join(command)}\n"
    log_file.parent.mkdir(parents=True, exist_ok=True)
    with log_file.open("a", encoding="utf-8") as handle:
        handle.write(header)
    if capture:
        proc = subprocess.run(command, cwd=str(cwd), text=True, capture_output=True)
        stdout = proc.stdout or ""
        stderr = proc.stderr or ""
        if echo_output and stdout:
            print(stdout, end="")
        if echo_output and stderr:
            print(stderr, end="", file=sys.stderr)
        with log_file.open("a", encoding="utf-8") as handle:
            if stdout:
                handle.write(stdout)
            if stderr:
                handle.write("\n[stderr]\n")
                handle.write(stderr)
        return proc.returncode
    return subprocess.run(command, cwd=str(cwd)).returncode


def check_php_runtime(errors: list[str], warnings: list[str]) -> None:
    if shutil.which("php") is None:
        errors.append("PHP n'est pas disponible dans le PATH.")
        return
    version = php_version_tuple()
    if version is None:
        errors.append("Version PHP illisible.")
    elif version < (8, 2, 0):
        errors.append(f"PHP 8.2+ requis ; version détectée: {'.'.join(map(str, version))}.")
    class_error = php_project_class_load_error()
    if class_error:
        errors.append(class_error)
    for extension in REQUIRED_PHP_EXTENSIONS:
        if not php_has_extension(extension):
            errors.append(f"Extension PHP manquante: {extension}")
    if not php_has_pdo_sqlite():
        errors.append("Extension PHP pdo_sqlite indisponible: activez pdo_sqlite/sqlite3 sur l’hébergement avant d’utiliser le CMS.")
    if not any(php_has_extension(ext) for ext in IMAGE_EXTENSIONS):
        warnings.append("Aucune extension image gd/imagick détectée: uploads possibles, mais génération/contrôle avancé des médias à vérifier.")


def check_runtime_config(errors: list[str], warnings: list[str], target: str = "auto") -> None:
    configured_env = env_value("APP_ENV", "").lower()
    if target in {"production", "staging"}:
        app_env = target
    elif target == "local":
        app_env = configured_env or "local"
    else:
        app_env = configured_env or "local"
    app_debug = env_value("APP_DEBUG", "0").lower()
    public_url = env_value("APP_PUBLIC_BASE_URL", "")
    base_path = env_value("APP_BASE_PATH", "")
    if app_env not in {"production", "prod", "staging", "dev", "development", "local", "test"}:
        warnings.append(f"APP_ENV inhabituel: {app_env}")
    if app_env in {"production", "prod"} and app_debug in {"1", "true", "yes", "on"}:
        errors.append("APP_DEBUG doit être désactivé en production.")
    if app_env in {"production", "prod", "staging"}:
        if not public_url:
            errors.append("APP_PUBLIC_BASE_URL doit être configuré pour staging/production. En local, lancez le préflight sans APP_ENV=production ou utilisez --target local.")
        else:
            parsed = urlparse(public_url)
            if parsed.scheme not in {"https", "http"} or not parsed.netloc:
                errors.append("APP_PUBLIC_BASE_URL doit être une URL absolue valide.")
            if app_env in {"production", "prod"} and parsed.scheme != "https":
                warnings.append("APP_PUBLIC_BASE_URL n'utilise pas HTTPS en production.")
    if base_path and not base_path.startswith("/"):
        errors.append("APP_BASE_PATH doit être vide ou commencer par '/'.")
    if "//" in base_path:
        errors.append("APP_BASE_PATH ne doit pas contenir de double slash.")

    twig_configured_path = env_value("APP_TWIG_VENDOR_PATH", env_value("APP_TWIG_PATH", "./vendor/twig/"))
    twig_candidates = twig_environment_candidates(twig_configured_path)
    existing_twig = [path for path in twig_candidates if path.exists()]
    if existing_twig:
        parent_vendor = (ROOT.parent / "vendor").resolve()
        if any(parent_vendor in path.resolve().parents for path in existing_twig):
            warnings.append(
                "Twig est résolu depuis le vendor parent. Vérifiez que ce vendor ne charge pas un autoloader Composer déclarant App\\."
            )
    else:
        warnings.append(
            "Twig introuvable dans APP_TWIG_VENDOR_PATH, ./vendor/twig ou ../vendor/twig. "
            "Le runtime natif peut fonctionner, mais les templates Twig doivent être vérifiés."
        )


def check_server_security(errors: list[str]) -> None:
    for path in SENSITIVE_PATHS:
        if not path.exists():
            errors.append(f"Protection serveur absente: {path.relative_to(ROOT).as_posix()}")
            continue
        source = path.read_text(encoding="utf-8", errors="ignore")
        if "Options -Indexes" not in source:
            errors.append(f"Indexation non désactivée: {path.relative_to(ROOT).as_posix()}")
        if path.name == ".htaccess" and path.parent.name != "media" and "Require all denied" not in source and "Deny from all" not in source:
            errors.append(f"Accès direct non bloqué clairement: {path.relative_to(ROOT).as_posix()}")
    media_htaccess = ROOT / "storage" / "media" / ".htaccess"
    if media_htaccess.exists():
        source = media_htaccess.read_text(encoding="utf-8", errors="ignore")
        for marker in ["Options -Indexes", "FilesMatch", "quarantine", "X-Content-Type-Options"]:
            if marker not in source:
                errors.append(f"storage/media/.htaccess doit contenir le garde-fou: {marker}")
    else:
        errors.append("storage/media/.htaccess absent.")


def check_prerequisites(target: str = "auto") -> tuple[list[str], list[str]]:
    errors: list[str] = []
    warnings: list[str] = []
    check_php_runtime(errors, warnings)
    check_runtime_config(errors, warnings, target)

    if not CONSOLE.exists():
        errors.append(f"Console introuvable: {CONSOLE}")
    if not (ROOT / "backend" / "composer.json").exists():
        errors.append("backend/composer.json introuvable.")
    for route_file in [ROOT / "backend" / "routes" / "api.php", ROOT / "backend" / "routes" / "admin.php", ROOT / "backend" / "routes" / "web.php"]:
        if not route_file.exists():
            errors.append(f"Fichier de routes introuvable: {route_file}")
    if not (ROOT / "backend" / "src" / "Core" / "Router.php").exists():
        errors.append("Core/Router.php introuvable: les routes déclarées ne peuvent pas être dispatchées.")

    app_source_path = ROOT / "backend" / "src" / "Core" / "App.php"
    if app_source_path.exists():
        app_source = app_source_path.read_text(encoding="utf-8")
        required_wirings = {
            "ProfileApiController": "new ProfileApiController($this->request, $services->auth())",
            "SeoAuditApiController": "new SeoAuditApiController(",
        }
        for controller_name, wiring_marker in required_wirings.items():
            if wiring_marker not in app_source:
                errors.append(f"Contrôleur admin non câblé dans Core/App.php: {controller_name}")
    else:
        errors.append(f"Front controller applicatif introuvable: {app_source_path}")

    admin_routes = ROOT / "backend" / "routes" / "admin.php"
    if admin_routes.exists():
        admin_route_source = admin_routes.read_text(encoding="utf-8")
        if "'/admin'" not in admin_route_source or "'/admin/'" not in admin_route_source:
            errors.append("/admin et /admin/ doivent être des alias sûrs vers le back-office natif pour éviter la résolution front.")
        if "AdminSpaController@index" not in admin_route_source:
            errors.append("Les routes /admin doivent pointer vers AdminSpaController@index, pas vers une interface Twig héritée.")

    legacy_admin_files = [
        ROOT / "backend" / "src" / "Application" / "Admin" / "AdminPageController.php",
        ROOT / "backend" / "src" / "Application" / "Admin" / "DashboardController.php",
        ROOT / "backend" / "src" / "Application" / "Admin" / "ContentEntryController.php",
        ROOT / "backend" / "src" / "Application" / "Admin" / "ContentTypeController.php",
        ROOT / "backend" / "src" / "Application" / "Admin" / "TaxonomyController.php",
        ROOT / "backend" / "src" / "Application" / "Admin" / "MediaController.php",
        ROOT / "backend" / "src" / "Application" / "Admin" / "SeoAuditController.php",
        ROOT / "backend" / "src" / "Application" / "Admin" / "SystemController.php",
        ROOT / "backend" / "src" / "Security" / "AdminAccess.php",
        ROOT / "backend" / "resources" / "views" / "admin",
    ]
    for legacy_admin_file in legacy_admin_files:
        if legacy_admin_file.exists():
            errors.append(f"Relicat admin Twig à supprimer: {legacy_admin_file}")

    if not (ROOT / "storage").exists():
        errors.append("Le répertoire storage/ est introuvable.")
    for directory in WRITABLE_DIRS:
        if not is_writable_directory(directory):
            errors.append(f"Répertoire non inscriptible par le processus courant: {directory.relative_to(ROOT).as_posix()}")
    errors.extend(sqlite_access_errors())
    check_server_security(errors)

    admin_manifest = ROOT / "admin-app" / ".vite" / "manifest.json"
    admin_assets = ROOT / "admin-app" / "assets"
    if not admin_manifest.exists():
        errors.append("Manifest back-office Vue introuvable: compilez frontend/admin-vue avant packaging.")
    if not admin_assets.exists() or not any(admin_assets.glob("index-*.js")):
        errors.append("Bundle JS back-office Vue introuvable dans admin-app/assets.")
    errors.extend(vite_manifest_missing_assets(admin_manifest, ROOT / "admin-app"))

    api_client = ROOT / "frontend" / "admin-vue" / "src" / "api" / "client.ts"
    if api_client.exists():
        api_client_source = api_client.read_text(encoding="utf-8")
        if "resolveAdminApiBasePath" not in api_client_source or "__AMCMS_ADMIN__" not in api_client_source:
            errors.append("Le client API Vue doit résoudre /admin/api depuis le runtime, pas via une constante absolue.")

    spa_controller = ROOT / "backend" / "src" / "Application" / "Admin" / "AdminSpaController.php"
    if spa_controller.exists():
        spa_source = spa_controller.read_text(encoding="utf-8")
        if "__AMCMS_ADMIN__" not in spa_source or "apiBasePath" not in spa_source:
            errors.append("AdminSpaController doit injecter la configuration runtime du back-office Vue.")

    legacy_paths = [
        ROOT / "backend" / "src" / "Controller",
        ROOT / "backend" / "src" / "Application" / "Admin" / "LegacyAdminAction.php",
        ROOT / "backend" / "src" / "Application" / "Frontend" / "FrontendRuntimeAction.php",
        ROOT / "backend" / "src" / "Infrastructure" / "Http" / "Kernel.php",
        ROOT / "backend" / "src" / "Infrastructure" / "Container" / "Container.php",
        ROOT / "backend" / "bootstrap" / "container.php",
    ]
    for legacy_path in legacy_paths:
        if legacy_path.exists():
            errors.append(f"Relicat legacy à supprimer: {legacy_path}")

    return errors, warnings


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Préflight production/local avant packaging ou mise en ligne DEC CMS.")
    parser.add_argument("--log", default=str(DEFAULT_LOG), help="Fichier de log du préflight.")
    parser.add_argument("--skip-console", action="store_true", help="Ne lance pas les commandes PHP console de smoke test.")
    parser.add_argument("--json", action="store_true", help="Sortie JSON exploitable par CI.")
    parser.add_argument("--target", choices=["auto", "local", "staging", "production"], default="auto", help="Profil de contrôle: auto/local ne rend pas APP_PUBLIC_BASE_URL obligatoire; staging/production l’exigent.")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    log_file = Path(args.log)
    if not log_file.is_absolute():
        log_file = ROOT / log_file

    errors, warnings = check_prerequisites(args.target)
    if not args.skip_console and not errors and CONSOLE.exists() and shutil.which("php"):
        for command in [["php", str(CONSOLE), "route:list"], ["php", str(CONSOLE), "system:smoke"]]:
            code = run_step(command, ROOT, log_file, echo_output=False)
            if code != 0:
                errors.append(f"Commande de préflight échouée: {' '.join(command)}")
                break

    if args.json:
        print(json.dumps({"ok": not errors, "target": args.target, "errors": errors, "warnings": warnings}, ensure_ascii=False, indent=2))
    else:
        label = "auto/local" if args.target == "auto" else args.target
        print(f"[preflight {label}]")
        for warning in warnings:
            print(f"WARN {warning}")
        for error in errors:
            print(f"ERROR {error}", file=sys.stderr)
        if not errors:
            print("OK Environnement, stockage, bases SQLite, configuration et protections serveur cohérents.")
        print(f"Résumé: {len(warnings)} avertissement(s), {len(errors)} erreur(s)")

    return 0 if not errors else 1


if __name__ == "__main__":
    raise SystemExit(main())
