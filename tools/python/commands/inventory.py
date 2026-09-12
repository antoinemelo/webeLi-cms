from __future__ import annotations

from tools.python.cms.runtime import execute, resolve_php_binary


def configure(parser) -> None:
    sub = parser.add_subparsers(dest="inventory_action", required=True)
    reconcile = sub.add_parser("reconcile", help="Reconstruire et comparer le stock; dry-run par défaut.")
    reconcile.add_argument("--site", type=int, default=1)
    reconcile.add_argument("--repair", action="store_true", help="Appliquer après sauvegarde les corrections prévisualisées.")
    reconcile.add_argument("--reason", help="Motif obligatoire en mode réparation.")
    reconcile.add_argument("--only", help="IDs d'items séparés par des virgules pour relancer uniquement les échecs.")
    reconcile.add_argument("--correction", action="append", default=[], metavar="ITEM:QUANTITY", help="Quantité physique externe à matérialiser par mouvement correctif.")
    reconcile.add_argument("--output", help="Chemin du rapport JSON téléchargeable.")


def run(ctx, args) -> int:
    if args.inventory_action != "reconcile":
        raise ValueError(f"Action Inventory inconnue: {args.inventory_action}")
    if args.site < 1:
        raise ValueError("--site doit être strictement positif")
    if args.repair and len((args.reason or "").strip()) < 3:
        raise ValueError("--reason est obligatoire et doit contenir au moins 3 caractères avec --repair")
    if not args.repair and (args.reason or args.correction):
        raise ValueError("--reason et --correction exigent --repair")
    ctx.require_native_database_dir()
    command = [resolve_php_binary(), str(ctx.root / "backend/bin/console"), "inventory:reconcile", f"--site={args.site}"]
    if args.repair:
        command.extend(["--repair", f"--reason={args.reason.strip()}"])
    if args.only:
        command.append(f"--only={args.only}")
    for correction in args.correction:
        command.append(f"--correction={correction}")
    if args.output:
        command.append(f"--output={args.output}")
    if not ctx.json_output:
        print("Réconciliation Inventory: " + ("RÉPARATION contrôlée" if args.repair else "DRY-RUN"), flush=True)
    return execute(ctx, command, timeout=min(ctx.command_timeout, 600))
