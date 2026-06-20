from __future__ import annotations

from tools.python.cms.runtime import python_script


def configure(p):
    target = p.add_mutually_exclusive_group()
    target.add_argument('--all', action='store_true', help='Traite toutes les bases SQLite natives migratables (défaut).')
    target.add_argument('--database', help='Traite une seule base native par clé: core, iam, forms, cookies ou ai.')
    mode = p.add_mutually_exclusive_group()
    mode.add_argument('--plan', action='store_true', help='Affiche les migrations disponibles et manquantes sans les appliquer.')
    mode.add_argument('--apply', action='store_true', help='Applique les migrations manquantes après confirmation interne.')
    p.add_argument('--backup', action='store_true', help='Crée une sauvegarde SQLite avant application.')
    p.add_argument('--yes', action='store_true', help='Confirme explicitement l’application des migrations.')


def run(ctx, args):
    ctx.require_native_database_dir()
    argv: list[str] = []
    if args.database:
        argv += ['--database', args.database]
    else:
        argv.append('--all')

    if args.plan or ctx.dry_run or not args.apply:
        argv.append('--plan')
    else:
        argv.append('--yes')
        if args.backup:
            argv.append('--backup')
        if args.yes and '--yes' not in argv:
            argv.append('--yes')

    if args.apply and args.yes and '--yes' not in argv:
        argv.append('--yes')

    return python_script(ctx, 'tools/python/operations/database/d9_migrate_sqlite.py', argv)
