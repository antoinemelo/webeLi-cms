---
title: Installation
audience:
  - installer
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
# Installation

Cette section décrit uniquement l’installation initiale d’une release. Les procédures récurrentes sont dans [Exploitation](../operations/README.md).

1. [Vérifier les prérequis](requirements.md)
2. [Installer une release](install-release.md)
3. [Configurer l’instance](configuration-reference.md)
4. [Valider le déploiement](../operations/deployment.md)
5. [Mettre en place les sauvegardes](../operations/backup-restore.md)

Une installation neuve depuis les sources peut être initialisée avec `python3 tools/cms.py init` ou reconstruite en développement avec `python3 tools/cms.py rebuild`. Une instance existante avec contenu doit être mise à jour par sauvegarde et migrations incrémentales, pas par reconstruction. Une release de production doit être préparée par la chaîne de release, et non par copie arbitraire du répertoire de développement.
