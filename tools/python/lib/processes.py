"""Helpers partagés pour l'exécution des processus externes DEC CMS."""
from __future__ import annotations

import os
from pathlib import Path
from typing import Mapping


def cms_subprocess_env(
    *,
    app_base_path: str | None = None,
    extra: Mapping[str, str] | None = None,
) -> dict[str, str]:
    """Retourne un environnement stable pour les commandes CLI du CMS.

    En PHP CLI, ``SCRIPT_NAME`` contient le chemin disque du script. Sans
    ``APP_BASE_PATH`` explicite, ce chemin pouvait être interprété comme un
    préfixe URL et être persisté dans les projections publiques. Le défaut
    historique du projet est ``/cms``; une valeur fournie par l'environnement
    reste prioritaire.
    """
    env = os.environ.copy()
    configured = app_base_path
    if configured is None:
        configured = env.get("APP_BASE_PATH", "/cms")
    configured = str(configured).strip()
    if configured in {"", "/"}:
        env["APP_BASE_PATH"] = ""
    else:
        env["APP_BASE_PATH"] = "/" + configured.strip("/")
    if extra:
        env.update({str(key): str(value) for key, value in extra.items()})
    return env
