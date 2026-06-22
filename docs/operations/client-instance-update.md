---
title: Mettre à jour une instance client avec modules locaux
audience:
  - installer
  - operator
  - administrator
  - developer
status: stable
last_verified: 2026-06-20
source_of_truth: procedure
source_paths:
  - tools/python/commands/instance.py
  - tools/python/operations/deployment/d8_deploy_web_update.py
  - tools/python/operations/backup/d6_backup_sqlite.py
  - tools/python/operations/database/d9_migrate_sqlite.py
owners:
  - operations
  - documentation
document_type: procedure
generated: false
---
# Mettre à jour une instance client avec modules locaux

La commande `instance update` déploie une release du noyau sur une instance client en conservant les contenus, les bases SQLite, les médias, les sauvegardes, les secrets et les modules clients locaux.

## Plan non mutatif

```bash
python3 tools/cms.py instance update \
  --source /chemin/release.zip \
  --target /chemin/site-client \
  --plan
```

Le plan calcule le delta fichiers et le plan de migrations sur une copie temporaire des bases de la cible. Il ne modifie pas l’instance.

## Application contrôlée

```bash
python3 tools/cms.py instance update \
  --source /chemin/release.zip \
  --target /chemin/site-client \
  --apply --backup --yes
```

L’application crée un backup SQLite, applique le delta fichiers avec archive de rollback, exécute les migrations, lance des validations essentielles et écrit un journal JSON dans `storage/operations/instance-updates/`.

## Chemins protégés

Les chemins suivants ne doivent pas être écrasés par une release core :

- `storage/database/` ;
- `storage/media/` ;
- `storage/uploads/` ;
- `storage/logs/` ;
- `storage/backups/` ;
- `ops/.env` ;
- `ops/modules.local.json` ;
- `local/modules/`.

`backend/src/Modules/` appartient au noyau et aux modules système livrés officiellement.

## Rollback

Le rollback fichiers utilise l’archive produite par le déployeur. Le rollback base utilise la restauration du backup SQLite. Il n’y a pas de down migration automatique.
