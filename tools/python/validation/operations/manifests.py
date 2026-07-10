from __future__ import annotations

from tools.python.lib.database_inventory import database_specs, native_database_names
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
    require_tokens(r, "tools/python/operations/backup/d6_backup_sqlite.py", ["database_specs(root=ROOT, backup=True)", "module_key", "integrity_check"], "OPS-003")
    require_tokens(r, "tools/python/operations/backup/d7_restore_sqlite.py", ["backup-manifest.json", "storage/database/", "safety_copy"], "OPS-004")
    require_tokens(r, "tools/python/operations/database/d9_migrate_sqlite.py", ["database_specs(root=ROOT, migratable=True)", "--module", "checksum", "--no-backup-i-understand-the-risk"], "OPS-005")
    require_tokens(r, "tools/python/commands/migrate.py", ["--module", "--no-backup-i-understand-the-risk"], "OPS-007")
    r.checked()
    if "ai.sqlite" not in native_database_names(backup=True):
        r.add("OPS-006", "ai.sqlite absent de l'inventaire backup")

    specs = database_specs(backup=True)
    r.checked(2)
    if not {"core", "iam", "forms", "cookies", "ai"}.issubset({spec.key for spec in specs}):
        r.add("OPS-008", "Les bases natives ne sont pas toutes couvertes par l'inventaire de backup")
    for spec in specs:
        r.checked()
        if spec.kind == "client_module" and not spec.path.startswith("storage/database/"):
            r.add("OPS-009", "Base de module client hors storage/database", database=spec.key, path=spec.path)
    return r
