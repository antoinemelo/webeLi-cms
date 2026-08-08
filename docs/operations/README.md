---
title: Exploitation
audience:
  - installer
  - administrator
  - superadministrator
  - developer
status: stable
last_verified: 2026-08-04
source_of_truth: manual
owners:
  - core
document_type: guide
generated: false
---
# Exploitation

Cet espace concerne une instance déjà installée ou une release qui doit être mise en service. Il privilégie les décisions d’exploitation : protéger les données, choisir la bonne procédure, contrôler le résultat et savoir revenir en arrière.

La plupart des commandes passent par `python3 tools/cms.py`. Cette façade rassemble les contrôles dans un ordre reproductible ; les guides expliquent ce que chaque commande vérifie et quand l’exécuter.

## Règles de sécurité

1. Identifiez précisément l’instance et l’environnement avant toute action.
2. Créez une sauvegarde vérifiable avant une mise à jour, une migration ou un changement de configuration important.
3. Ne considérez pas une sauvegarde comme valide tant qu’une restauration n’a pas été testée.
4. Conservez les modules et personnalisations locales pendant une mise à jour d’instance client.
5. En cas d’incident, collectez les faits et les journaux avant de multiplier les tentatives.

## Choisir la bonne procédure

| Situation | Procédure | Résultat attendu |
|---|---|---|
| Je prépare une mise en production | [Checklist de production](production-checklist.md) | Prérequis, sécurité et services contrôlés |
| Je déploie une nouvelle release | [Déploiement et qualification](deployment.md) | Release installée puis vérifiée |
| Je travaille sur Hostpoint | [Déploiement Git ou FTP](hostpoint-git-ftp.md) | Méthode adaptée à l’hébergement |
| Je dois sauvegarder ou restaurer | [Sauvegarde, restauration et retour arrière](backup-restore.md) | Données protégées et restauration testée |
| Je mets à jour une instance client | [Mise à jour d’une instance client](client-instance-update.md) | Socle actualisé sans écraser les ajouts locaux |
| Une base existante doit évoluer | [Mise à jour d’une base existante](existing-database-update.md) | Migrations appliquées dans l’ordre |
| Je clone une instance pour travailler | [Clonage local](instance-clone.md) | Copie isolée, secrets et URLs adaptés |
| L’instance semble indisponible ou incohérente | [Contrôles de santé](health-checks.md), puis [Dépannage](troubleshooting.md) | Cause circonscrite avant correction |
| Je prépare un paquet distribuable | [Dépôt source et release](source-vs-release.md) | Bon contenu dans le bon livrable |

## Déroulement d’une maintenance planifiée

1. Définissez le changement, la fenêtre d’intervention et le responsable de la décision de retour arrière.
2. Lisez le [runbook de maintenance](runbook.md) et la procédure propre à l’opération.
3. Vérifiez l’espace disque, les versions requises, les secrets et l’accès à la base.
4. Sauvegardez les données et les fichiers persistants ; notez leur emplacement.
5. Exécutez la modification et les migrations prévues, sans opérations improvisées entre les étapes.
6. Lancez les contrôles de santé, testez une connexion, une lecture publique et les fonctions critiques des modules actifs.
7. Documentez le résultat. Si un critère critique échoue, appliquez la procédure de retour arrière préparée.

## Contrôles avant production

```bash
python3 tools/cms.py docs check
python3 tools/cms.py validate --full --with-slow
python3 tools/cms.py test
python3 tools/cms.py release --ci
```

Ces commandes contrôlent respectivement la documentation, la cohérence complète du projet, les tests automatisés et la composition de la release. Elles ne remplacent pas les contrôles de l’environnement cible : droits de fichiers, extensions PHP, HTTPS, protection de `storage/`, secrets, tâches planifiées et restauration réelle d’une sauvegarde.

## Procédures spécialisées

- [Migrations SQLite des modules](module-migrations.md) : faire évoluer les données module par module.
- [Outbox transactionnelle](outbox.md) : comprendre et surveiller l’envoi différé d’événements.
- [Export statique](static-export.md) : produire et vérifier une version statique du site.
- [Gate de sortie M0](m0-release-gate.md) : appliquer les exigences propres à ce jalon.

Les bases contenant déjà des données évoluent uniquement par sauvegarde et migrations incrémentales. Le flux de mise à jour d’instance protège les modules clients locaux ; ne remplacez jamais ce flux par une copie aveugle du dépôt source.
