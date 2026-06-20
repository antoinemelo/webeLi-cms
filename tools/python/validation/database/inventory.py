from __future__ import annotations

from tools.python.lib.database_inventory import database_specs, migration_scopes, native_database_specs
from tools.python.validation.checks import ROOT
from tools.python.validation.model import ValidationReport

VALIDATOR_ID = 'DB_INVENTORY'
DOMAIN = 'database'
MODES = ("fast", "full")

EXPECTED_KEYS = {"core", "iam", "forms", "cookies", "ai"}
EXPECTED_NAMES = {"core.sqlite", "iam.sqlite", "forms.sqlite", "cookies.sqlite", "ai.sqlite"}


def validate(mode: str = "fast") -> ValidationReport:
    r = ValidationReport(VALIDATOR_ID, DOMAIN, mode)
    native_specs = native_database_specs()
    keys = {spec.key for spec in native_specs}
    names = {spec.name for spec in native_specs}
    r.checked(2)
    if keys != EXPECTED_KEYS:
        r.add("DB-006", "Inventaire SQLite natif incomplet ou divergent", details={"expected": sorted(EXPECTED_KEYS), "found": sorted(keys)})
    if names != EXPECTED_NAMES:
        r.add("DB-007", "Noms de bases SQLite natives incomplets ou divergents", details={"expected": sorted(EXPECTED_NAMES), "found": sorted(names)})

    for spec in native_specs:
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
    scopes = set(migration_scopes(ROOT))
    missing = EXPECTED_KEYS - scopes
    if missing:
        r.add("DB-013", "Bases natives absentes des scopes migratables", details={"missing": sorted(missing)})

    unified = database_specs(root=ROOT)
    r.checked(2)
    if len({spec.key for spec in unified}) != len(unified):
        r.add("DB-014", "Clé de base dupliquée dans l'inventaire unifié")
    if len({spec.path for spec in unified}) != len(unified):
        r.add("DB-015", "Chemin de base dupliqué dans l'inventaire unifié")
    for spec in unified:
        r.checked(3)
        if spec.kind == "client_module" and not spec.path.startswith("storage/database/"):
            r.add("DB-016", "Base client hors storage/database", database=spec.key, path=spec.path)
        if spec.kind == "client_module" and spec.required and not (ROOT / spec.path).is_file():
            r.add("DB-018", "Base déclarée par un module client activé absente", database=spec.key, module=spec.module_key, path=spec.path)
        migrations_path = spec.absolute_migrations_path(ROOT)
        if spec.migratable and migrations_path and not migrations_path.is_dir():
            r.add("DB-017", "Dossier de migrations introuvable", database=spec.key, path=migrations_path.relative_to(ROOT).as_posix())
    r.checked()
    if any(spec.module_key == "client-notes" for spec in unified):
        r.add("DB-019", "Le module exemple client ne doit pas apparaître dans l’inventaire runtime", database="client_notes")
    return r
