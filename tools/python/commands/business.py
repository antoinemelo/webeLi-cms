from __future__ import annotations

from tools.python.cms.runtime import execute, resolve_php_binary

CATALOG_SMOKE_TESTS = (
    "tools/php/tests/unit/business_catalog_schema_test.php",
    "tools/php/tests/unit/business_catalog_pricing_service_test.php",
    "tools/php/tests/unit/business_catalog_api_controller_test.php",
    "tools/php/tests/unit/business_catalog_public_api_test.php",
    "tools/php/tests/unit/business_pos_catalog_api_test.php",
    "tools/php/tests/unit/business_catalog_csv_service_test.php",
)


def configure(parser) -> None:
    sub = parser.add_subparsers(dest="business_action", required=True)
    smoke = sub.add_parser("smoke", help="Exécuter les smoke tests ciblés du module Business.")
    smoke.add_argument(
        "--suite",
        choices=("catalog",),
        default="catalog",
        help="Suite Business à exécuter (défaut: catalog).",
    )


def run(ctx, args) -> int:
    if args.business_action != "smoke":
        raise ValueError(f"Action Business inconnue: {args.business_action}")
    if args.suite != "catalog":
        raise ValueError(f"Suite Business inconnue: {args.suite}")

    php = resolve_php_binary()
    if not ctx.json_output:
        print(f"Business smoke catalogue: PHP {php}", flush=True)

    for test in CATALOG_SMOKE_TESTS:
        if not ctx.json_output:
            print(f"[RUN] {test}", flush=True)
        result = execute(ctx, [php, str(ctx.root / test)], timeout=min(ctx.command_timeout, 240))
        if result != 0:
            if not ctx.json_output:
                print(f"[FAILED] {test} ({result})", flush=True)
            return result

    if not ctx.json_output:
        print("Business smoke catalogue: OK", flush=True)
    return 0
