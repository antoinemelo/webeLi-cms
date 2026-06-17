from tools.python.cms.runtime import python_script

def configure(p): p.add_argument('--with-reference-seed',action='store_true')
def configure_rebuild(p): p.add_argument('--skip-projections',action='store_true')
def run(ctx,args):
    ctx.require_native_database_dir(); a=['--structures-only'];
    if args.with_reference_seed: a.append('--with-reference-seed')
    return python_script(ctx,'tools/python/operations/database/a_db_init.py',a)
def rebuild(ctx,args):
    ctx.require_native_database_dir(); a=[]
    if args.skip_projections: a.append('--seed-skip-projections')
    return python_script(ctx,'tools/python/operations/database/a_db_init.py',a)
