---
title: Contrôles de santé natifs
audience:
  - installer
  - administrator
  - superadministrator
  - developer
status: stable
last_verified: 2026-06-17
source_of_truth: code
source_paths:
  - backend/public/index.php
  - backend/config/health.php
  - backend/src/Health/HealthEndpoint.php
  - backend/src/Health/HealthCheckService.php
  - ops/.env.example
owners:
  - core
  - operations
document_type: runbook
generated: false
---
# Contrôles de santé natifs

webeLi fournit deux endpoints simples, activés par défaut et indépendants d'une configuration avancée Nginx ou Cloudflare.

## Endpoints

### `GET /health/live`

Confirme uniquement que PHP peut exécuter le point d'entrée HTTP. La réponse intervient avant le démarrage d'une session, l'ouverture de SQLite et l'initialisation du CMS.

Réponse normale :

```json
{"status":"live"}
```

Le statut HTTP attendu est `200`.

### `GET /health/ready`

Vérifie que l'origine peut raisonnablement servir le trafic public. Le contrôle reste local et sans écriture :

- PHP 8.2 ou supérieur et extension `pdo_sqlite` ;
- lecture des répertoires de stockage requis ;
- présence et lecture minimale des bases `core` et `iam` avec `SELECT 1` ;
- respect d'une durée totale configurée.

Le statut HTTP est `200` avec `status=ready`, ou `503` avec `status=not_ready`. La réponse n'expose ni chemin local, ni exception, ni donnée de configuration sensible.

## Configuration

```dotenv
APP_HEALTH_ENABLED=1
APP_HEALTH_READY_TIMEOUT_MS=1600
APP_HEALTH_DB_BUSY_TIMEOUT_MS=100
APP_HEALTH_CHECK_DATABASES=1
APP_HEALTH_CHECK_STORAGE=1
```

Désactiver rapidement les deux endpoints :

```dotenv
APP_HEALTH_ENABLED=0
```

Les options Nginx ou Cloudflare peuvent interroger ces endpoints plus tard, mais elles ne sont pas nécessaires au fonctionnement standard du CMS.

## Propriétés opérationnelles

- aucun compteur ni état n'est écrit dans SQLite ;
- aucune requête externe ou résolution DNS n'est effectuée ;
- aucun export statique n'est généré ;
- aucune session n'est créée ;
- les réponses utilisent `Cache-Control: no-store` et `X-Robots-Tag: noindex` ;
- une méthode autre que `GET` retourne `405` ;
- le contrôle `ready` est indépendant du trafic des visiteurs et doit être appelé par un moniteur selon une cadence raisonnable, par exemple toutes les 30 secondes.

## Vérification manuelle

```bash
curl -i https://example.test/health/live
curl -i https://example.test/health/ready
```

Avec un sous-répertoire configuré par `APP_BASE_PATH=/mod` :

```bash
curl -i https://example.test/mod/health/live
curl -i https://example.test/mod/health/ready
```

## Limites

La durée configurée est une condition de résultat, pas un mécanisme capable d'interrompre une opération système bloquée. Le `PRAGMA busy_timeout` réduit l'attente SQLite ; le frontal HTTP doit également définir son propre timeout. Le contrôle ne teste volontairement ni un service externe, ni une génération complète Twig, afin de rester déterministe et peu coûteux.

## Consultation dans le back-office

Une vue en lecture seule est disponible dans **Configuration > Système**, placée après l’onglet **Relations**.

Elle permet de :

- consulter les URL `/health/live` et `/health/ready` ;
- lancer manuellement les deux contrôles ;
- voir le statut HTTP retourné ;
- voir la durée mesurée depuis le navigateur ;
- consulter la réponse JSON publique des endpoints.

Cette vue ne modifie aucun paramètre, n’écrit pas dans SQLite et ne remplace pas un outil de supervision externe. Les réglages restent définis dans les variables d’environnement et dans `backend/config/health.php`.
