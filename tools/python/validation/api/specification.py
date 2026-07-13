from __future__ import annotations
import json
import re
from tools.python.validation.checks import *
from tools.python.validation.model import ValidationReport

VALIDATOR_ID='API_SPEC'
DOMAIN='api'
MODES=("fast","full")


def _schema_columns(table: str) -> set[str]:
    schema_path = ROOT / "database/schema/core.sql"
    text = schema_path.read_text(encoding="utf-8", errors="ignore")
    match = re.search(rf"CREATE\s+TABLE\s+{re.escape(table)}\s*\((.*?)\n\);", text, re.IGNORECASE | re.DOTALL)
    if not match:
        return set()
    columns: set[str] = set()
    for raw_line in match.group(1).splitlines():
        line = raw_line.strip().rstrip(',')
        if not line or line.startswith(("FOREIGN ", "PRIMARY ", "UNIQUE", "CHECK", "CONSTRAINT")):
            continue
        name = line.split(maxsplit=1)[0].strip('"`[]')
        if name:
            columns.add(name)
    return columns


def _validate_content_type_column_references(r: ValidationReport) -> None:
    """Catch live API SQL regressions caused by references to missing columns.

    The public API is intentionally validated against the real SQLite schema,
    because generated route/contract documentation can stay green even when an
    endpoint performs a JOIN on a column that does not exist at runtime.
    """
    columns = _schema_columns("content_types")
    r.checked()
    if not columns:
        r.add("API-020", "Impossible de lire les colonnes de content_types", path="database/schema/core.sql")
        return

    for path in sorted((ROOT / "backend/src").rglob("*.php")):
        rel = path.relative_to(ROOT).as_posix()
        text = path.read_text(encoding="utf-8", errors="ignore")
        if "content_types" not in text:
            continue

        aliases = set()
        for match in re.finditer(r"\b(?:FROM|JOIN)\s+content_types\s+(?:AS\s+)?([A-Za-z_][A-Za-z0-9_]*)", text, re.IGNORECASE):
            alias = match.group(1)
            if alias.upper() not in {"WHERE", "ON", "JOIN", "LEFT", "RIGHT", "INNER", "OUTER", "LIMIT", "ORDER", "GROUP"}:
                aliases.add(alias)

        for alias in aliases:
            for column in sorted(set(re.findall(rf"\b{re.escape(alias)}\.([A-Za-z_][A-Za-z0-9_]*)\b", text))):
                r.checked()
                if column not in columns:
                    r.add("API-021", "Référence SQL vers une colonne absente de content_types", path=rel, alias=alias, column=column)

        # Also catch the unaliased form that caused the live API failure:
        # "FROM content_types WHERE ... AND is_active = 1". Keep this check
        # intentionally narrow to avoid parsing every SQL string in PHP.
        for column in sorted(set(re.findall(r"\bcontent_types\b\s+WHERE\b[^;\n]{0,240}\b(is_active)\b", text, re.IGNORECASE))):
            r.checked()
            if column not in columns:
                r.add("API-022", "Référence SQL non aliasée vers une colonne absente de content_types", path=rel, column=column)


def _module_public_headless_routes() -> list[tuple[str, str, str]]:
    routes: list[tuple[str, str, str]] = []
    modules_dir = ROOT / "backend/src/Modules"
    if not modules_dir.exists():
        return routes
    for path in sorted(modules_dir.glob("*/*ModuleProvider.php")):
        text = path.read_text(encoding="utf-8", errors="ignore")
        match = re.search(r"public\s+function\s+publicHeadlessRoutes\s*\([^)]*\)\s*:\s*array\s*\{(?P<body>.*?)\n\s*\}", text, re.DOTALL)
        if not match:
            continue
        rel = path.relative_to(ROOT).as_posix()
        for method, route_path in re.findall(r"\$this->route\(\s*'([A-Z]+)'\s*,\s*'([^']+)'", match.group("body")):
            if route_path == "/api/v1" or route_path.startswith("/api/v1/"):
                routes.append((method, route_path, rel))
    return routes


def _public_runtime_routes() -> list[tuple[str, str, str]]:
    routes: list[tuple[str, str, str]] = []
    for method, path, _handler in php_route_entries("backend/routes/api.php"):
        if path == "/api/v1" or path.startswith("/api/v1/"):
            routes.append((method, path, "backend/routes/api.php"))
    routes.extend(_module_public_headless_routes())
    return sorted(routes, key=lambda item: (item[1], item[0], item[2]))


def _openapi_operations(rel: str) -> set[tuple[str, str]]:
    path = ROOT / rel
    if not path.is_file():
        return set()
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return set()
    operations: set[tuple[str, str]] = set()
    paths = data.get("paths", {})
    if not isinstance(paths, dict):
        return operations
    for route_path, methods in paths.items():
        if not isinstance(route_path, str) or not isinstance(methods, dict):
            continue
        for method in methods:
            if str(method).upper() in {"GET", "POST", "PUT", "PATCH", "DELETE", "OPTIONS"}:
                operations.add((str(method).upper(), route_path))
    return operations


def _validate_public_openapi_alignment(r: ValidationReport) -> None:
    openapi_rel = "docs/public-api/openapi.v1.json"
    reference_rel = "docs/reference/contracts/public-api/openapi.v1.json"
    require_paths(r, [openapi_rel, reference_rel, "packages/amcms-client/src/generated/openapi-types.ts"], code="API-030")

    runtime = {(method, path) for method, path, _source in _public_runtime_routes()}
    documented = _openapi_operations(openapi_rel)
    reference = _openapi_operations(reference_rel)

    for method, path, source in _public_runtime_routes():
        r.checked()
        if (method, path) not in documented:
            r.add("API-031", "Route publique runtime absente de l’OpenAPI public", path=source, method=method, route=path)

    for method, path in sorted(documented):
        r.checked()
        if (method, path) not in runtime:
            r.add("API-032", "Opération OpenAPI absente du runtime public", path=openapi_rel, method=method, route=path)

    r.checked()
    if documented != reference:
        missing = sorted(documented - reference)
        extra = sorted(reference - documented)
        r.add("API-033", "OpenAPI public et OpenAPI de référence divergents", path=reference_rel, missing_in_reference=missing[:20], extra_in_reference=extra[:20])

    sdk = ROOT / "packages/amcms-client/src/generated/openapi-types.ts"
    sdk_text = sdk.read_text(encoding="utf-8", errors="ignore") if sdk.is_file() else ""
    for token in [
        "OpenApiPublicSaleBootstrapResponse",
        "OpenApiPublicSaleCartResponse",
        "OpenApiPublicSaleCartLineMutationResponse",
        "OpenApiPublicSaleCartLineDeleteResponse",
        "OpenApiPublicSaleCheckoutResponse",
    ]:
        r.checked()
        if token not in sdk_text:
            r.add("API-034", "Type SDK généré manquant pour l’API publique Sale", path=sdk.relative_to(ROOT).as_posix(), token=token)


def validate(mode: str="fast") -> ValidationReport:
    r=ValidationReport(VALIDATOR_ID,DOMAIN,mode)
    routes=[]
    for rel in ["backend/routes/api.php","backend/routes/admin.php","backend/routes/web.php"]:
        require_paths(r,[rel]); routes.extend((rel,*x) for x in php_route_entries(rel))
    seen=set()
    for rel,method,path,handler in routes:
        r.checked(); key=(method,path)
        if key in seen: r.add("API-002","Route dupliquée",path=rel,method=method,route=path)
        seen.add(key)
        if "@" not in handler: r.add("API-003","Handler de route invalide",path=rel,handler=handler)
    require_paths(r,["docs/reference/contracts/headless-v1/README.md"])
    _validate_content_type_column_references(r)
    _validate_public_openapi_alignment(r)
    return r
