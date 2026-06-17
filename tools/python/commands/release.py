from tools.python.cms.runtime import python_script

def configure(p):
    p.add_argument('--ci',action='store_true',help='Exécuter la chaîne CI stable.')
    p.add_argument('--interactive-prepare',action='store_true',help='Lance d0_prepare_release.py, puis poursuit automatiquement avec le préflight et le packaging.')
    p.add_argument('--build-admin',action='store_true'); p.add_argument('--run-essential-validators',action='store_true'); p.add_argument('--package',action='store_true'); p.add_argument('--verify-archive',action='store_true'); p.add_argument('--deploy',choices=['ftp','sftp'])
    p.add_argument('--skip-preflight',action='store_true'); p.add_argument('--exclude-databases',action='store_true'); p.add_argument('--include-vendor',action='store_true'); p.add_argument('--no-zip',action='store_true'); p.add_argument('--clean-stage',action='store_true')
def run(ctx,args):
    ctx.require_native_database_dir()
    # La préparation interactive appartient à la même chaîne d_ que le
    # préflight et le packaging. Un seul orchestrateur garde donc la main
    # jusqu'à la fin de la release.
    if args.ci or args.build_admin or args.run_essential_validators or args.package or args.verify_archive or args.deploy:
        a=[]
        for attr,flag in [('build_admin','--build-admin'),('run_essential_validators','--run-essential-validators'),('package','--package'),('verify_archive','--verify-archive'),('include_vendor','--include-vendor')]:
            if getattr(args,attr): a.append(flag)
        if args.deploy:a += ['--deploy',args.deploy]
        if ctx.dry_run:a.append('--plan-only')
        return python_script(ctx,'tools/python/operations/deployment/d11_ci_release.py',a)
    a=['release']
    if args.interactive_prepare:
        a.append('--prepare')
    for flag,name in [('skip_preflight','--skip-preflight'),('exclude_databases','--exclude-databases'),('include_vendor','--include-vendor'),('no_zip','--no-zip'),('clean_stage','--clean-stage')]:
        if getattr(args,flag):a.append(name)
    if ctx.dry_run:a.append('--plan-only')
    return python_script(ctx,'tools/python/operations/deployment/d_deploy.py',a)
