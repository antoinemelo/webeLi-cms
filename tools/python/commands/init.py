from tools.python.cms.runtime import python_script

def configure(p): p.add_argument('--with-reference-seed',action='store_true')
def configure_rebuild(p):
    p.add_argument('--skip-projections',action='store_true')
    p.add_argument('--without-commerce-seed',action='store_true',help='Structures Business/Sale sans donnees initiales.')
    p.add_argument(
        '--without-core-seed',
        action='store_true',
        help='Ignore les contenus Core de demonstration et conserve le socle runtime minimal.',
    )
def run(ctx,args):
    ctx.require_native_database_dir(); a=['--structures-only'];
    if args.with_reference_seed: a.append('--with-reference-seed')
    return python_script(ctx,'tools/python/operations/database/a_db_init.py',a)
def rebuild(ctx,args):
    ctx.require_native_database_dir(); a=[]
    if args.skip_projections: a.append('--seed-skip-projections')
    if args.without_commerce_seed or args.without_core_seed: a.append('--without-commerce-seed')
    if args.without_core_seed: a.append('--without-core-seed')
    return python_script(ctx,'tools/python/operations/database/a_db_init.py',a)
