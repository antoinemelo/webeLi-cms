from __future__ import annotations

from tools.python.cms.runtime import python_script


def configure(p):
    sub = p.add_subparsers(dest="instance_action", required=True)
    clone = sub.add_parser("clone", help="Cloner une instance locale dans un autre répertoire.")
    clone.add_argument("--source", help="Répertoire source. Par défaut: racine CMS courante.")
    clone.add_argument("--destination", required=True, help="Répertoire destination à créer, par exemple ../mod2 ou ../eve.")
    clone.add_argument("--old-base-path", help="APP_BASE_PATH public source. Par défaut: nom du répertoire source.")
    clone.add_argument("--new-base-path", "--target-base-path", dest="new_base_path", help="APP_BASE_PATH public cible. Par défaut: nom du répertoire destination.")
    clone.add_argument("--new-public-base-url", help="APP_PUBLIC_BASE_URL exact à écrire dans ops/.env.")
    clone.add_argument("--force", action="store_true", help="Remplace la destination si elle existe.")
    clone.add_argument("--include-dev-admin-vue", action="store_true", help="Inclut les sources frontend/admin-vue.")
    clone.add_argument("--include-docs", action="store_true", help="Inclut docs/, README.md et TREE.txt.")


def run(ctx, args):
    if args.instance_action != "clone":
        raise ValueError(f"Action instance inconnue: {args.instance_action}")

    values = []
    if args.source:
        values.extend(["--source", args.source])
    values.extend(["--destination", args.destination])
    if args.old_base_path:
        values.extend(["--old-base-path", args.old_base_path])
    if args.new_base_path:
        values.extend(["--new-base-path", args.new_base_path])
    if args.new_public_base_url:
        values.extend(["--new-public-base-url", args.new_public_base_url])
    if args.force:
        values.append("--force")
    if ctx.dry_run:
        values.append("--dry-run")
    if args.include_dev_admin_vue:
        values.append("--include-dev-admin-vue")
    if args.include_docs:
        values.append("--include-docs")

    return python_script(ctx, "tools/python/operations/deployment/d14_clone_instance.py", values)
