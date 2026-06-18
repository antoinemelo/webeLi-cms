---
title: Exploitation
audience:
  - installer
  - administrator
  - superadministrator
  - developer
status: stable
version: 1.0
last_verified: 2026-06-14
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
