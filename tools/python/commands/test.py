from __future__ import annotations

import sys
import time

from tools.python.cms.runtime import execute

DEFAULT_TIMEOUT_SECONDS = 300
DEFAULT_TARGET_SECONDS = 120
E2E_TIMEOUT_SECONDS = 900


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


def run(ctx, args) -> int:
    if args.timeout <= 0:
        raise ValueError("--timeout doit être strictement positif")
    if args.target_duration <= 0:
        raise ValueError("--target-duration doit être strictement positif")

    command = [
        sys.executable,
        "-m",
        "unittest",
        "discover",
        "-s",
        "tools/python/tests",
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

    return execute(
        ctx,
        ["npm", "run", "test:e2e"],
        cwd="frontend/admin-vue",
        timeout=E2E_TIMEOUT_SECONDS,
    )
