---
title: Migrations SQLite des modules
audience:
  - developer
  - installer
  - administrator
status: stable
last_verified: 2026-06-20
source_of_truth: procedure
source_paths:
  - tools/python/lib/database_inventory.py
  - tools/python/commands/migrate.py
  - tools/python/operations/database/d9_migrate_sqlite.py
  - backend/src/Modules/Forms/module.json
  - backend/src/Modules/AiAssistant/module.json
owners:
  - core
  - operations
document_type: procedure
generated: false
---
# Migrations SQLite des modules

Les migrations restent locales, incrémentales et tracées dans chaque base par `schema_migrations`. Aucune base globale de suivi n’est ajoutée.

## Scopes migratables

```bash
python3 tools/cms.py migrate --plan
python3 tools/cms.py migrate --database core --plan
python3 tools/cms.py migrate --module forms --plan
```

Le mode par défaut couvre toutes les bases migratables connues : bases natives, bases de modules système et bases de modules clients activés localement.

## Application

```bash
python3 tools/cms.py migrate --module forms --apply --backup --yes
```

`--apply` refuse de s’exécuter sans `--backup` ou sans l’option longue `--no-backup-i-understand-the-risk`. Cette seconde option est réservée à une procédure contrôlée disposant d’un backup externe vérifié.

## Checksum

Lorsqu’une migration appliquée possède un checksum, le migrateur détecte toute modification ultérieure du fichier. Une migration déjà appliquée doit rester immuable ; ajoutez une nouvelle migration corrective si nécessaire.

## Modules clients

Un module client activé dans `ops/modules.local.json` peut déclarer :

```json
{
  "key": "client_example",
  "path": "storage/database/client_example.sqlite",
  "schema": "local/modules/client-example/database/schema.sql",
  "migrations": "local/modules/client-example/database/migrations"
}
```

La base doit rester sous `storage/database/` pour être sauvegardée et restaurée par les outils locaux.
