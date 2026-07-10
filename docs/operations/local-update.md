---
title: Mise à jour locale SQLite
audience:
  - installer
  - administrator
  - developer
status: stable
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
generated: false
---
# Mise à jour locale SQLite

Cette procédure garde le contrôle des mises à jour dans les outils Python locaux du projet. Elle s’applique à l’inventaire SQLite unifié déclaré par `tools/python/lib/database_inventory.py` : bases natives, bases de modules système et bases de modules clients activées localement par `ops/modules.local.json`. Pour mettre à jour une release complète du noyau sur une instance client, utiliser d’abord `tools/cms.py instance update`.

## Flux recommandé pour une release complète

```bash
python3 tools/cms.py instance update --source /chemin/release.zip --target /chemin/site-client --plan
python3 tools/cms.py instance update --source /chemin/release.zip --target /chemin/site-client --apply --backup --yes
```

Ce flux conserve les bases, médias, secrets et modules locaux, puis applique les migrations avec backup.

## Flux manuel migrations uniquement

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
python3 tools/cms.py migrate --apply --backup --yes
python3 tools/cms.py validate --category database
python3 tools/cms.py validate --category operations
```

Pour cibler une seule base native :

```bash
python3 tools/cms.py migrate --database ai --plan
python3 tools/cms.py migrate --database ai --apply --backup --yes
python3 tools/cms.py migrate --module forms --plan
```

## Règles importantes

- Les migrations sont incrémentales et ne sont appliquées qu’une seule fois par base grâce à `schema_migrations`.
- Chaque base native doit posséder sa propre table `schema_migrations`.
- Les migrations natives sont déposées dans `database/migrations/<scope>/`; les migrations de modules clients restent dans `local/modules/<module-key>/database/migrations/`.
- Les migrations appliquées peuvent être journalisées avec un `checksum`; si un fichier déjà appliqué est modifié, le migrateur bloque et demande une migration corrective.
- Aucune down migration n’est prévue : le retour arrière se fait par restauration d’un backup vérifié.
- En mode `--apply`, l’outil exige `--backup` ou l’option volontairement longue `--no-backup-i-understand-the-risk`.
- Les bases existantes avec contenu ne doivent pas être reconstruites avec `rebuild`.

## Restauration

En cas de problème :

```bash
python3 tools/cms.py backup --restore "/chemin/archive-sqlite.zip" --yes
python3 tools/cms.py validate --category database
```

`--no-safety-copy` ne doit être utilisé que si une autre copie de sécurité vérifiée existe déjà.

## Référence détaillée

Voir aussi [Mettre à jour une base existante](existing-database-update.md) pour la procédure complète de passage à la dernière version de schéma sur une installation avec contenu.
