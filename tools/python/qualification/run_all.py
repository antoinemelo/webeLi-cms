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

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
REPORT_DIR = ROOT / "storage" / "qualification"


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
            (py, cms, "test", "--timeout", "300", "--target-duration", "120"),
            executables=("php",),
            timeout=360,
        ),
        Step("validate-core", "Validateurs structurels", ("complete", "release"), (py, cms, "validate", "--full", "--category", "configuration", "--category", "database", "--category", "content", "--category", "permissions", "--category", "api", "--category", "operations", "--category", "security", "--category", "documentation", "--category", "shared"), timeout=600),
        Step("runtime-integrity", "Intégrité runtime", ("complete", "release"), (py, cms, "validate", "--full", "--validator", "RUNTIME_INTEGRITY"), timeout=300),
        Step("backup-restore", "Sauvegarde/restauration", ("complete", "release"), (py, cms, "validate", "--with-slow", "--validator", "BACKUP_RESTORE_ROUNDTRIP"), timeout=600),
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
            timeout=600,
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
            timeout=1200,
        ),
        Step("docs-generate", "Génération documentaire", ("complete", "release"), (py, cms, "docs", "generate"), timeout=300),
        Step("docs-check", "Contrôle documentaire", ("complete", "release"), (py, cms, "docs", "check"), timeout=300),
        Step("static-export", "Export statique à blanc", ("complete", "release"), (py, cms, "--dry-run", "export"), executables=("php",), files=("backend/bin/console",), timeout=300),
        Step("preflight", "Préflight de production", ("release",), (py, "tools/python/operations/deployment/d1_preflight_local.py"), timeout=300),
        Step("package", "Création de la release", ("release",), (py, "tools/python/operations/deployment/d2_package_release.py"), timeout=600),
        Step("verify-archive", "Vérification de l'archive", ("release",), action=_verify_latest_archive),
        Step("fresh-install", "Installation neuve et smoke test", ("release",), action=_fresh_install_smoke),
    )


def _environment_check() -> tuple[int, str, str]:
    commands = {"python": [sys.executable, "--version"], "php": ["php", "-v"], "node": ["node", "--version"], "npm": ["npm", "--version"]}
    lines: list[str] = []
    missing: list[str] = []
    for name, command in commands.items():
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


def _php_lint() -> tuple[int, str, str]:
    files = sorted((ROOT / "backend").rglob("*.php")) + sorted((ROOT / "tools/php").rglob("*.php"))
    outputs: list[str] = []
    for path in files:
        proc = subprocess.run(["php", "-l", str(path)], cwd=ROOT, text=True, capture_output=True, timeout=20)
        if proc.returncode != 0:
            return proc.returncode, "\n".join(outputs), proc.stderr or proc.stdout
        outputs.append(path.relative_to(ROOT).as_posix())
    return 0, f"{len(files)} fichiers PHP valides", ""


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
    parser.add_argument("--list", action="store_true", help="Affiche le plan sans exécuter.")
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    selected = [step for step in steps() if args.profile in step.profiles]
    if args.list:
        for step in selected:
            print(f"{step.id}: {step.label}")
        return 0
    results: list[Result] = []
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
    payload = {"schema_version": 1, "profile": args.profile, "status": {0: "passed", 1: "failed", 2: "incomplete", 3: "internal-error"}[code], "exit_code": code, "generated_at": datetime.now(timezone.utc).isoformat(), "results": [asdict(r) for r in results]}
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
