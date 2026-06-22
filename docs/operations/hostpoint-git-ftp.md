---
title: Déploiement Hostpoint par Git ou FTP
audience:
  - operator
  - administrator
status: draft
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

# Déploiement Hostpoint par Git ou FTP

Cette note décrit une approche simple pour publier une instance sur un hébergement Hostpoint ou équivalent. Elle reste volontairement indépendante d’un système CI/CD.

## Préparer le projet en local

Avant transfert, vérifier le projet localement :

```bash
python3 tools/cms.py validate --category configuration --category database --category operations
python3 tools/cms.py qualify --profile quick
```

## Sauvegarder avant remplacement

Avant de remplacer des fichiers ou de migrer les bases d’une instance existante :

```bash
python3 tools/cms.py backup --help
python3 tools/cms.py migrate --plan
```

## Transfert

Le transfert peut être réalisé avec l’outil choisi : Git, SFTP, FTP sécurisé ou rsync selon l’hébergement. Le dépôt source, le zip de release et le répertoire de production doivent rester clairement distingués.

Ne pas transférer inutilement les éléments suivants vers une production simple :

- caches locaux ;
- rapports temporaires ;
- fichiers de développement non nécessaires ;
- anciennes sauvegardes locales ;
- environnements virtuels Python (`.venv/`, `venv/`) ;
- dossiers de tests locaux (`tools/python/tests/`, `tools/tests/`) ;
- archives locales (`*.zip`, `*.tar`, `*.tar.gz`).

Le stage de release doit être généré par `d2_package_release.py` ou par la commande de release du CMS. Il ne doit pas être construit par simple copie récursive du dépôt de développement.

Exception de configuration : `ops/.env` est un fichier runtime autorisé et attendu en production pour ce projet. Les autres fichiers `.env` locaux ou variantes de développement restent interdits.

## Après transfert

Contrôler l’instance et appliquer les migrations si nécessaire :

```bash
python3 tools/cms.py migrate --apply --backup
python3 tools/cms.py validate --category database --category operations
```

## Retour arrière

En cas d’échec, restaurer les fichiers précédents et le backup SQLite. Cette procédure ne repose pas sur des down migrations.
