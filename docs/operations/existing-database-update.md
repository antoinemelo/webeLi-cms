---
title: Mettre à jour une base existante
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
  - tools/python/commands/migrate.py
  - tools/python/operations/database/d9_migrate_sqlite.py
  - tools/python/operations/backup/d6_backup_sqlite.py
  - tools/python/lib/database_inventory.py
owners:
  - operations
  - core
document_type: procedure
permissions:
generated: false
---
# Mettre à jour une base existante

Cette procédure est la voie normale pour amener une installation existante, avec contenu, vers la dernière version des schémas SQLite livrés par la release courante. Elle remplace la logique de reconstruction systématique : les bases sont conservées, sauvegardées, puis migrées par fichiers SQL numérotés.

## Principe

```text
sauvegarde vérifiée
→ plan de migrations non mutatif
→ application incrémentale des migrations manquantes
→ contrôles d’intégrité
→ validations
→ reconstructions explicites de projections si nécessaire
```

Chaque base concernée possède sa propre table `schema_migrations`. Le migrateur compare les fichiers présents dans les dossiers de migrations avec ce journal, applique uniquement les migrations manquantes et bloque si le checksum d’une migration déjà appliquée a changé.

## Bases couvertes

L’inventaire Python unifié couvre :

- les bases natives (`core`, `iam`, `forms`, `cookies`, `ai`) ;
- les bases de modules système déclarées par `backend/src/Modules/*/module.json` ;
- les bases de modules clients déclarées localement sous `local/modules/` et activées par `ops/modules.local.json`.

Les bases d’un module client doivent rester sous `storage/database/` pour être prises en compte par les sauvegardes, restaurations et migrations locales.

## Procédure standard

Depuis la racine de l’instance à mettre à jour :

```bash
python3 tools/cms.py backup --output storage/backups/manual-before-migrate.zip
python3 tools/cms.py migrate --plan
python3 tools/cms.py migrate --apply --backup --yes
python3 tools/cms.py validate --category database
python3 tools/cms.py validate --category operations
```

`migrate --plan` ne modifie aucun fichier SQLite. Si une base ne possède pas encore `schema_migrations`, le plan signale que le journal sera créé lors de l’application, sans le créer immédiatement.

`migrate --apply` refuse de s’exécuter sans `--backup` ou sans l’option volontairement longue `--no-backup-i-understand-the-risk`. Cette dernière est réservée à une procédure contrôlée disposant déjà d’un backup externe vérifié.

## Cibler une base ou un module

```bash
python3 tools/cms.py migrate --database core --plan
python3 tools/cms.py migrate --database core --apply --backup --yes
python3 tools/cms.py migrate --module forms --plan
python3 tools/cms.py migrate --module forms --apply --backup --yes
```

Le comportement par défaut de `migrate --plan` couvre toutes les bases migratables connues. Le ciblage sert au diagnostic ou à une intervention limitée.

## Mise à jour complète d’une instance client

Lorsque la mise à jour concerne aussi les fichiers du noyau, utilisez le flux d’instance :

```bash
python3 tools/cms.py instance update --source /chemin/release.zip --target /chemin/site-client --plan
python3 tools/cms.py instance update --source /chemin/release.zip --target /chemin/site-client --apply --backup --yes
```

Ce flux protège les contenus, médias, bases SQLite, secrets et modules clients locaux, puis applique les migrations avec le code de la release.

## Projections, recherche et données dérivées

Les migrations doivent garder les données sources cohérentes. Les reconstructions de projections ou d’index restent des opérations explicites de maintenance lorsque la release ou une procédure de support les demande. Elles ne remplacent pas les migrations.

Exemples de cas où une reconstruction contrôlée peut être utile :

- correction d’un index de recherche dérivé ;
- changement de format d’une projection publique ;
- récupération après interruption d’une tâche de publication.

## Retour arrière

Il n’y a pas de down migration automatique. En cas de problème bloquant :

1. stoppez les écritures ;
2. restaurez le backup SQLite vérifié ;
3. restaurez le code compatible si une mise à jour de release a aussi été appliquée ;
4. relancez les validations.

```bash
python3 tools/cms.py backup --restore storage/backups/manual-before-migrate.zip --yes
python3 tools/cms.py validate
```

## Rôle de `rebuild`

`python3 tools/cms.py rebuild` reste disponible pour le développement, les tests, les démonstrations et les récupérations contrôlées après sauvegarde. Il est destructif et ne doit pas être utilisé comme mécanisme de mise à jour d’une installation contenant du contenu utile.
