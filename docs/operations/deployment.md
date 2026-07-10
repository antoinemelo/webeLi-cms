---
title: Déployer une instance AM-CMS
audience:
  - administrator
  - developer
status: stable
last_verified: 2026-06-20
source_of_truth: procedure
source_paths:
  - tools/cms.py
  - tools/python/cms/cli.py
  - tools/python/operations/backup
  - tools/python/operations/database
  - tools/python/operations/deployment
owners:
  - operations
  - documentation
document_type: guide
generated: false
---

# Déployer une instance AM-CMS

Cette procédure décrit un déploiement simple contrôlé localement. Elle ne suppose ni service distant, ni pipeline obligatoire, ni marketplace de modules.

## Principe

Une instance de production doit être préparée depuis une release vérifiée, puis contrôlée avec la façade locale `tools/cms.py`. Les bases SQLite restent dans `storage/database/` et doivent être sauvegardées avant toute migration.

## Préparation locale

```bash
python3 tools/cms.py validate --category configuration --category database --category operations --category documentation
python3 tools/cms.py qualify --profile quick
python3 tools/cms.py release --help
```

## Avant intervention sur une instance existante

```bash
python3 tools/cms.py backup --help
python3 tools/cms.py migrate --plan
python3 tools/cms.py instance update --help
```

La commande de backup doit être exécutée avant toute migration appliquée. Pour une mise à jour de release sur une instance client, privilégier le flux local `instance update`, qui protège les bases, les médias, les secrets et les modules clients locaux.

## Mise à jour locale d’une instance client

```bash
python3 tools/cms.py instance update --source /chemin/release.zip --target /chemin/site-client --plan
python3 tools/cms.py instance update --source /chemin/release.zip --target /chemin/site-client --apply --backup --yes
```

Ce flux orchestre le delta fichiers, le backup SQLite, les migrations et les validations essentielles. Les chemins `storage/database/`, `storage/media/`, `storage/uploads/`, `storage/logs/`, `storage/backups/`, `ops/.env`, `ops/modules.local.json` et `local/modules/` sont protégés.

## Application manuelle des migrations

```bash
python3 tools/cms.py migrate --apply --backup --yes
```

Cette commande applique les migrations SQLite connues par l’inventaire local. Elle ne remplace pas une sauvegarde externe complète du serveur.

## Après intervention

```bash
python3 tools/cms.py validate --category database --category operations --category documentation
python3 tools/cms.py qualify --profile quick
```

## Configuration runtime autorisée

Le fichier `ops/.env` fait partie de la configuration de l'instance déployée, mais pas de l'archive release officielle. Le package distribue `ops/.env.example`; la configuration réelle est créée ou conservée sur la cible par les procédures d'installation ou de mise à jour.

Tous les fichiers d’environnement locaux restent interdits dans une archive de production, notamment `.env`, `.env.local`, `backend/.env`, `frontend/.env`, `ops/.env`, `ops/.env.local`, `ops/.env.dev`, `ops/.env.test`, `*.env.bak` et `*.env.save`.

## Points à vérifier manuellement

- les droits d’écriture sur `storage/` ;
- la présence des bases attendues ;
- le bon domaine dans la configuration ;
- l’accès HTTP au front et au backoffice ;
- l’absence d’erreur PHP dans les logs ;
- l’existence d’un backup exploitable.

## Ce que cette procédure ne fait pas

Elle ne crée pas de rollback SQL. En cas de problème bloquant, la stratégie recommandée est de restaurer le backup SQLite et les fichiers concernés.
