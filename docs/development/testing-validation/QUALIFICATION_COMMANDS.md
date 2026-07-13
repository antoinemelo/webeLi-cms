---
title: Commandes de qualification
audience:
  - developer
  - administrator
  - evaluator
status: current
last_verified: 2026-07-13
source_of_truth: code
source_paths:
  - tools/python/qualification/run_all.py
  - tools/python/qualification/performance_baseline.py
  - tools/python/qualification/omnichannel_gate.py
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

## Gate M0 officielle

La commande officielle de sortie M0 est :

```bash
python3 tools/cms.py qualify --profile release
```

Elle échoue avec un code non nul si un contrôle critique échoue : endpoint documenté en 500 pendant les tests, divergence routes/OpenAPI/SDK, test critique en échec, sauvegarde ou restauration non intègre, fuite PII détectée, dépendance PHP/npm vulnérable non acceptée, seuil de performance critique dépassé, package invalide ou installation neuve impossible. Un code `2` signifie que la gate est incomplète, par exemple parce qu’un prérequis local manque ; ce n’est jamais un succès.

Le rapport JSON `storage/qualification/latest.json` contient `version`, `commit`, `environment`, `commands`, `duration_ms`, les résultats de chaque étape, une matrice `m0_gate.source_vs_release`, les limites connues et les SHA-256 d’artefacts disponibles.

## Contrôles source et release

| Exigence M0 | Dépôt source complet | Release distribuée |
|---|---|---|
| Validation statique | `python-lint`, `php-lint`, `validate-core` | `tools/cms.py validate` |
| Tests PHP/Python/TypeScript | `tools/cms.py test`, `frontend-build` | non inclus dans l’archive |
| Build back-office | `npm run build` via `frontend-build` | assets déjà livrés |
| Reconstruction from scratch | instance isolée Playwright et baseline performance | `tools/cms.py rebuild` explicite |
| Smoke HTTP / E2E | Playwright isolé et installation neuve | `tools/cms.py smoke` |
| Gate omnicanale storefront/POS | Playwright isolé, preuve JSON validée | commande ciblée disponible depuis le dépôt source |
| Catalogue, panier, commande, paiement local, stock | tests PHP + `performance-baseline` | smoke structurel uniquement |
| Backup / restore | `BACKUP_RESTORE_ROUNDTRIP` avec SHA-256 et intégrité SQLite | `tools/cms.py backup`, `tools/cms.py backup --restore` |
| Documentation / OpenAPI / SDK | `docs generate`, `docs check`, `API_SPEC` | `tools/cms.py docs check` |
| Dépendances | `composer audit --locked`, `npm audit --audit-level=high` | audit externe à l’archive |

## Baseline performance

Le profil `release` exécute `tools/python/qualification/performance_baseline.py` après le build frontend. Par défaut, l’outil crée une instance isolée, reconstruit les bases from scratch, démarre le serveur PHP local, puis mesure :

- storefront SSR ;
- page de login back-office ;
- API catalogue Sale ;
- création de panier ;
- ajout de ligne ;
- lecture panier ;
- checkout avec paiement local ;
- réservation de stock.

Le seuil critique par scénario est de `2000 ms` par défaut. Il est configurable avec :

```bash
AMCMS_M0_PERF_CRITICAL_MS=2500 AMCMS_M0_PERF_REPEAT=5 \
  python3 tools/cms.py qualify --profile release
```

Le rapport détaillé est écrit dans `storage/qualification/performance/latest.json`. Cette baseline qualifie la machine courante et doit être lue avec son contexte matériel ; elle ne remplace pas un test de charge réseau externe.

## Gate omnicanale storefront/POS

Le profil `release` exige une preuve JSON valide sous `storage/qualification/omnichannel/latest.json`. Une exécution ciblée est disponible pour diagnostiquer uniquement ce contrat :

```bash
python3 tools/cms.py e2e --use-built-assets --omnichannel-only
```

Le détail des deux parcours, des comparaisons croisées et des mutations négatives est documenté dans [Gate E2E omnicanale storefront et POS](OMNICHANNEL_E2E_GATE.md).

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
