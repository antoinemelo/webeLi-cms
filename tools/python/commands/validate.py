from tools.python.validation.registry import VALIDATORS
from tools.python.validation.runner import run_validators

def configure(p):
    p.add_argument('--category', action='append', choices=['configuration','database','content','permissions','api','operations','security','documentation','shared','qualification'], help='Domaine à exécuter; répétable.')
    p.add_argument('--validator', action='append', default=[], help='Identifiant stable du validateur; répétable.')
    p.add_argument('--full', action='store_true', help='Ajoute les qualifications déterministes plus coûteuses, notamment la reconstruction temporaire des bases.')
    p.add_argument('--with-slow', action='store_true', help='Ajoute aux contrôles complets les qualifications longues, notamment le round-trip sauvegarde/restauration.')
    p.add_argument('--list', action='store_true', help='Lister le registre sans exécuter.')
    p.add_argument('--no-fail-fast', action='store_true', help='Exécuter toute la sélection malgré les erreurs.')
    p.add_argument('--plan-only', action='store_true', help='Afficher le plan déterministe.')
    p.add_argument('--require-vue-build', action='store_true', help='Option dépréciée, sans effet.')

def run(ctx,args):
    if args.list:
        import json
        rows=[{'name':v.name,'module':v.module,'domain':v.domain,'modes':list(v.modes)} for v in VALIDATORS]
        print(json.dumps(rows,ensure_ascii=False,indent=2) if ctx.json_output else '\n'.join(f"{r['name']}: {r['domain']} [{', '.join(r['modes'])}]" for r in rows)); return 0
    mode = 'slow' if args.with_slow else ('full' if args.full else 'fast')
    return run_validators(categories=tuple(args.category or ()),names=tuple(args.validator or ()),json_output=ctx.json_output,plan_only=args.plan_only or ctx.dry_run,fail_fast=not args.no_fail_fast,mode=mode)
