---
title: Commandes de qualification
audience:
  - developer
  - administrator
  - evaluator
status: current
last_verified: 2026-07-08
source_of_truth: code
source_paths:
  - tools/python/qualification/run_all.py
  - tools/python/commands/qualify.py
  - tools/admin.py
owners:
  - core-team
document_type: guide
---
# Commandes de qualification

La commande canonique pour une vérification approfondie du dépôt source complet est :

```bash
python3 tools/cms.py qualify --profile complete
```

Une archive release installée n’est pas qualifiée avec `test` ou `qualify`, car les tests source et dépendances de build sont exclus du package. Elle est contrôlée avec :

```bash
python3 tools/cms.py smoke
python3 tools/cms.py validate
python3 tools/cms.py docs check
```

Trois profils seulement sont proposés. **Aucun profil ne reconstruit les bases de l’instance de travail.** La qualification de release crée sa propre instance temporaire et y reconstruit les bases depuis les sources pour Playwright.

## Profils

| Profil | Usage | Contenu principal |
|---|---|---|
| `quick` | boucle locale courte | environnement, syntaxe Python et validateurs structurels rapides |
| `complete` | avant intégration ou après une modification importante | lint Python/PHP, tests, validateurs complets, intégrité runtime, sauvegarde/restauration, audit npm high/critical, build frontend, documentation et export statique à blanc |
| `release` | depuis le dépôt source complet, avant livraison ou déploiement | profil complet, tests Playwright, préflight de production, création du package, vérification de l’archive et installation neuve |

Le profil `complete` ne crée pas de package de release. Les profils `complete` et `release` exécutent `npm audit --audit-level=high` dans `frontend/admin-vue` avant le build frontend, sans lancer `npm ci` automatiquement. L’installation des dépendances reste une étape explicite de préparation locale ou de CI : `cd frontend/admin-vue && npm ci`. Le profil `release` compile le frontend puis lance Playwright sur une copie isolée avec des bases, un compte administrateur et un récepteur webhook éphémères. Aucune variable `E2E_*` n’est requise. Chromium doit avoir été installé une fois, après `npm ci`, avec `python3 tools/cms.py e2e --install-browser`. Si Chromium manque, la qualification s'arrête rapidement avec le code `2` plutôt que de lancer toute la suite E2E contre un navigateur absent.

## Cache local

Les étapes coûteuses `frontend-build` et `browser-e2e` utilisent un cache local sous `storage/qualification/cache/`. Le cache ne remplace une exécution que si la dernière exécution réussie correspond exactement à l’empreinte des fichiers suivis :

- sources et configuration `frontend/admin-vue` pour le build ;
- backend, routes, schémas, sources frontend, tests Playwright et harness E2E pour `browser-e2e`.

Une étape servie par le cache est reportée comme `passed`, avec un message `Cache qualification` dans la sortie. Pour forcer une qualification complète, notamment avant une vérification release stricte ou après un doute sur les assets générés :

```bash
python3 tools/cms.py qualify --profile release --no-cache
```

## Reconstruction des bases

La reconstruction de l’instance de travail ne fait partie d’aucun validateur ni d’aucun profil :

```bash
python3 tools/cms.py rebuild
```

Elle doit être déclenchée explicitement, après vérification de la cible et sauvegarde si nécessaire. La reconstruction effectuée par Playwright reste confinée à son répertoire temporaire.

## Codes de retour

- `0` : toutes les étapes requises ont réussi ;
- `1` : au moins une étape a échoué ;
- `2` : qualification incomplète, car une étape requise n'a pas pu être exécutée ;
- `3` : erreur interne de l'orchestrateur.

## Rapports

Par défaut, la commande écrit :

- `storage/qualification/latest.json` ;
- `storage/qualification/latest.md`.

Les chemins peuvent être remplacés avec `--json-report` et `--markdown-report`. `--continue-on-failure` permet de collecter toutes les erreurs dans une seule exécution sans modifier le code de retour final.

## Compatibilité

`tools/python/operations/deployment/d12_quality_gate.py` reste temporairement disponible, mais délègue au profil `release`. Les procédures et workflows nouveaux ne doivent plus appeler ce fichier directement.
