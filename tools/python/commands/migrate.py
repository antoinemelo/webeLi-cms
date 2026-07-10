from __future__ import annotations

from tools.python.cms.runtime import python_script
from tools.python.lib.database_inventory import database_keys, module_keys


def configure(p):
    target = p.add_mutually_exclusive_group()
    target.add_argument('--all', action='store_true', help='Traite toutes les bases SQLite migratables connues (défaut).')
    target.add_argument('--database', choices=database_keys(migratable=True), help='Traite une seule base par clé ou scope.')
    target.add_argument('--module', choices=module_keys(), help='Traite les bases déclarées par un module.')
    mode = p.add_mutually_exclusive_group()
    mode.add_argument('--plan', action='store_true', help='Affiche les migrations disponibles et manquantes sans les appliquer.')
    mode.add_argument('--apply', action='store_true', help='Applique les migrations manquantes après confirmation interne.')
    p.add_argument('--backup', action='store_true', help='Crée une sauvegarde SQLite avant application.')
    p.add_argument('--no-backup-i-understand-the-risk', action='store_true', help='Applique sans sauvegarde préalable; option volontairement explicite et déconseillée.')
    p.add_argument('--yes', action='store_true', help='Confirme explicitement l’application des migrations.')


def run(ctx, args):
    ctx.require_native_database_dir()
    argv: list[str] = []
    if args.database:
        argv += ['--database', args.database]
    elif args.module:
        argv += ['--module', args.module]
    else:
        argv.append('--all')

    if args.plan or ctx.dry_run or not args.apply:
        argv.append('--plan')
    else:
        if args.backup:
            argv.append('--backup')
        if args.no_backup_i_understand_the_risk:
            argv.append('--no-backup-i-understand-the-risk')
        if args.yes:
            argv.append('--yes')

    return python_script(ctx, 'tools/python/operations/database/d9_migrate_sqlite.py', argv)
