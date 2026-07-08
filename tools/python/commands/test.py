from __future__ import annotations

import json
import sys
import time
from pathlib import Path

from tools.python.cms.runtime import execute

DEFAULT_TIMEOUT_SECONDS = 400
DEFAULT_TARGET_SECONDS = 200
E2E_TIMEOUT_SECONDS = 900
PYTHON_TEST_DIR = Path("tools/python/tests")
RELEASE_COMMAND_HINTS = (
    "python3 tools/cms.py smoke",
    "python3 tools/cms.py validate",
    "python3 tools/cms.py docs check",
)


def configure(parser) -> None:
    parser.add_argument("--pattern", default="test*.py")
    parser.add_argument("--verbose", action="store_true")
    parser.add_argument(
        "--e2e",
        action="store_true",
        help=(
            "Exécute aussi les scénarios Playwright "
            "(serveur et identifiants E2E requis)."
        ),
    )
    parser.add_argument(
        "--timeout",
        type=int,
        default=DEFAULT_TIMEOUT_SECONDS,
        help=(
            "Durée maximale de la suite Python en secondes "
            f"(défaut : {DEFAULT_TIMEOUT_SECONDS})."
        ),
    )
    parser.add_argument(
        "--target-duration",
        type=int,
        default=DEFAULT_TARGET_SECONDS,
        help=(
            "Durée cible en secondes ; son dépassement produit un avertissement "
            f"(défaut : {DEFAULT_TARGET_SECONDS})."
        ),
    )


def _python_tests_available(root: Path, pattern: str) -> bool:
    test_dir = root / PYTHON_TEST_DIR
    return test_dir.is_dir() and any(path.is_file() for path in test_dir.rglob(pattern))


def _source_tests_absent_message(root: Path) -> str:
    hints = " ; ".join(RELEASE_COMMAND_HINTS)
    return (
        "Tests source absents: la commande `test` est réservée au dépôt source complet. "
        f"Répertoire attendu: {(root / PYTHON_TEST_DIR).as_posix()}. "
        "Dans une archive release, utilisez les contrôles release: "
        f"{hints}."
    )


def _emit_source_tests_absent(ctx, message: str) -> None:
    if ctx.json_output:
        print(
            json.dumps(
                {
                    "status": "skipped",
                    "returncode": 2,
                    "context": "release-archive-or-source-tests-missing",
                    "error": message,
                    "release_commands": list(RELEASE_COMMAND_HINTS),
                },
                ensure_ascii=False,
            )
        )
    else:
        print(message, file=sys.stderr)


def run(ctx, args) -> int:
    if args.timeout <= 0:
        raise ValueError("--timeout doit être strictement positif")
    if args.target_duration <= 0:
        raise ValueError("--target-duration doit être strictement positif")

    if not _python_tests_available(ctx.root, args.pattern):
        message = _source_tests_absent_message(ctx.root)
        _emit_source_tests_absent(ctx, message)
        return 2

    command = [
        sys.executable,
        "-m",
        "unittest",
        "discover",
        "-s",
        PYTHON_TEST_DIR.as_posix(),
        "-p",
        args.pattern,
    ]
    if args.verbose:
        command.append("-v")

    started = time.monotonic()
    result = execute(ctx, command, timeout=args.timeout)
    elapsed = time.monotonic() - started

    if not ctx.json_output:
        print(f"Durée de la suite Python : {elapsed:.1f} s")
        if elapsed > args.target_duration:
            print(
                "AVERTISSEMENT : la durée cible de "
                f"{args.target_duration} s est dépassée.",
                file=sys.stderr,
            )

    if result != 0 or not args.e2e:
        return result

    return execute(ctx, [sys.executable, str(ctx.root / "tools/cms.py"), "e2e"], timeout=E2E_TIMEOUT_SECONDS)
