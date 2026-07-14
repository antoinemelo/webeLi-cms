from tools.python.cms.runtime import python_script


def configure(parser) -> None:
    parser.add_argument('--install-browser', action='store_true', help='Installer Chromium pour Playwright puis quitter.')
    parser.add_argument('--headed', action='store_true', help='Afficher le navigateur pendant les tests.')
    parser.add_argument('--keep-instance', action='store_true', help='Conserver l’instance temporaire pour diagnostic.')
    parser.add_argument('--use-built-assets', action='store_true', help='Utiliser les assets compilés par une étape précédente.')
    parser.add_argument('--omnichannel-only', action='store_true', help='Exécuter uniquement la gate storefront/POS et valider son rapport JSON.')
    parser.add_argument('--usability-only', action='store_true', help='Exécuter uniquement la gate d’utilisabilité Commerce M5–M7 et valider son rapport JSON.')


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
    return python_script(ctx, 'tools/python/operations/testing/run_playwright_e2e.py', flags, timeout=1200)
