---
title: Maintenance et versions installées
audience:
  - administrator
  - superadministrator
status: current
last_verified: 2026-07-10
source_of_truth: code
source_paths:
  - backend/src/Application/Api/Admin/MaintenanceApiController.php
  - backend/src/Application/Maintenance/VersionInventoryService.php
  - backend/src/Infrastructure/Maintenance/DatabaseMigrationInventory.php
  - frontend/admin-vue/src/views/tools/MaintenanceView.vue
  - backend/config/updates.php
owners:
  - core
  - operations
document_type: guide
generated: false
---
# Maintenance et versions installées

L'écran **Maintenance** rassemble les opérations courantes d'exploitation et un bloc informatif sur l'état technique de l'instance. Il est accessible aux comptes disposant de la permission `maintenance.manage`.

Le bloc de versions est volontairement en lecture seule. Il ne télécharge pas de fichier, ne lance pas de migration et ne modifie aucune base. Il sert à décider si une intervention de mise à jour doit être préparée avec les outils d'exploitation.

## Versions installées

Le tableau **Versions installées** compare l'instance courante avec deux canaux de référence :

| Colonne | Rôle |
|---|---|
| `Installé` | Version détectée sur l'instance ouverte dans le back-office. |
| `Dev` | Version publiée par le canal de développement/staging. |
| `Stable` | Version publiée par le canal stable/release. |
| `État` | Résultat de comparaison informatif. |

La première ligne décrit le noyau CMS. Les lignes suivantes décrivent les modules installés ou visibles dans les canaux distants. Les modules système éditoriaux internes sont masqués pour garder la lecture centrée sur ce qui doit réellement être suivi par l'administrateur.

Les statuts possibles indiquent notamment :

- `À jour` : un canal distant est disponible et ne déclare pas de version plus récente.
- `Nouvelle version disponible` : au moins un canal déclare une version supérieure.
- `Version distante inconnue` : aucun canal exploitable ne fournit l'information.
- `Absent localement` : un module existe dans un canal distant mais pas dans l'instance.
- `Absent des canaux` : un module local n'est pas retrouvé dans les canaux de référence.

## Bases de données

Le tableau **Bases de données** compare l'état des migrations SQLite connues par le code et celles appliquées dans l'instance.

| Colonne | Rôle |
|---|---|
| `Appliquée` | Dernière migration réellement enregistrée dans `schema_migrations`. |
| `Dev` | Dernière migration attendue par le canal de développement/staging. |
| `Stable` | Dernière migration attendue par le canal stable/release. |
| `État` | Diagnostic informatif sur l'état local ou distant. |

L'inventaire couvre les bases natives, les bases des modules système et les bases des modules clients déclarés localement lorsqu'elles restent sous `storage/database/`.

Les statuts importants sont :

- `À jour` : les migrations attendues sont appliquées.
- `Nouvelle migration disponible` : un canal distant déclare une migration plus récente.
- `Aucune migration attendue` : aucune migration SQL n'est déclarée pour cette base.
- `Base absente` : la base attendue n'existe pas localement.
- `Journal de migrations absent` : la base existe mais ne possède pas encore `schema_migrations`.
- `Migration manquante` : une migration attendue n'est pas appliquée.
- `Migration inconnue` : la base contient une migration non présente dans le code courant.
- `Divergence checksum` : une migration déjà appliquée ne correspond plus au fichier attendu.

Une divergence ne doit pas être corrigée depuis l'interface. Elle doit être traitée par plan de migration, sauvegarde et validation.

## Canaux de référence

Les canaux sont configurés côté serveur dans `backend/config/updates.php`.

| Canal | Usage recommandé |
|---|---|
| `dev` | Référence de développement/staging. Dans l'organisation webeLi, ce canal correspond à l'instance `/mod` et à la branche Git `staging`. |
| `stable` | Référence stable/release. Dans l'organisation webeLi, ce canal correspond à l'instance `/maj` et à la branche Git `main`. |

Chaque canal essaie d'abord de lire un manifeste public :

```text
/updates/manifest.json
```

Si ce manifeste est absent ou incomplet, le système peut utiliser Git comme source de secours pour lire les informations disponibles dans le dépôt configuré. Le fallback Git sert à compléter l'information ; il ne remplace pas une release vérifiée.

## Manifeste public

Chaque instance expose son état local sous forme JSON :

```text
/updates/manifest.json
```

Ce manifeste contient notamment :

- la version du noyau ;
- les modules détectés ;
- les bases connues ;
- les migrations attendues et appliquées ;
- la branche et le commit Git si l'instance est dans un checkout Git.

Le manifeste ne contient pas les données métier ou éditoriales. Il sert à comparer des versions et des schémas, pas à sauvegarder une instance.

## Décision de mise à jour

L'écran Maintenance répond à la question : **une mise à jour semble-t-elle nécessaire ?**

Il ne répond pas seul à la question : **peut-on appliquer cette mise à jour maintenant ?**

Avant toute mise à jour d'une instance client, il faut suivre le workflow d'exploitation :

1. cloner ou sauvegarder l'instance ;
2. afficher un plan non mutatif ;
3. vérifier les chemins protégés et les migrations ;
4. appliquer avec backup ;
5. valider ;
6. conserver le journal d'opération et le rollback.

La procédure détaillée est décrite dans [Mettre à jour une instance client avec modules locaux](../operations/client-instance-update.md).
