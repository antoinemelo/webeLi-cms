---
title: Mettre à jour une instance client avec modules locaux
audience:
  - installer
  - administrator
  - developer
status: current
last_verified: 2026-08-11
source_of_truth: procedure
source_paths:
  - tools/python/commands/instance.py
  - tools/admin.py
  - tools/python/operations/deployment/d3_deploy_ftp.py
  - tools/python/operations/deployment/d8_deploy_web_update.py
  - tools/python/operations/backup/d6_backup_sqlite.py
  - tools/python/operations/database/d9_migrate_sqlite.py
  - backend/src/Application/Maintenance/DependencyInventoryService.php
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
- les versions de PHP, Composer, Node, npm et des dépendances suivies ;
- les chemins réellement détectés pour les vendors, Twig, les lockfiles et les assets admin.

Les canaux ont le rôle suivant :

| Canal | Usage |
|---|---|
| `dev` | Prévisualiser une version de développement/staging. Dans l'organisation webeLi, ce canal correspond à `/cms` et à la branche Git `staging`. |
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

Depuis `tools/admin.py`, le point 11 guide toute l'opération : il demande la release source, puis propose une cible locale ou FTP/FTPS. Lorsque la source proposée `storage/exports/release_stage` est conservée, le point 11 la reconstruit automatiquement depuis les fichiers courants de `/dev`, sans bases, sans vendor et sans artefacts de développement. Une archive ZIP ou un autre dossier explicitement choisi reste inchangé et permet toujours de déployer une release précise. Le point 5 demeure la procédure de préparation et de qualification d'une release officielle.

Avant d'intervenir sur une instance client, vérifiez :

- que la release cible correspond au canal choisi ;
- que les notes de livraison ou le manifeste indiquent les migrations attendues ;
- que les chemins de vendors affichés dans Maintenance correspondent au mode de livraison attendu ;
- que les contraintes PHP/Node et les dépendances critiques ne signalent pas une divergence incompatible ;
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

## Cible FTP/FTPS depuis le point 11

Le mode FTP du point 11 demande :

- le fichier `ops/ftp.deploy.json` contenant la connexion ;
- le chemin distant exact, par exemple `/www/webe.li/eve`, ou une URL `ftp://serveur/www/webe.li/eve` ;
- si les fichiers retirés de la nouvelle release doivent aussi être supprimés.

Une simulation est toujours exécutée avant la confirmation. Un manifeste différentiel distinct est conservé par configuration FTP et chemin distant sous `storage/deployments/ftp-manifests/`. Après le premier transfert suivi, les exécutions suivantes n'envoient que les fichiers ajoutés ou dont le hash a changé. Le premier transfert d'une cible sans manifeste constitue la référence et peut donc envoyer toute la release.

Ce profil protège les bases, médias, uploads, secrets, logs, caches, backups, vendors et modules locaux. FTP ne permet pas d'exécuter de manière fiable les commandes du serveur : le point 11 réalise donc uniquement la mise à jour différentielle des fichiers. Si la release contient des migrations, exécutez ensuite sur le serveur, par SSH ou console d'hébergement :

La simulation écrit en outre un plan propre à la cible. L'exécution réelle refuse de démarrer si le stage, le manifeste précédent, la cible ou la liste des différences a changé entre-temps. Si la simulation ne détecte aucune différence, le point 11 s'arrête sans lancer un second déploiement FTP.

```bash
python3 tools/cms.py migrate --plan
python3 tools/cms.py migrate --apply --backup --yes
python3 tools/cms.py validate
```

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
