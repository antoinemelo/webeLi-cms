---
title: Tester, valider et maintenir la documentation
audience:
  - developer
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: code
owners:
  - core
document_type: guide
permissions:
source_paths:
  - tools/cms.py
  - tools/python/generators
  - `python3 tools/cms.py docs check`
generated: false
---
# Tester, valider et maintenir la documentation

Exécutez les tests ciblés, `python3 tools/cms.py validate`, `python3 tools/cms.py docs generate`, `docs check`, `docs evaluation-generate` et `evaluation-check`. Une modification de route, permission, schéma, commande, variable ou release doit mettre à jour la source générée.

Les pages manuelles déclarent `source_paths` et `last_verified`. Aucun guide public ne pointe vers `internal/`.

## Niveaux d'exécution

- `python3 tools/cms.py validate` exécute les invariants rapides, locaux et déterministes.
- `python3 tools/cms.py validate --full` ajoute les contrôles d’intégrité runtime, sans reconstruire les bases.
- `python3 tools/cms.py validate --full --with-slow` ajoute les qualifications lentes, notamment `BACKUP_RESTORE_ROUNDTRIP` et `STATIC_EXPORT_DRY_RUN`.

`--with-slow` implique le niveau complet. Aucun validateur ne reconstruit les bases du projet ni une copie temporaire de celles-ci. La reconstruction est une opération administrative indépendante : `python3 tools/cms.py rebuild`.

## Porte de qualité avant release

La chaîne de release exécute une qualification dédiée avant le préflight et le packaging :

```bash
python3 tools/cms.py qualify --profile release
```

Cette qualification ne reconstruit jamais les bases de données.

Les qualifications lentes couvrent notamment l’intégrité des bases actives, la cohérence des projections publiques, le vrai mécanisme de backup, ainsi qu’un dry-run de l’export statique. Ces contrôles remplacent les anciens validateurs numérotés de production sans réintroduire les recherches de textes, classes CSS ou détails d’interface non contractuels.

## Tests spécialisés

- [Tests de charge traçables](load-testing.md) : campagnes HTTP manuelles sous `tools/tests/`, hors suites automatisées `tools/cms.py test`.
