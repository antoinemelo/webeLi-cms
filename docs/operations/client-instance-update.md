---
title: Mettre à jour une instance client avec modules locaux
audience:
  - installer
  - administrator
  - developer
status: current
last_verified: 2026-07-10
source_of_truth: procedure
source_paths:
  - tools/python/commands/instance.py
  - tools/python/operations/deployment/d8_deploy_web_update.py
  - tools/python/operations/backup/d6_backup_sqlite.py
  - tools/python/operations/database/d9_migrate_sqlite.py
  - backend/src/Application/Maintenance/VersionInventoryService.php
  - frontend/admin-vue/src/views/tools/MaintenanceView.vue
owners:
  - operations
  - documentation
document_type: procedure
generated: false
---
# Mettre à jour une instance client avec modules locaux

La commande `instance update` déploie une release du noyau sur une instance client en conservant les contenus, les bases SQLite, les médias, les sauvegardes, les secrets et les modules clients locaux.

Ce document formalise le workflow recommandé. L'écran **Maintenance** du back-office aide à constater les versions disponibles, mais il ne remplace pas le plan, le backup et les validations locales.

## Résumé du workflow

```text
observer les versions
→ choisir le canal et la release
→ travailler sur un clone ou avec une sauvegarde vérifiée
→ afficher un plan non mutatif
→ vérifier fichiers protégés et migrations
→ appliquer avec backup
→ valider l'instance
→ conserver journal, backup et rollback
```

Une mise à jour ne doit jamais commencer par une copie manuelle de fichiers sur une instance contenant des données utiles.

## Lire les canaux disponibles

Dans le back-office, l'écran **Maintenance** affiche :

- la version core installée ;
- les versions de modules installées ;
- les bases SQLite et leurs migrations appliquées ;
- la version attendue par le canal `dev` ;
- la version attendue par le canal `stable`.

Les canaux ont le rôle suivant :

| Canal | Usage |
|---|---|
| `dev` | Prévisualiser une version de développement/staging. Dans l'organisation webeLi, ce canal correspond à `/mod` et à la branche Git `staging`. |
| `stable` | Suivre la release stable destinée aux instances client. Dans l'organisation webeLi, ce canal correspond à `/maj` et à la branche Git `main`. |

La source prioritaire est le manifeste public du canal :

```text
/updates/manifest.json
```

Si le manifeste est absent ou incomplet, l'information peut être complétée depuis Git. Ce fallback est utile pour diagnostiquer une version disponible, mais la mise à jour d'une instance client doit utiliser une release ou une source explicitement choisie et vérifiée.

## Choisir la source de mise à jour

Le canal `stable` est le choix normal pour une instance client. Le canal `dev` peut servir à tester une correction ou une évolution sur un clone, mais il ne doit pas devenir la source habituelle d'une production client sans décision explicite.

La source fournie à `instance update` est généralement une archive release :

```text
/chemin/release.zip
```

Avant d'intervenir sur une instance client, vérifiez :

- que la release cible correspond au canal choisi ;
- que les notes de livraison ou le manifeste indiquent les migrations attendues ;
- que l'instance cible dispose d'un backup récent ou qu'un backup sera créé par la procédure ;
- que les modules clients locaux sont déclarés dans `ops/modules.local.json` si leurs bases doivent être suivies.

## Plan non mutatif

```bash
python3 tools/cms.py instance update \
  --source /chemin/release.zip \
  --target /chemin/site-client \
  --plan
```

Le plan calcule le delta fichiers et le plan de migrations sur une copie temporaire des bases de la cible. Il ne modifie pas l’instance.

Le plan doit être lu avant toute application. Il doit permettre de vérifier :

- les fichiers ajoutés, modifiés et supprimés ;
- les chemins ignorés parce qu'ils sont protégés ;
- la version de release détectée ;
- les bases concernées ;
- les migrations qui seraient appliquées ;
- les éventuels fichiers obsolètes si `--delete-obsolete` est envisagé.

Si le plan signale une migration inattendue, une base absente, un fichier protégé qui serait touché ou un module client non reconnu, arrêtez la procédure et corrigez l'inventaire avant d'appliquer.

## Application contrôlée

```bash
python3 tools/cms.py instance update \
  --source /chemin/release.zip \
  --target /chemin/site-client \
  --apply --backup --yes
```

L’application crée un backup SQLite, applique le delta fichiers avec archive de rollback, exécute les migrations, lance des validations essentielles et écrit un journal JSON dans `storage/operations/instance-updates/`.

`--apply` doit rester accompagné de `--backup` et `--yes` dans une procédure normale. L'option longue de risque explicite des migrations ne doit être utilisée que si un backup externe vérifié existe déjà.

Pendant une intervention réelle, il est recommandé de bloquer les écritures applicatives ou de placer l'instance en maintenance, surtout si des éditeurs peuvent publier pendant la copie fichiers ou les migrations.

## Chemins protégés

Les chemins suivants ne doivent pas être écrasés par une release core :

- `storage/database/` ;
- `storage/media/` ;
- `storage/uploads/` ;
- `storage/logs/` ;
- `storage/backups/` ;
- `ops/.env` ;
- `ops/modules.local.json` ;
- `local/modules/`.

`backend/src/Modules/` appartient au noyau et aux modules système livrés officiellement.

Les modules clients doivent rester sous `local/modules/`. Leurs bases doivent rester sous `storage/database/` pour être incluses dans les sauvegardes et les plans de migrations locaux.

## Validation après application

Après application, contrôlez l'instance depuis la racine de la cible :

```bash
python3 tools/cms.py smoke
python3 tools/cms.py validate
python3 tools/cms.py docs check
```

Pour une instance source complète ou un environnement de préproduction contenant les dépendances de test, utilisez une qualification plus large lorsque le changement le justifie :

```bash
python3 tools/cms.py qualify --profile complete
```

Puis vérifiez fonctionnellement :

- connexion au back-office ;
- consultation de l'écran Maintenance ;
- contenu public principal ;
- recherche si l'index a été touché ;
- modules métier utilisés par le client ;
- export ou API si l'instance en dépend.

Les reconstructions de projections ou d'index restent des opérations explicites de maintenance. Elles ne remplacent pas les migrations.

## Journal d'opération

Chaque application écrit un journal JSON sous :

```text
storage/operations/instance-updates/
```

Conservez ce journal avec le backup et l'archive de rollback fichiers. Il documente la source, la cible, la version détectée, le delta fichiers, le backup, les migrations et les validations exécutées.

## Rollback

Le rollback fichiers utilise l’archive produite par le déployeur. Le rollback base utilise la restauration du backup SQLite. Il n’y a pas de down migration automatique.

En cas d'échec bloquant :

1. stoppez les écritures ;
2. restaurez les fichiers depuis l'archive de rollback ;
3. restaurez les bases depuis le backup SQLite ;
4. relancez les validations ;
5. conservez le journal d'échec pour diagnostic.

Ne corrigez pas une migration publiée en modifiant son fichier SQL. Toute correction de schéma doit passer par une nouvelle migration incrémentale.
