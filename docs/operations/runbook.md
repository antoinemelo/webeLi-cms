---
title: Exploiter et maintenir une instance
audience:
  - administrator
  - superadministrator
  - operator
status: stable
version: 1.1
last_verified: 2026-06-20
source_of_truth: procedure
source_paths:
  - tools/cms.py
  - tools/python/cms/cli.py
  - tools/python/operations
  - tools/python/validation
owners:
  - operations
  - core
document_type: guide
generated: false
---

# Exploiter et maintenir une instance

Ce runbook rassemble les opérations courantes pour vérifier, sauvegarder, migrer et qualifier une instance AM-CMS / webeLi avec les outils locaux Python.

## Commandes de référence

Afficher les commandes disponibles :

```bash
python3 tools/cms.py --help
```

Valider rapidement le projet :

```bash
python3 tools/cms.py validate
python3 tools/cms.py qualify --profile quick
```

Créer ou vérifier les bases natives en environnement local :

```bash
python3 tools/cms.py init
python3 tools/cms.py rebuild --help
```

Préparer une intervention sur les bases SQLite :

```bash
python3 tools/cms.py backup --help
python3 tools/cms.py migrate --plan
```

Appliquer les migrations incrémentales avec sauvegarde préalable :

```bash
python3 tools/cms.py migrate --apply --backup --yes
```

Vérifier la documentation générée ou contrôlée par le projet :

```bash
python3 tools/cms.py docs generate
python3 tools/cms.py docs check
```

## Séquence recommandée avant migration

1. vérifier que le projet est dans l’état attendu ;
2. créer ou vérifier un backup ;
3. lire le plan de migration non mutatif ;
4. appliquer uniquement si le plan est cohérent ;
5. relancer les validations.

```bash
python3 tools/cms.py validate --category database --category operations
python3 tools/cms.py migrate --plan
python3 tools/cms.py migrate --apply --backup --yes
python3 tools/cms.py validate --category database --category operations --category documentation
```

## En cas d’échec

Ne pas tenter de corriger une base de production à la main sans copie. Restaurer d’abord le backup ou travailler sur un clone local.

La stratégie actuelle reste volontairement simple : backup avant migration, migration incrémentale, vérification d’intégrité, restauration depuis backup en cas de problème bloquant.

## Vérifier un stage de release avant FTP

Avant un déploiement FTP réel, vérifier que le stage ne contient pas d’environnement local ni d’archive temporaire :

```bash
find storage/exports/release_stage -path "*/.venv/*"
find storage/exports/release_stage -name "*.zip"
find storage/exports/release_stage -name "__pycache__"
```

Ces commandes doivent rester vides. Si un fichier est trouvé, il faut corriger le packaging ou supprimer l’artefact local avant de relancer la release.

## Configuration runtime

`ops/.env` est explicitement autorisé dans la release car il est nécessaire au runtime. Ne pas le confondre avec des fichiers d’environnement locaux : `.env`, `.env.local`, `backend/.env`, `frontend/.env`, `ops/.env.local`, `ops/.env.dev`, `ops/.env.test`, `*.env.bak` et `*.env.save` doivent rester exclus ou bloqués.

## Points de vigilance

- ne pas déplacer les bases SQLite natives ;
- ne pas modifier une migration déjà publiée ;
- ne pas confondre release, dépôt source et instance cliente ;
- ne pas activer un module custom sans vérifier ses tables et ses migrations ;
- garder `tools/cms.py` comme façade principale pour les opérations locales.

## Rappel sur `rebuild`

Pour une base existante avec contenu, ne pas utiliser `rebuild` comme mise à jour. Utilisez `backup`, `migrate --plan`, `migrate --apply --backup --yes`, puis les validations. `rebuild` reste réservé au développement, aux tests et aux récupérations contrôlées après sauvegarde.
