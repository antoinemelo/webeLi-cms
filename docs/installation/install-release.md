---
title: Installer une release officielle
audience:
  - installer
  - superadministrator
status: stable
last_verified: 2026-06-14
source_of_truth: procedure
source_paths:
  - backend/composer.json
  - frontend/admin-vue/package.json
  - config
  - tools/cms.py
  - ops/.env.example
  - .htaccess

owners:
  - operations
document_type: procedure
generated: false
---
# Installer une release officielle

## Contextes de contrôle

Une archive release installée doit rester autonome : elle contient le runtime, les assets compilés, les bases SQLite seedées et la documentation publique, mais pas les tests source, les dépendances de build ni `ops/.env`. Les commandes garanties dans ce contexte sont `smoke`, `validate` et `docs check`.

Le dépôt source complet sert au développement et à la préparation d'une livraison. Il contient les tests source et permet d'exécuter `test`, `qualify`, `docs generate` et le packaging.

Une archive de preuves est un artefact séparé réservé aux releases mineures ou majeures : elle documente l'audit reproductible et ne doit pas être confondue avec l'archive installable.

## Procédure

1. Décompressez l’archive dans un chemin définitif, ou clonez la branche attendue lorsque l'environnement est explicitement synchronisé par Git. Les commandes citées utilisent des guillemets afin de supporter les espaces : `cd "/chemin/avec espaces/mod"`.
2. Copiez `ops/.env.example` vers la configuration locale utilisée par le déploiement et adaptez `APP_BASE_PATH`, l’URL publique, les secrets et le mode production ou staging.
3. Si `vendor` est absent, exécutez Composer dans `backend/` selon le contrat de l’archive.
4. Rendez `storage/` et le répertoire de bases accessibles en écriture au processus PHP.
5. Initialisez une installation vide avec `python3 tools/cms.py init`; utilisez `--with-reference-seed` uniquement pour une instance de démonstration prévue à cet effet.
6. Créez le premier superadministrateur par le mécanisme fourni par la release ou par une procédure d’exploitation contrôlée ; ne conservez aucun identifiant de seed de démonstration.
7. Configurez le serveur web pour pointer vers la racine publique attendue et respecter `.htaccess` sur Apache.
8. Exécutez les contrôles adaptés à une archive release installée :

   ```bash
   python3 tools/cms.py smoke
   python3 tools/cms.py validate
   python3 tools/cms.py docs check
   ```

   N’exécutez pas `python3 tools/cms.py test` pour qualifier une archive release : les tests source sont volontairement exclus du package. Si cette commande est lancée dans une release, elle doit afficher un message explicite et renvoyer le code `2`.

## Vérifier une archive

Depuis le dépôt source complet, vérifiez une archive existante sans relancer Composer, npm ni une chaîne CI :

```bash
python3 tools/cms.py release --verify-archive --archive storage/exports/<technical_version>/<technical_version>.zip
make verify-release VERIFY_ARCHIVE=storage/exports/<technical_version>/<technical_version>.zip
```

Cette vérification extrait l'archive dans un répertoire temporaire, contrôle la structure, les bases SQLite, l'absence de fichiers interdits ou secrets, puis exécute dans l'archive extraite :

```bash
python3 tools/cms.py smoke
python3 tools/cms.py validate
python3 tools/cms.py docs check
python3 tools/cms.py test
```

Le dernier contrôle doit retourner le code `2` avec un message explicite lorsque les tests source ne sont pas distribués.

## Sous-répertoire

Pour une installation sous `/cms`, définissez `APP_BASE_PATH=/cms` et vérifiez les routes `/admin`, `/api/v1/health`, les assets et les URL générées. En multisite par sous-répertoire, un sous-site configuré comme `/site-a` doit être ouvert publiquement sous `/cms/site-a`; les liens du back-office ne doivent jamais perdre le préfixe d’installation.

Pour Hostpoint, l'installation sous `/mod` peut être synchronisée soit par Git depuis la branche `staging`, soit par FTP/FTPS depuis une release préparée. Voir [Déployer sur Hostpoint avec Git ou FTP](../operations/hostpoint-git-ftp.md).

## Socle modulaire local

Une release officielle contient le noyau CMS et les modules système livrés avec lui. Elle ne doit pas contenir les développements spécifiques d’une instance client.

Pour installer une instance qui recevra ensuite des modules clients :

1. Installez la release normalement.
2. Créez ou conservez `local/modules/` pour les développements locaux.
3. Déclarez les modules clients activés dans `ops/modules.local.json`, sur le modèle de `ops/modules.local.json.example`.
4. Gardez les bases métier des modules sous `storage/database/`.
5. Vérifiez l’état local avec `python3 tools/cms.py validate`.

Voir aussi [Modules système et modules clients](../development/extending/modules.md), [Migrations SQLite des modules](../operations/module-migrations.md) et [Mettre à jour une instance client avec modules locaux](../operations/client-instance-update.md).

## Mise à jour d’une installation existante

Cette page décrit l’installation initiale. Pour une instance qui contient déjà des données, ne relancez pas `init` ou `rebuild`. Utilisez le flux de mise à jour :

```bash
python3 tools/cms.py instance update --source /chemin/release.zip --target /chemin/site-client --plan
python3 tools/cms.py instance update --source /chemin/release.zip --target /chemin/site-client --apply --backup --yes
```

Si seuls les schémas SQLite doivent être amenés à la dernière version, suivez [mettre à jour une base existante](../operations/existing-database-update.md).
