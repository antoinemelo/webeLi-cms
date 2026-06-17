from tools.python.cms.runtime import python_script

def configure(p):
    p.add_argument('--output'); p.add_argument('--restore'); p.add_argument('--yes',action='store_true'); p.add_argument('--no-safety-copy',action='store_true')
def run(ctx,args):
    ctx.require_native_database_dir()
    if args.restore:
        a=['--archive',args.restore]
        if args.yes:a.append('--yes')
        if args.no_safety_copy:a.append('--no-safety-copy')
        return python_script(ctx,'tools/python/operations/backup/d7_restore_sqlite.py',a)
    a=[]
    if args.output:a += ['--output',args.output]
    return python_script(ctx,'tools/python/operations/backup/d6_backup_sqlite.py',a)
