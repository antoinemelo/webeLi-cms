from __future__ import annotations

from tools.python.cms.runtime import python_script


def configure(parser) -> None:
    parser.add_argument(
        "--structural-only",
        action="store_true",
        help=(
            "Conservé pour compatibilité: le smoke test release est volontairement "
            "structurel et non destructif."
        ),
    )


def run(ctx, args) -> int:
    flags = ["--root", str(ctx.root)]
    if ctx.json_output:
        flags.append("--json")
    return python_script(
        ctx,
        "tools/python/operations/deployment/d5_smoke_test_release_structure.py",
        flags,
        timeout=min(ctx.command_timeout, 180),
    )
