---
title: Mise à jour stable intégrée du core et des modules
audience:
  - administrator
  - developer
  - release-manager
status: current
last_verified: 2026-08-22
source_of_truth: procedure
source_paths:
  - backend/src/Infrastructure/Maintenance/StableUpdateCatalogService.php
  - backend/src/Infrastructure/Maintenance/StableUpdateService.php
  - tools/python/operations/deployment/d15_package_stable_components.py
  - .github/workflows/publish-stable-components.yml
  - frontend/admin-vue/src/views/tools/MaintenanceView.vue
owners:
  - operations
  - release-management
document_type: procedure
generated: false
---
# Mise à jour stable intégrée du core et des modules

L’écran **Maintenance** peut appliquer les publications stables immuables hébergées par GitHub. Ce mécanisme n’installe jamais la branche `staging` et ne rétrograde jamais une instance.

## Règles fonctionnelles

- le core DEC CMS doit être exactement à la dernière version stable avant toute mise à jour de module ;
- si le core est plus ancien, seul son bouton **Mettre à jour** est disponible ;
- après rechargement et validation du core, les modules plus anciens deviennent applicables indépendamment ;
- une instance plus récente que le stable est signalée comme hors canal et n’est pas rétrogradée ;
- le backend revérifie le catalogue et son empreinte au moment de l’application : masquer ou forcer un bouton côté navigateur ne contourne pas les règles.

## Publication GitHub

Un tag égal à la valeur courante de `config/release.json:technical_version` déclenche `.github/workflows/publish-stable-components.yml`. Le commit tagué doit appartenir à l’historique de `main`.

La publication construit :

- `core-<version>.zip` ;
- un `module-<clé>-<version>.zip` par module système ;
- un fichier SHA-256 par archive ;
- `dec-cms-stable.json`, catalogue complet de la release.

Le core exclut le code et les migrations propres aux modules. Un paquet module possède son répertoire PHP, son schéma et ses migrations. Tant que le front Vue reste compilé comme une application unique, `admin-app/` est un préfixe partagé contrôlé entre les paquets.

## Contrôles à l’installation

L’updater vérifie le dépôt GitHub autorisé, le canal `stable`, l’empreinte du catalogue, l’URL immuable de chaque asset, le SHA-256 du ZIP, la correspondance catalogue/manifeste et le SHA-256 de chaque fichier extrait. Les chemins contenant une traversée ou un lien symbolique sont refusés.

Les chemins suivants ne peuvent jamais être revendiqués par un paquet :

- `storage/database/`, `storage/media/`, `storage/uploads/` ;
- caches, logs, exports, sauvegardes et historique d’opérations ;
- `ops/.env`, `ops/modules.local.json` ;
- l’ensemble de `local/`.

Avant la copie, toutes les bases SQLite de l’instance passent `integrity_check` et sont sauvegardées avec `VACUUM INTO`. Le code remplacé est archivé sous `storage/backups/updates/`. Un verrou empêche deux opérations simultanées et `storage/maintenance.flag` place le site en HTTP 503 pendant la copie et les migrations.

Lors de la toute première mise à jour intégrée, l’inventaire de la dernière release complète (`storage/deployments/release-manifest.json`) amorce les manifestes par composant. Les anciens fichiers gérés devenus inutiles sont ainsi sauvegardés puis retirés, sans toucher aux fichiers d’un autre composant.

## Échec et récupération

Si une erreur survient avant les migrations, le code est restauré et le mode maintenance est retiré. Si les migrations ont commencé, le code est restauré mais le drapeau de maintenance reste présent : l’opération est marquée `recovery_required` afin d’éviter de rouvrir automatiquement une instance dont plusieurs bases pourraient être partiellement migrées.

Dans ce cas :

1. conserver `storage/backups/updates/<opération>/operation.json` ;
2. restaurer les bases depuis le sous-dossier `databases/` ;
3. vérifier ou restaurer le code depuis `code/` ;
4. exécuter `php backend/bin/console migrate` puis les validations ;
5. retirer `storage/maintenance.flag` seulement lorsque l’instance est cohérente.

## Canal de développement

Maintenance continue d’observer la branche Git `staging` et signale lorsqu’une version dev plus récente existe. Elle renvoie vers le workflow GitHub de release, à exécuter sur `staging`, pour préparer ou déployer par FTP, SFTP ou CLI. Aucun endpoint web ne peut appliquer `staging` sur une instance live.
