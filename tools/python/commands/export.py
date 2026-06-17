from tools.python.cms.runtime import php_console

def configure(p):
    p.add_argument('--site'); p.add_argument('--lang'); p.add_argument('--all-languages',action='store_true'); p.add_argument('--route'); p.add_argument('--output')
def run(ctx,args):
    arguments=[]
    if ctx.dry_run: arguments.append('--dry-run')
    if args.site: arguments.append('--site='+args.site)
    if args.lang: arguments.append('--lang='+args.lang)
    if args.all_languages: arguments.append('--all-languages')
    if args.route: arguments.append('--route='+args.route)
    if args.output: arguments.append('--output='+args.output)
    if ctx.dry_run:
        from dataclasses import replace
        return php_console(replace(ctx,dry_run=False),'static:export',arguments)
    return php_console(ctx,'static:export',arguments)
