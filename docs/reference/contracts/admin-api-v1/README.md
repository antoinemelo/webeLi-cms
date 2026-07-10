---
title: Contrats de l’API administrative v1
audience:
  - administrator
  - superadministrator
  - api-integrator
  - developer
status: stable
last_verified: 2026-06-14
source_of_truth: contract
owners:
  - api
document_type: reference
generated: false
---

# Contrats de l’API administrative v1

Ce répertoire contient les contrats JSON de la surface `/admin/api`. Ils décrivent les requêtes et réponses attendues par le back-office et servent de référence aux contrôleurs, aux tests et aux validateurs.

## Périmètre de sécurité

Toutes les routes `/admin/api` exigent une session admin valide. Le garde de requête administrative est exécuté avant la résolution de la route et applique également le délai d’inactivité de la session.

Les lectures (`GET`) exigent la session, mais n’exigent pas de jeton CSRF ni de corps JSON.

Les écritures admin (`POST`, `PUT`, `PATCH`, `DELETE`) doivent utiliser les deux en-têtes suivants avant que le corps soit décodé :

```http
Content-Type: application/json
X-CSRF-Token: <jeton-csrf-de-la-session>
```

Une écriture sans `Content-Type: application/json` est rejetée. Une écriture sans `X-CSRF-Token`, ou avec un jeton invalide, est également rejetée.

Ces protections ne remplacent pas l’autorisation métier : après authentification et validation CSRF, chaque opération reste soumise aux permissions et au contexte de site applicables.

## Erreurs normalisées

Les erreurs de sécurité et de contrat sont levées sous forme d’exceptions API normalisées. Elles couvrent notamment :

- session absente ou expirée ;
- autorisation insuffisante ;
- version de contrat non supportée ;
- type de contenu JSON requis ;
- jeton CSRF rejeté ;
- corps JSON invalide.

Le contrat commun d’erreur est défini dans `error.v1.json`.

## Organisation des contrats

Chaque fichier JSON représente une opération ou une famille d’opérations administratives. Les noms suivent la convention :

```text
admin.<domaine>.<action>.v1.json
```

Exemples :

- `admin.entries.publish.v1.json` ;
- `admin.media.upload.v1.json` ;
- `admin.iam.roles.index.v1.json` ;
- `admin.configuration.v1.json`.

Les contrats documentent la méthode HTTP, le chemin, le contexte, les permissions, les données d’entrée, la réponse et les erreurs attendues lorsqu’elles sont applicables.

## Sources de vérité

La surface réellement exposée est définie par :

- `backend/routes/api.php` pour les routes ;
- `backend/src/Security/AdminApiRequestGuard.php` pour le périmètre de sécurité commun ;
- les contrôleurs et services administratifs pour les règles métier ;
- les permissions natives et les affectations de rôles pour l’autorisation ;
- les fichiers JSON de ce répertoire pour le contrat documentaire.

## Validation

Depuis la racine du projet :

```bash
python3 tools/cms.py validate
python3 tools/cms.py validate
python3 tools/cms.py validate
```

Le premier contrôle vérifie notamment que l’authentification précède la résolution de route, que les écritures imposent JSON puis CSRF avant le décodage, et que les règles ci-dessus restent explicitement documentées.
