from __future__ import annotations

import json
import os
import shutil
import signal
import subprocess
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Sequence

DEFAULT_COMMAND_TIMEOUT = 600


@dataclass(frozen=True)
class Context:
    root: Path
    database_dir: Path
    json_output: bool
    dry_run: bool
    evidence_dir: Path | None
    command_timeout: int

    @classmethod
    def build(
        cls,
        root: str | None,
        database_dir: str | None,
        json_output: bool,
        dry_run: bool,
        evidence_dir: str | None = None,
        command_timeout: int = DEFAULT_COMMAND_TIMEOUT,
    ) -> 'Context':
        base = Path(root or Path.cwd()).expanduser().resolve()
        if not (base / 'backend').is_dir() or not (base / 'tools/python').is_dir():
            raise ValueError(f'Racine CMS invalide: {base}')
        database = Path(database_dir).expanduser() if database_dir else base / 'storage/database'
        if not database.is_absolute():
            database = base / database
        if command_timeout <= 0:
            raise ValueError('--command-timeout doit être strictement positif')
        evidence = Path(evidence_dir).expanduser() if evidence_dir else None
        if evidence is not None and not evidence.is_absolute():
            evidence = base / evidence
        return cls(base, database.resolve(), json_output, dry_run, evidence.resolve() if evidence else None, command_timeout)

    def require_native_database_dir(self) -> None:
        expected = (self.root / 'storage/database').resolve()
        if self.database_dir != expected:
            raise ValueError(f'Cette commande historique exige --database-dir={expected}; reçu {self.database_dir}')


def resolve_executable(name: str, *, env_variable: str | None = None) -> str:
    configured = os.environ.get(env_variable, '').strip() if env_variable else ''
    candidate = configured or shutil.which(name)
    if not candidate:
        hint = f' ou définir {env_variable}' if env_variable else ''
        raise FileNotFoundError(f'Dépendance externe introuvable: {name}{hint}')
    path = Path(candidate).expanduser()
    if path.is_absolute() and not path.is_file():
        raise FileNotFoundError(f'Exécutable configuré introuvable: {path}')
    return str(path if path.is_absolute() else candidate)


def merged_environment(overrides: dict[str, str] | None = None) -> dict[str, str]:
    """Return a subprocess environment with explicit overrides applied last.

    This intentionally avoids ``dict(**os.environ, NAME=value)`` because that
    pattern raises ``TypeError`` when NAME is already exported by the caller.
    """
    environment = os.environ.copy()
    if overrides:
        environment.update({str(key): str(value) for key, value in overrides.items()})
    return environment


def _terminate_process_group(process: subprocess.Popen[str]) -> None:
    if process.poll() is not None:
        return
    try:
        if os.name == 'posix':
            os.killpg(process.pid, signal.SIGTERM)
        else:
            process.terminate()
        process.wait(timeout=3)
    except (ProcessLookupError, subprocess.TimeoutExpired):
        try:
            if os.name == 'posix':
                os.killpg(process.pid, signal.SIGKILL)
            else:
                process.kill()
        except ProcessLookupError:
            pass
        process.wait(timeout=3)


def execute(
    ctx: Context,
    command: Sequence[str],
    *,
    env: dict[str, str] | None = None,
    cwd: str | Path | None = None,
    timeout: int | None = None,
) -> int:
    cmd = [str(item) for item in command]
    if not cmd:
        raise ValueError('Commande vide')
    working_directory = (ctx.root / cwd if cwd is not None else ctx.root).resolve()
    effective_timeout = timeout if timeout is not None else ctx.command_timeout

    if ctx.dry_run:
        payload = {'status': 'dry-run', 'root': str(ctx.root), 'database_dir': str(ctx.database_dir), 'cwd': str(working_directory), 'command': cmd, 'timeout': effective_timeout, 'returncode': 0}
        print(json.dumps(payload, ensure_ascii=False) if ctx.json_output else 'DRY-RUN: ' + ' '.join(cmd))
        return 0

    process: subprocess.Popen[str] | None = None
    try:
        process = subprocess.Popen(
            cmd,
            cwd=working_directory,
            env=merged_environment(env),
            text=True,
            stdout=subprocess.PIPE if ctx.json_output else None,
            stderr=subprocess.PIPE if ctx.json_output else None,
            start_new_session=(os.name == 'posix'),
        )
        stdout, stderr = process.communicate(timeout=effective_timeout)
        returncode = int(process.returncode or 0)
    except subprocess.TimeoutExpired:
        if process is not None:
            _terminate_process_group(process)
        message = f"Timeout après {effective_timeout} secondes : {' '.join(cmd)}"
        if ctx.json_output:
            print(json.dumps({'status': 'timeout', 'command': cmd, 'cwd': str(working_directory), 'timeout': effective_timeout, 'returncode': 124, 'stdout': '', 'stderr': '', 'error': message}, ensure_ascii=False))
        else:
            print(message, file=sys.stderr)
        return 124
    except OSError as exc:
        message = f"Impossible d'exécuter {cmd[0]}: {exc}"
        if ctx.json_output:
            print(json.dumps({'status': 'error', 'command': cmd, 'cwd': str(working_directory), 'timeout': effective_timeout, 'returncode': 127, 'error': message}, ensure_ascii=False))
        else:
            print(message, file=sys.stderr)
        return 127

    if ctx.json_output:
        print(json.dumps({'status': 'ok' if returncode == 0 else 'failed', 'command': cmd, 'cwd': str(working_directory), 'timeout': effective_timeout, 'returncode': returncode, 'stdout': stdout, 'stderr': stderr}, ensure_ascii=False))
    return returncode


def python_script(ctx: Context, relative: str, args: Sequence[str] = (), *, timeout: int | None = None) -> int:
    current_pythonpath = os.environ.get('PYTHONPATH', '')
    entries = [str(ctx.root)] + ([current_pythonpath] if current_pythonpath else [])
    return execute(ctx, [sys.executable, str(ctx.root / relative), *args], env={'PYTHONPATH': os.pathsep.join(entries)}, timeout=timeout)


def php_console(ctx: Context, command: str, args: Sequence[str] = (), *, timeout: int | None = None) -> int:
    php = resolve_executable('php', env_variable='CMS_PHP_BINARY')
    return execute(ctx, [php, str(ctx.root / 'backend/bin/console'), command, *args], timeout=timeout)
