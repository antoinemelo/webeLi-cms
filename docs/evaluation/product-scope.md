---
title: Périmètre réel du produit
document_type: evaluation
audience:
  - evaluator
  - ai-evaluator
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: manual
source_paths:
  - tools/python/qualification/run_all.py
  - tools/python/validation
  - docs/evaluation/machine-readable

generated: false
owners:
  - core
evidence_scope:
  - code
  - tests
  - validators
  - documentation
---

# Périmètre réel du produit

## Positionnement observable

Le dépôt implémente un CMS éditorial hybride : rendu public côté serveur en PHP avec Twig, back-office SPA Vue/TypeScript, API publique versionnée et API administrative interne. Le stockage principal est réparti entre plusieurs bases SQLite. Python fournit une façade d’exploitation, de reconstruction, de validation, de sauvegarde, d’export et de release.

## Publics et projets ciblés

Les guides et permissions couvrent éditeur, publicateur, responsable SEO, administrateur, superadministrateur, intégrateur API, développeur et exploitant. Le produit paraît ciblé sur des sites éditoriaux multisites et multilingues, avec déploiement possible sans infrastructure distribuée. La capacité réelle à servir une très forte charge n’est pas qualifiée.

## Technologies et dépendances

- PHP `^8.2` et Twig `^3.0` pour le runtime.
- Vue, TypeScript, Pinia, Vue Router, Vite et Bootstrap pour le back-office source ; assets compilés sous `admin-app/`.
- SQLite pour les données.
- Python 3 pour les outils locaux et de release.
- Node.js est nécessaire pour reconstruire le back-office, pas pour utiliser les assets déjà compilés.

## Capacités par catégorie

| Catégorie | État | Observation |
|---|---|---|
| contenu, révisions, publication | `supported` | schémas, services, API et validateurs présents |
| multisite, multilingue | `supported` | modèles, routes et validateurs dédiés |
| SEO, sitemap, hreflang, redirections | `supported` | données, runtime et validateurs dédiés |
| API publique v1 | `supported` | kernel, handlers, OpenAPI et documentation |
| export statique | `partial` | implémentation et validateurs présents ; environnement cible à vérifier |
| assistant IA | `experimental` | module, base et contrôles présents ; dépend de fournisseurs/configuration |
| stockage média S3 | `partial` | driver présent ; exploitation externe non démontrée ici |
| haute disponibilité distribuée | `not-supported` | aucune architecture de cluster démontrée |
| marketplace d’extensions | `not-supported` | fondation de modules présente, pas de marketplace démontrée |

## Distribution et environnements

Une chaîne de release et un packaging existent. Le déploiement FTP/SFTP est exposé par la CLI. Le support d’un hébergement mutualisé dépend de PHP 8.2, des extensions SQLite, des droits filesystem, de la réécriture HTTP et des headers configurables. Les environnements Windows, conteneurisés ou orchestration cloud ne sont pas qualifiés par cette documentation.
