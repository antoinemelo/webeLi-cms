from __future__ import annotations

from tools.python.lib.database_inventory import native_database_names
from tools.python.validation.checks import require_paths, require_tokens
from tools.python.validation.model import ValidationReport

VALIDATOR_ID = 'OPERATIONS_MANIFESTS'
DOMAIN = 'operations'
MODES = ("fast", "full")


def validate(mode: str = "fast") -> ValidationReport:
    r = ValidationReport(VALIDATOR_ID, DOMAIN, mode)
    require_paths(r, [
        "tools/python/commands/backup.py",
        "tools/python/commands/migrate.py",
        "tools/python/commands/export.py",
        "tools/python/operations/backup/d6_backup_sqlite.py",
        "tools/python/operations/backup/d7_restore_sqlite.py",
        "tools/python/operations/database/d9_migrate_sqlite.py",
        "tools/python/operations/deployment/d0_prepare_release.py",
    ])
    require_tokens(r, "tools/python/cms/runtime.py", ["dry_run"], "OPS-001")
    require_tokens(r, "tools/python/cms/cli.py", ["migrate.configure", "'migrate': migrate.run"], "OPS-002")
    require_tokens(r, "tools/python/operations/backup/d6_backup_sqlite.py", ["native_database_names(backup=True)"], "OPS-003")
    require_tokens(r, "tools/python/operations/backup/d7_restore_sqlite.py", ["native_database_names(backup=True)"], "OPS-004")
    require_tokens(r, "tools/python/operations/database/d9_migrate_sqlite.py", ["native_database_specs(migratable=True)", "ensure_migration_log"], "OPS-005")
    r.checked()
    if "ai.sqlite" not in native_database_names(backup=True):
        r.add("OPS-006", "ai.sqlite absent de l'inventaire backup")
    return r
