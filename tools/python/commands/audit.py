from __future__ import annotations

from pathlib import Path

from tools.python.cms.runtime import execute


def configure(parser):
    parser.add_argument(
        "--profile",
        choices=("quick", "full", "release"),
        default="quick",
        help="Niveau d'audit reproductible à exécuter dans le conteneur.",
    )
    parser.add_argument(
        "--build",
        action="store_true",
        help="Force la reconstruction de l'image d'audit avant l'exécution.",
    )
    parser.add_argument(
        "--pull",
        action="store_true",
        help="Actualise les images de base pendant la reconstruction.",
    )
    parser.add_argument(
        "--engine",
        choices=("auto", "podman", "docker"),
        default="auto",
        help="Moteur de conteneurs à utiliser (défaut : détection automatique).",
    )
    parser.add_argument(
        "--results-dir",
        help="Répertoire des preuves, relatif à la racine du CMS ou absolu.",
    )
    parser.add_argument(
        "--timeout",
        type=int,
        default=None,
        help=(
            "Borne maximale de l'audit en secondes. "
            "Par défaut: 7200 pour le profil release, sinon --command-timeout."
        ),
    )


def _effective_timeout(profile: str, requested: int | None) -> int | None:
    if requested is not None:
        if requested <= 0:
            raise ValueError("--timeout doit être strictement positif")
        return requested
    if profile == "release":
        return 7200
    return None


def run(ctx, args):
    script = ctx.root / "tools" / "audit" / "audit.sh"
    if not script.is_file():
        raise FileNotFoundError(
            "Script d'audit introuvable : "
            f"{script}. Ajoutez tools/audit/audit.sh et rendez-le exécutable."
        )

    command = ["bash", str(script), args.profile, "--engine", args.engine]
    if args.build:
        command.append("--build")
    if args.pull:
        command.append("--pull")
    if args.results_dir:
        results = Path(args.results_dir).expanduser()
        if not results.is_absolute():
            results = ctx.root / results
        command.extend(["--results-dir", str(results.resolve())])

    return execute(ctx, command, timeout=_effective_timeout(args.profile, args.timeout))
