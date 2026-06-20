from __future__ import annotations

from tools.python.lib.database_inventory import native_database_specs, migration_scopes
from tools.python.validation.checks import ROOT
from tools.python.validation.model import ValidationReport

VALIDATOR_ID = 'DB_INVENTORY'
DOMAIN = 'database'
MODES = ("fast", "full")

EXPECTED_KEYS = {"core", "iam", "forms", "cookies", "ai"}
EXPECTED_NAMES = {"core.sqlite", "iam.sqlite", "forms.sqlite", "cookies.sqlite", "ai.sqlite"}


def validate(mode: str = "fast") -> ValidationReport:
    r = ValidationReport(VALIDATOR_ID, DOMAIN, mode)
    specs = native_database_specs()
    keys = {spec.key for spec in specs}
    names = {spec.name for spec in specs}
    r.checked(2)
    if keys != EXPECTED_KEYS:
        r.add("DB-006", "Inventaire SQLite natif incomplet ou divergent", details={"expected": sorted(EXPECTED_KEYS), "found": sorted(keys)})
    if names != EXPECTED_NAMES:
        r.add("DB-007", "Noms de bases SQLite natives incomplets ou divergents", details={"expected": sorted(EXPECTED_NAMES), "found": sorted(names)})

    for spec in specs:
        r.checked(4)
        if not spec.schema_path:
            r.add("DB-008", "Schema initial non déclaré pour une base native", database=spec.key)
        elif not (ROOT / spec.schema_path).is_file():
            r.add("DB-009", "Schema initial introuvable pour une base native", database=spec.key, path=spec.schema_path)
        if spec.migratable and not spec.migration_scope:
            r.add("DB-010", "Base migratable sans scope de migration", database=spec.key)
        if spec.migration_scope and not (ROOT / "database" / "migrations" / spec.migration_scope).is_dir():
            r.add("DB-011", "Dossier de migrations introuvable pour une base native", database=spec.key, scope=spec.migration_scope)
        if spec.required_in_backup and spec.required_in_release and not spec.required:
            r.add("DB-012", "Base optionnelle marquée obligatoire en backup et release", database=spec.key, severity="warning")

    r.checked()
    scopes = set(migration_scopes())
    missing = EXPECTED_KEYS - scopes
    if missing:
        r.add("DB-013", "Bases natives absentes des scopes migratables", details={"missing": sorted(missing)})
    return r
