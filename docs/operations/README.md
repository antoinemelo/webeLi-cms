---
title: Exploitation
audience:
  - installer
  - administrator
  - superadministrator
  - developer
status: stable
version: 1.0
last_verified: 2026-06-20
source_of_truth: manual
owners:
  - core
document_type: guide
generated: false
---
# Exploitation

Les procédures de cette section concernent une instance installée ou une release en préparation. Elles utilisent la façade stable `python3 tools/cms.py`.

## Procédures canoniques

- [Runbook de maintenance](runbook.md)
- [Checklist de production](production-checklist.md)
- [Déploiement et qualification](deployment.md)
- [Déploiement Hostpoint Git ou FTP](hostpoint-git-ftp.md)
- [Clonage local d’instance](instance-clone.md)
- [Sauvegarde, restauration et rollback](backup-restore.md)
- [Mettre à jour une base existante](existing-database-update.md)
- [Mettre à jour une instance client](client-instance-update.md)
- [Migrations SQLite des modules](module-migrations.md)
- [Export statique](static-export.md)
- [Contrôles de santé natifs](health-checks.md)
- [Dépannage](troubleshooting.md)

## Porte minimale avant production

```bash
python3 tools/cms.py docs check
python3 tools/cms.py validate --full --with-slow
python3 tools/cms.py test
python3 tools/cms.py release --ci
```

L’environnement cible doit en plus être contrôlé pour les droits de fichiers, les extensions PHP requises, HTTPS, les protections de `storage/`, la configuration des secrets, les tâches planifiées et la restauration effective d’une sauvegarde.

## Socle modulaire local

Les bases existantes avec contenu se mettent à jour par sauvegarde et migrations incrémentales. Les modules clients locaux sont protégés par le flux `instance update` et par l’inventaire SQLite unifié.
