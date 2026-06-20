---
title: Mise à jour locale SQLite
audience:
  - installer
  - administrator
  - developer
status: stable
version: 1.0
last_verified: 2026-06-20
source_of_truth: procedure
source_paths:
  - tools/cms.py
  - tools/python/lib/database_inventory.py
  - tools/python/operations/database/d9_migrate_sqlite.py
  - tools/python/operations/backup/d6_backup_sqlite.py
  - tools/python/operations/backup/d7_restore_sqlite.py
owners:
  - operations
document_type: procedure
permissions:
generated: false
---
# Mise à jour locale SQLite

Cette procédure garde le contrôle des mises à jour dans les outils Python locaux du projet. Elle s’applique aux bases SQLite natives déclarées dans `tools/python/lib/database_inventory.py` : `core.sqlite`, `iam.sqlite`, `forms.sqlite`, `cookies.sqlite` et `ai.sqlite`.

## Flux recommandé

1. Créer une sauvegarde SQLite vérifiée.
2. Afficher le plan de migrations.
3. Appliquer les migrations avec sauvegarde préalable.
4. Exécuter les validateurs.
5. Restaurer la sauvegarde si un problème bloquant apparaît.

## Commandes

Depuis la racine du projet :

```bash
python3 tools/cms.py backup
python3 tools/cms.py migrate --plan
python3 tools/cms.py migrate --apply --backup
python3 tools/cms.py validate --category database
python3 tools/cms.py validate --category operations
```

Pour cibler une seule base native :

```bash
python3 tools/cms.py migrate --database ai --plan
python3 tools/cms.py migrate --database ai --apply --backup
```

## Règles importantes

- Les migrations sont incrémentales et ne sont appliquées qu’une seule fois par base grâce à `schema_migrations`.
- Chaque base native doit posséder sa propre table `schema_migrations`.
- Les migrations sont déposées dans `database/migrations/<scope>/`.
- Aucune down migration n’est prévue : le retour arrière se fait par restauration d’un backup vérifié.
- Les bases existantes avec contenu ne doivent pas être reconstruites avec `rebuild`.

## Restauration

En cas de problème :

```bash
python3 tools/cms.py backup --restore "/chemin/archive-sqlite.zip" --yes
python3 tools/cms.py validate --category database
```

`--no-safety-copy` ne doit être utilisé que si une autre copie de sécurité vérifiée existe déjà.
