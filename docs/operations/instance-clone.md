---
title: Cloner une instance localement
audience:
  - operator
  - developer
  - administrator
status: draft
version: 1.0
last_verified: 2026-06-20
source_of_truth: procedure
source_paths:
  - tools/cms.py
  - tools/python/operations/backup
  - tools/python/operations/database
owners:
  - operations
  - documentation
document_type: guide
generated: false
---

# Cloner une instance localement

Cette procédure vise un clone simple pour test, maintenance ou préparation de migration. Elle ne crée pas de service distant et ne modifie pas l’architecture du CMS.

## Préparer la source

Sur l’instance source, créer ou vérifier un backup SQLite :

```bash
python3 tools/cms.py backup --help
python3 tools/cms.py validate --category database --category operations
```

Copier ensuite les fichiers nécessaires vers l’environnement de destination, notamment :

- le code applicatif ;
- le contenu de `storage/database/` ;
- les médias nécessaires ;
- la configuration adaptée à l’environnement cible.

## Vérifier le clone

Sur la copie locale :

```bash
python3 tools/cms.py migrate --plan
python3 tools/cms.py validate --category database --category operations
```

Si le plan de migration est attendu, appliquer avec backup :

```bash
python3 tools/cms.py migrate --apply --backup
```

## Après clonage

Vérifier manuellement :

- l’URL de base ;
- les droits d’écriture ;
- les accès au backoffice ;
- les médias ;
- les tâches ou scripts locaux qui ne doivent pas pointer vers la production.

## Limite

Le clonage ne doit pas être confondu avec un outil de synchronisation automatique multi-sites. Pour l’instant, la maintenance reste locale et explicite.
