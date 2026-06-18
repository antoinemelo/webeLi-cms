---
title: Installer une release officielle
audience:
  - installer
  - superadministrator
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: procedure
source_paths:
  - backend/composer.json
  - frontend/admin-vue/package.json
  - config

owners:
  - operations
document_type: procedure
permissions:
source_paths:
  - tools/cms.py
  - ops/.env.example
  - backend/composer.json
  - .htaccess
generated: false
---
# Installer une release officielle

## Procédure

1. Décompressez l’archive dans un chemin définitif, ou clonez la branche attendue lorsque l'environnement est explicitement synchronisé par Git. Les commandes citées utilisent des guillemets afin de supporter les espaces : `cd "/chemin/avec espaces/mod"`.
2. Copiez `ops/.env.example` vers la configuration locale utilisée par le déploiement et adaptez `APP_BASE_PATH`, l’URL publique, les secrets et le mode production ou staging.
3. Si `vendor` est absent, exécutez Composer dans `backend/` selon le contrat de l’archive.
4. Rendez `storage/` et le répertoire de bases accessibles en écriture au processus PHP.
5. Initialisez une installation vide avec `python3 tools/cms.py init`; utilisez `--with-reference-seed` uniquement pour une instance de démonstration prévue à cet effet.
6. Créez le premier superadministrateur par le mécanisme fourni par la release ou par une procédure d’exploitation contrôlée ; ne conservez aucun identifiant de seed de démonstration.
7. Configurez le serveur web pour pointer vers la racine publique attendue et respecter `.htaccess` sur Apache.
8. Exécutez les validations et le smoke test.

## Sous-répertoire

Pour une installation sous `/cms`, définissez `APP_BASE_PATH=/cms` et vérifiez les routes `/admin`, `/api/v1/health`, les assets et les URL générées.

Pour Hostpoint, l'installation sous `/mod` peut être synchronisée soit par Git depuis la branche `staging`, soit par FTP/FTPS depuis une release préparée. Voir [Déployer sur Hostpoint avec Git ou FTP](../operations/hostpoint-git-ftp.md).
