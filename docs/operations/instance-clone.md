---
title: Cloner une instance locale
audience:
  - operator
  - developer
  - installer
status: stable
version: 1.0
last_verified: 2026-06-18
source_of_truth: procedure
source_paths:
  - tools/cms.py
  - tools/admin.py
  - tools/python/commands/instance.py
  - tools/python/operations/deployment/d14_clone_instance.py

owners:
  - operations
  - documentation
document_type: guide
generated: false
---

# Cloner une instance locale

La commande de clonage local prépare une copie exploitable d'une instance CMS dans un autre répertoire. Elle est destinée aux phases de test, aux duplications d'environnement et aux préparations de chemins comme `/mod`, `/eve`, `/edu` ou `/mod2`.

Elle distingue volontairement deux notions :

- le **répertoire de destination**, c'est-à-dire le dossier créé sur le disque ;
- le **chemin public configuré**, c'est-à-dire la valeur écrite dans `APP_BASE_PATH` et remplacée dans les fichiers texte et les bases SQLite.

Cette séparation permet par exemple de créer un dossier temporaire `../mod2` tout en conservant une configuration publique `/mod`, puis de renommer le dossier plus tard pendant une phase de test.

## Commande

Depuis la racine de l'instance source :

```bash
python3 tools/cms.py instance clone --destination ../mod2 --new-base-path /mod
```

Dans cet exemple :

- le dossier créé est `../mod2` ;
- `ops/.env` dans le clone conserve `APP_BASE_PATH=/mod` ;
- les chemins absolus locaux sont réécrits vers le nouveau dossier `mod2` ;
- les références publiques `/mod` restent configurées comme `/mod`.

Pour créer une vraie instance `/eve` configurée publiquement en `/eve` :

```bash
python3 tools/cms.py instance clone --destination ../eve --new-base-path /eve
```

Si `--new-base-path` est absent, la commande déduit le chemin public depuis le nom du dossier destination.

## Simulation

Utilisez toujours une simulation avant une copie réelle :

```bash
python3 tools/cms.py --dry-run instance clone \
  --destination ../mod2 \
  --new-base-path /mod
```

Le menu interactif `tools/admin.py` lance cette simulation automatiquement avant de proposer l'écriture effective.

## URL publique

Lorsque l'URL publique ne peut pas être déduite par remplacement du chemin, forcez-la explicitement :

```bash
python3 tools/cms.py instance clone \
  --destination ../eve2 \
  --new-base-path /eve \
  --new-public-base-url https://webe.li/eve
```

`--destination ../eve2` et `--new-base-path /eve` sont indépendants : le dossier peut s'appeler `eve2` pendant la préparation alors que l'instance est déjà configurée pour `/eve`.

## Contenu copié

Le clonage copie le runtime utile de l'instance source, notamment le code applicatif, la configuration locale, les bases SQLite, les médias et les fichiers nécessaires au fonctionnement local.

Il exclut les éléments de développement ou de publication qui ne doivent pas être recopiés tels quels :

```text
.git/
.github/
.codex/
.agents/
docs/
frontend/admin-vue/
node_modules/
storage/cache/
storage/logs/*.log
storage/exports/
storage/deployments/current.json
storage/deployments/history.ndjson
storage/deployments/release-manifest.json
ops/ftp.deploy.json
ops/*.example
tools/tests/.venv/
tools/tests/users.csv
tools/tests/*.zip
```

Les fichiers `.gitkeep` utiles sont recréés dans les dossiers runtime attendus.

## Remplacements effectués

La commande remplace :

- les chemins absolus de l'ancienne instance vers le nouveau dossier ;
- l'ancien `APP_BASE_PATH` public vers le nouveau chemin public configuré ;
- les valeurs texte correspondantes dans les bases SQLite.

Le remplacement de chemin public est borné pour ne pas casser des mots comme `modules`, ni des chemins comme `database/modules` ou `config/modules.php`.

## Précautions

Le clonage local peut copier des bases, médias et secrets runtime. N'utilisez pas cette commande pour produire une release publique ou transférer une instance vers un tiers. Pour une publication, utilisez la chaîne de release et le workflow Git ou FTP documenté.

Avant d'écraser une destination existante, créez une sauvegarde ou travaillez dans un dossier temporaire. `--force` supprime la destination avant de la recréer.
