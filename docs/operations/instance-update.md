---
title: Mettre à jour une instance client localement
audience:
  - operator
  - administrator
  - developer
status: stable
version: 1.0
last_verified: 2026-06-20
source_of_truth: procedure
source_paths:
  - tools/cms.py
  - tools/python/commands/instance.py
  - tools/python/operations/deployment/d8_deploy_web_update.py
  - tools/python/operations/backup/d6_backup_sqlite.py
  - tools/python/operations/database/d9_migrate_sqlite.py
owners:
  - operations
  - documentation
document_type: procedure
permissions:
generated: false
---
# Mettre à jour une instance client localement

La commande `instance update` applique une release du noyau sur une instance client sans écraser les contenus, les bases SQLite, les médias, les secrets ni les modules clients locaux.

Elle orchestre les outils locaux existants : plan fichiers, backup SQLite, mise à jour fichiers avec archive de rollback, migrations SQLite et validations essentielles. Elle ne remplace pas un déploiement distant complet et ne crée pas de base de suivi supplémentaire.

## Chemins protégés

Les chemins suivants sont conservés pendant la mise à jour fichiers :

- `storage/database/` ;
- `storage/media/` ;
- `storage/uploads/` ;
- `storage/logs/` ;
- `storage/backups/` ;
- `ops/.env` ;
- `ops/modules.local.json` ;
- `local/modules/`.

`backend/src/Modules/` reste géré par le noyau et les modules système livrés dans la release.

## Afficher le plan

Depuis une installation outillée :

```bash
python3 tools/cms.py instance update \
  --source /chemin/release.zip \
  --target /chemin/site-client \
  --plan
```

Le plan affiche :

- les fichiers ajoutés, modifiés, supprimés et ignorés car protégés ;
- la version de release lorsqu’elle est détectable ;
- le plan de migrations calculé sur une copie temporaire des bases de la cible avec le code de la release.

Le mode `--plan` ne modifie pas l’instance cible.

## Appliquer la mise à jour

```bash
python3 tools/cms.py instance update \
  --source /chemin/release.zip \
  --target /chemin/site-client \
  --apply --backup --yes
```

`--apply` exige `--backup` et `--yes`. Le backup SQLite est créé avant la copie des fichiers. Les migrations sont ensuite appliquées avec le migrateur local, qui crée aussi sa sauvegarde avant application.

## Fichiers obsolètes et maintenance

Deux options restent volontairement simples :

```bash
--delete-obsolete
--maintenance-flag
```

`--delete-obsolete` supprime les fichiers absents de la release, sauf chemins protégés. `--maintenance-flag` crée temporairement `storage/maintenance.flag` pendant la copie fichiers.

## Journal local

Chaque application écrit un journal JSON dans :

```text
storage/operations/instance-updates/<timestamp>-update.json
```

Le journal contient notamment la source, la cible, la version détectée, le résumé du delta fichiers, le backup SQLite, l’archive de rollback fichiers, le résultat des migrations, les validations et l’erreur éventuelle.

## Retour arrière

Le rollback fichiers reste l’archive produite par `d8_deploy_web_update.py`. Le rollback base se fait par restauration du backup SQLite. Il n’y a pas de down migration automatique.
