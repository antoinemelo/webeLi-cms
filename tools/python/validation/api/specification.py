from __future__ import annotations
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
    return r
