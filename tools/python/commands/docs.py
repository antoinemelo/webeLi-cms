from __future__ import annotations

from tools.python.cms.runtime import python_script
from tools.python.generators import generate_documentation
from tools.python.validation.runner import run_validators


def configure(parser):
    sub = parser.add_subparsers(dest="docs_action", required=True)
    sub.add_parser(
        "generate",
        help="Régénérer les références, OpenAPI et types SDK publics.",
    )
    sub.add_parser(
        "check",
        help="Vérifier la fraîcheur des références, OpenAPI, types SDK et gouvernance documentaire.",
    )
    sub.add_parser(
        "evaluation-generate",
        help="Régénérer les données machine-readable de l’espace d’évaluation.",
    )
    tree = sub.add_parser(
        "tree",
        help="Générer ou vérifier TREE.txt / TREE.release.txt.",
    )
    mode = tree.add_mutually_exclusive_group()
    mode.add_argument("--source", action="store_true", help="Mode source: TREE.txt, sans storage/, dépendances ni artefacts locaux.")
    mode.add_argument("--release", action="store_true", help="Mode release: TREE.release.txt, selon les règles de packaging.")
    tree.add_argument("--root", default="", help="Racine à scanner. Défaut: racine du projet courant.")
    tree.add_argument("--output", default="", help="Fichier de sortie explicite.")
    tree.add_argument("--stdout", action="store_true", help="Écrire le manifeste sur stdout.")
    tree.add_argument("--check", action="store_true", help="Comparer sans modifier.")
    tree.add_argument("--exclude-databases", action="store_true", help="En mode release, exclure les bases SQLite.")
    sub.add_parser(
        "evaluation-check",
        help="Vérifier les données et preuves de l’espace d’évaluation.",
    )


def _run_generate_documentation(flags: list[str]) -> int:
    return int(generate_documentation.main(flags))


def _run_steps(ctx, steps: list[tuple[str, list[str]]]) -> int:
    """Exécute la chaîne documentaire dans un ordre déterministe.

    OpenAPI doit être généré avant les types SDK, car ces derniers sont dérivés de
    ``components.schemas``. La première erreur interrompt la chaîne afin de ne pas
    masquer la cause initiale. Le générateur Markdown est lancé dans le processus
    courant afin que ``docs check`` reste fiable dans les environnements locaux où
    des sous-processus imbriqués peuvent conserver des descripteurs ouverts.
    """
    for script, flags in steps:
        if script == "tools/python/generators/generate_documentation.py":
            code = _run_generate_documentation(flags)
        else:
            code = python_script(ctx, script, flags)
        if code != 0:
            return code
    return 0


def run(ctx, args):
    json_flags = ["--json"] if ctx.json_output else []


    if args.docs_action == "tree":
        flags: list[str] = []
        if getattr(args, "release", False):
            flags.append("--release")
        else:
            flags.append("--source")
        for attr, flag in (
            ("root", "--root"),
            ("output", "--output"),
        ):
            value = getattr(args, attr, "")
            if value:
                flags.extend([flag, value])
        for attr, flag in (
            ("stdout", "--stdout"),
            ("check", "--check"),
            ("exclude_databases", "--exclude-databases"),
        ):
            if getattr(args, attr, False):
                flags.append(flag)
        if ctx.json_output:
            flags.append("--json")
        return _run_steps(ctx, [("tools/python/generators/generate_tree_manifest.py", flags)])

    if args.docs_action == "evaluation-generate":
        return _run_steps(ctx, [("tools/python/generators/generate_evaluation_documentation.py", json_flags)])

    if args.docs_action == "evaluation-check":
        return run_validators(
            names=("DOCUMENTATION_CONTRACTS",),
            mode="fast",
            json_output=ctx.json_output,
            fail_fast=True,
        )

    if args.docs_action == "generate":
        return _run_steps(
            ctx,
            [
                ("tools/python/generators/generate_documentation.py", json_flags),
                ("tools/python/generators/c40_generate_openapi_headless_v1.py", []),
                ("tools/python/generators/c44_generate_sdk_types_from_openapi.py", []),
            ],
        )

    code = _run_steps(
        ctx,
        [("tools/python/generators/generate_documentation.py", ["--check", *json_flags])],
    )
    if code != 0:
        return code

    code = run_validators(
        names=("API_SPEC", "DOCUMENTATION_CONTRACTS"),
        mode="fast",
        json_output=ctx.json_output,
        fail_fast=True,
    )
    if code != 0:
        return code

    return 0
