from tools.python.cms.runtime import python_script

E2E_TIMEOUT_SECONDS = 3600


def configure(parser) -> None:
    parser.add_argument('--install-browser', action='store_true', help='Installer Chromium pour Playwright puis quitter.')
    parser.add_argument('--headed', action='store_true', help='Afficher le navigateur pendant les tests.')
    parser.add_argument('--keep-instance', action='store_true', help='Conserver l’instance temporaire pour diagnostic.')
    parser.add_argument('--use-built-assets', action='store_true', help='Utiliser les assets compilés par une étape précédente.')
    parser.add_argument('--omnichannel-only', action='store_true', help='Exécuter uniquement la gate storefront/POS et valider son rapport JSON.')
    parser.add_argument('--usability-only', action='store_true', help='Exécuter uniquement la gate d’utilisabilité Commerce M5–M7 et valider son rapport JSON.')
    parser.add_argument('--admin-convergence-only', action='store_true', help='Exécuter uniquement la gate UX de convergence admin 38e et valider son rapport JSON.')
    parser.add_argument('--shop-operational-only', action='store_true', help='Exécuter uniquement la gate release Shop opérationnel 48 et valider son rapport JSON.')
    parser.add_argument('--spec', action='append', default=[], help='Exécuter un fichier E2E précis sur une instance fraîche; option répétable.')


def run(ctx, args) -> int:
    flags: list[str] = []
    if args.install_browser:
        flags.append('--install-browser')
    if args.headed:
        flags.append('--headed')
    if args.keep_instance:
        flags.append('--keep-instance')
    if args.use_built_assets:
        flags.append('--use-built-assets')
    if args.omnichannel_only:
        flags.append('--omnichannel-only')
    if args.usability_only:
        flags.append('--usability-only')
    if args.admin_convergence_only:
        flags.append('--admin-convergence-only')
    if args.shop_operational_only:
        flags.append('--shop-operational-only')
    for spec in args.spec:
        flags.extend(['--spec', spec])
    return python_script(ctx, 'tools/python/operations/testing/run_playwright_e2e.py', flags, timeout=E2E_TIMEOUT_SECONDS)
