from __future__ import annotations

import atexit
import os
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path


ROOT = Path(__file__).resolve().parents[4]
DB_INIT = ROOT / "tools/python/operations/database/a_db_init.py"
_template_holder: tempfile.TemporaryDirectory[str] | None = None
_template_path: Path | None = None


def full_seeded_database_template() -> Path:
    """Construit une fois le jeu complet utilisé par les tests historiques."""
    global _template_holder, _template_path
    if _template_path is not None:
        return _template_path

    holder = tempfile.TemporaryDirectory(prefix="webeli-full-test-databases-")
    target = Path(holder.name)
    env = os.environ.copy()
    env.update(
        {
            "CMS_DATABASE_DIR": str(target),
            "APP_ENV": "test",
            "APP_BASE_PATH": "/cms",
            "APP_ALLOW_LOCAL_HOSTS": "1",
        }
    )
    completed = subprocess.run(
        [sys.executable, str(DB_INIT), "--seed-skip-projections"],
        cwd=ROOT,
        env=env,
        text=True,
        capture_output=True,
        timeout=180,
    )
    if completed.returncode != 0:
        holder.cleanup()
        raise RuntimeError(
            "Impossible de préparer les bases complètes isolées de test.\n"
            + completed.stdout
            + "\n"
            + completed.stderr
        )

    _template_holder = holder
    _template_path = target
    return target


def copy_full_seeded_databases(
    *, prefix: str = "webeli-test-databases-"
) -> tuple[tempfile.TemporaryDirectory[str], Path]:
    """Retourne une copie jetable du jeu complet sans toucher aux bases de /dev."""
    source = full_seeded_database_template()
    holder = tempfile.TemporaryDirectory(prefix=prefix)
    target = Path(holder.name)
    for database in source.glob("*.sqlite"):
        shutil.copy2(database, target / database.name)
    return holder, target


def _cleanup_template() -> None:
    global _template_holder, _template_path
    if _template_holder is not None:
        _template_holder.cleanup()
    _template_holder = None
    _template_path = None


atexit.register(_cleanup_template)
