from __future__ import annotations

import argparse
import json
import sys
import time
from pathlib import Path

from .evidence import CommandEvidence
from .runtime import Context
from tools.python.commands import audit, backup, docs, export, init, qualify, release, test, validate
from tools.python.lib.release_metadata import load_release_metadata

COMMANDS = {'init': init.run, 'rebuild': init.rebuild, 'validate': validate.run, 'qualify': qualify.run, 'audit': audit.run, 'test': test.run, 'export': export.run, 'backup': backup.run, 'release': release.run, 'docs': docs.run}


def parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(prog='tools/cms.py', description='Façade stable des outils de maintenance DEC CMS.')
    p.add_argument('--root', help='Racine du projet CMS (défaut: répertoire courant).')
    p.add_argument('--database-dir', help='Répertoire des bases SQLite (défaut: storage/database).')
    p.add_argument('--json', action='store_true', help='Produit une enveloppe JSON stable.')
    p.add_argument('--dry-run', action='store_true', help='Affiche les actions sans modifier le projet.')
    p.add_argument('--command-timeout', type=int, default=600, help='Borne maximale par sous-processus en secondes (défaut: 600).')
    p.add_argument('--evidence-dir', help='Écrit une preuve JSON signée par SHA-256 pour la commande complète.')
    sub = p.add_subparsers(dest='command', required=True)
    init.configure(sub.add_parser('init', help='Créer les structures SQLite sans données métier.'))
    init.configure_rebuild(sub.add_parser('rebuild', help='Reconstruire les bases et appliquer les seeds natifs.'))
    validate.configure(sub.add_parser('validate', help='Exécuter les validateurs du CMS.'))
    qualify.configure(sub.add_parser('qualify', help='Qualifier globalement le CMS selon un profil.'))
    audit.configure(sub.add_parser('audit', help='Produire des preuves reproductibles dans le conteneur d audit.'))
    test.configure(sub.add_parser('test', help='Exécuter les tests automatisés Python.'))
    export.configure(sub.add_parser('export', help='Générer ou simuler un export statique.'))
    backup.configure(sub.add_parser('backup', help='Créer ou restaurer une sauvegarde SQLite.'))
    release.configure(sub.add_parser('release', help='Préparer et vérifier une release.'))
    docs.configure(sub.add_parser('docs', help='Générer ou vérifier la documentation de référence.'))
    return p


def _normalize_global_options(arguments: list[str]) -> list[str]:
    flags: list[str] = []
    rest: list[str] = []
    i = 0
    valued = {'--root', '--database-dir', '--command-timeout', '--evidence-dir'}
    while i < len(arguments):
        item = arguments[i]
        if item in {'--json', '--dry-run'}:
            flags.append(item); i += 1; continue
        if item in valued and i + 1 < len(arguments):
            flags.extend([item, arguments[i + 1]]); i += 2; continue
        if any(item.startswith(option + '=') for option in valued):
            flags.append(item); i += 1; continue
        rest.append(item); i += 1
    return flags + rest


def _version(root: Path) -> str:
    try:
        return load_release_metadata(root / 'config' / 'release.json').technical_version
    except Exception:
        return 'unknown'


def main(argv=None) -> int:
    raw = list(sys.argv[1:] if argv is None else argv)
    arguments = _normalize_global_options(raw)
    p = parser()
    if not arguments:
        p.print_help(); return 0
    args = p.parse_args(arguments)
    evidence: CommandEvidence | None = None
    ctx: Context | None = None
    code = 2
    summary = 'Commande non exécutée.'
    try:
        ctx = Context.build(args.root, args.database_dir, args.json, args.dry_run, args.evidence_dir, args.command_timeout)
        if ctx.evidence_dir:
            evidence = CommandEvidence.start(ctx.evidence_dir, ctx.root, [sys.executable, str(ctx.root / 'tools/cms.py'), *raw], _version(ctx.root))
        code = int(COMMANDS[args.command](ctx, args))
        summary = f"Commande {args.command} terminée avec le code {code}."
        return code
    except (ValueError, FileNotFoundError) as exc:
        summary = str(exc)
        if getattr(args, 'json', False):
            print(json.dumps({'status': 'error', 'returncode': 2, 'error': str(exc)}, ensure_ascii=False))
        else:
            print(f'ERREUR: {exc}', file=sys.stderr)
        code = 2
        return code
    except KeyboardInterrupt:
        summary = 'Commande interrompue.'
        code = 130
        return code
    except Exception as exc:
        summary = f'Erreur interne non masquée: {type(exc).__name__}: {exc}'
        if getattr(args, 'json', False):
            print(json.dumps({'status': 'error', 'returncode': 1, 'error': summary}, ensure_ascii=False))
        else:
            print(f'ERREUR: {summary}', file=sys.stderr)
        code = 1
        return code
    finally:
        if evidence is not None:
            evidence.finish(code, summary=summary)
