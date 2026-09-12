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
  - tools/python/qualification/usability_commerce_gate.py
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
| Utilisabilité Commerce M5–M7 | revue statique + sept captures Playwright, accessibilité et preuve JSON | `tools/python/qualification/usability_commerce_gate.py` et exécution E2E ciblée |
| Catalogue, panier, commande, paiement local, stock | tests PHP + `performance-baseline` | smoke structurel uniquement |
| Reconstruction et réconciliation stock M6.5 | `stock-reconstruction-gate` + scénario PHP + sauvegarde/restauration | `tools/python/qualification/stock_reconstruction_gate.py` et diagnostic CLI |
| Identité client M7.1 | `customer-identity-gate` + tests IAM/CRM/Sale + Playwright | `tools/python/qualification/customer_identity_gate.py` |
| Activités CRM par événements M7.2 | `crm-event-activity-gate` + tests de projection et chronologie | `tools/python/qualification/crm_event_activity_gate.py` |
| Segmentation et consentements M7.3 | `crm-segmentation-consent-gate` + tests métier et UX | `tools/python/qualification/crm_segmentation_consent_gate.py` |
| Commande invitée et CRM M7.4 | `crm-guest-order-gate` + checkout, identité, projection, consentements et Playwright | `tools/python/qualification/crm_guest_order_gate.py` |
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
- réservation de stock (création couverte par le checkout, puis lecture de preuve chronométrée séparément).

Le seuil critique par scénario est de `2000 ms` par défaut et s'applique à la
médiane des répétitions. Le p95 et le nombre d'échantillons au-dessus du seuil
restent consignés afin de rendre les pointes visibles sans transformer une seule
variation locale en échec. Le seuil est configurable avec :

```bash
AMCMS_M0_PERF_CRITICAL_MS=2500 AMCMS_M0_PERF_REPEAT=5 \
  python3 tools/cms.py qualify --profile release
```

Pour mesurer une instance externe, le vendable à utiliser doit être explicite afin que le panier, le checkout et le stock restent de vraies preuves transactionnelles :

```bash
AMCMS_M0_PERF_BASE_URL=https://instance.example \
AMCMS_M0_PERF_VARIANT_ID=123 \
  python3 tools/python/qualification/performance_baseline.py
```

Le rapport détaillé est écrit dans `storage/qualification/performance/latest.json`. Cette baseline qualifie la machine courante et doit être lue avec son contexte matériel ; elle ne remplace pas un test de charge réseau externe.

## Gate omnicanale storefront/POS

Le profil `release` exige la preuve JSON omnicanale M5/M6/M7 au format 2 sous `storage/qualification/omnichannel/latest.json`. Elle couvre checkout UI, paiement, ledger/réservations, fulfillment, CRM, POS, rapprochements, régressions et empreintes du paquet d’audit. Une exécution ciblée est disponible pour diagnostiquer uniquement ce contrat :

```bash
python3 tools/cms.py e2e --use-built-assets --omnichannel-only
```

Le détail des deux parcours, des comparaisons croisées et des mutations négatives est documenté dans [Gate E2E omnicanale storefront et POS](OMNICHANNEL_E2E_GATE.md).

## Gate d’utilisabilité Commerce M5–M7

Les profils `complete` et `release` valident la matrice statique des rôles, tâches, états et heuristiques. Le profil `release` exige en plus le rapport runtime et les sept captures hachées produits par Playwright. La commande ciblée reconstruit une instance jetable depuis les schémas canoniques et n’emploie aucune migration :

```bash
python3 tools/python/qualification/usability_commerce_gate.py --static-only
python3 tools/cms.py e2e --use-built-assets --usability-only
```

Le rapport runtime est écrit dans `storage/qualification/usability/latest.json`, avec les captures sous `storage/qualification/usability/captures/`. Il ne contient ni saisie client ni donnée de paiement. La méthode, la revue structurée, les recommandations P0/P1/P2 et ses limites sont documentées dans [Gate d’utilisabilité Commerce M5–M7](../../evaluation/usability-commerce-foundations.md).

## Gate release Shop opérationnel 48

Cette gate agrège les parcours canoniques 38a à 47 sur une instance reconstruite sans migration. Elle exige deux sites sur des chemins distincts, deux langues, la matrice de rôles, une activation Shop sélective, les scénarios métier A à E, les contrôles négatifs et les preuves UX. Elle ne crée pas de suite Commerce parallèle.

```bash
python3 tools/python/qualification/shop_operational_gate.py --static-only
python3 tools/cms.py e2e --use-built-assets --shop-operational-only
python3 tools/python/qualification/shop_operational_gate.py
```

Le rapport runtime sans donnée personnelle est écrit dans `storage/qualification/shop-operational/latest.json`. Une décision de diffusion requiert toujours `python3 tools/cms.py qualify --profile release`, car les performances, le paquet et l’installation neuve sont des étapes bloquantes du même profil. Voir [Gate release Shop opérationnel — point 48](../../evaluation/shop-operational-release-gate-48.md).

## Gate M6.5 reconstruction du stock

La porte vérifie le dry-run par défaut, les équations du ledger, les relations réservations/fulfillments/retours/transferts, la cohérence de la projection Business/Shop et la réparation obligatoirement motivée après sauvegarde :

```bash
python3 tools/python/qualification/stock_reconstruction_gate.py
python3 tools/cms.py inventory reconcile --site 1
```

Un diagnostic avec écarts retourne un code non nul afin de bloquer une automatisation. L'option `--repair` n'est jamais implicite et exige `--reason`. Les tests utilisent uniquement des bases temporaires reconstruites depuis `database/modules/*.sql`, puis copient et rouvrent les sauvegardes pour prouver leur restauration sans migration.

## Gate M7.4 commande invitée et CRM

La gate consolide les douze scénarios de commande invitée, rapprochement prudent, rejeu, panne CRM, retrait de consentement et panier abandonné :

```bash
python3 tools/python/qualification/crm_guest_order_gate.py
```

Le rapport machine-readable détaille identités avant/après, règles, décisions, événements, activités, consentements, doublons évités et métriques du parcours opérateur. Voir [Gate M7.4 commande invitée et CRM](CRM_GUEST_ORDER_GATE.md).

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
