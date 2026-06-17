---
title: Cartographie de couverture fonctionnelle
audience:
  - developer
  - evaluator
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: manual
owners:
  - core
document_type: reference
generated: false
---
# Cartographie de la couverture fonctionnelle

## Principe

Les validateurs Python restent limités aux invariants statiques et déterministes. Les comportements observables sont couverts par des tests exécutant le code PHP, les dépôts SQLite, les contrats HTTP ou le navigateur.

| Domaine | Ancien contrôle | Niveau cible | Preuve ajoutée | État |
|---|---|---|---|---|
| Authentification | présence de classes et permissions | intégration PHP | utilisateur authentifié reproductible dans IAM temporaire | ajouté |
| Permissions | recherche de chaînes `can`/`require` | intégration PHP + API/E2E | autorisé, interdit, appel direct, isolation par site | ajouté pour permissions critiques génériques |
| Brouillon/publication/archivage | présence des états dans la configuration | unitaire + intégration | normalisation, divergence rejetée, conservation de l’état publié lors d’un draft | ajouté au niveau unitaire ; cycle DB complet restant |
| Création de contenu | inspection de ports/classes | intégration/API | données et révisions réelles | à compléter avec fixture de content type stable |
| Médias | présence du modèle de stockage | intégration/API/E2E | upload, métadonnées, rattachement, suppression protégée | volontairement non automatisé dans ce lot |
| SEO | présence de tables/configuration | intégration/API | projection SEO, canonical et hreflang observables | volontairement non automatisé dans ce lot |
| Formulaires | présence des routes | API/E2E | validation, soumission, anti-spam et isolation | volontairement non automatisé dans ce lot |
| Recherche | présence de projection | intégration/API | isolation `site_id` + `language_code` sur documents réels | ajouté, pertinence et reindexation restant |
| Webhooks | fragments du contrôleur | intégration PHP + Playwright | création de livraison, idempotence, URL refusée, persistance, reload, permission directe, secret absent | ajouté |
| Multisite | colonnes et seeds | intégration PHP + E2E | accès et permissions isolés par site | ajouté |
| Multilingue | colonnes et seeds | intégration PHP | documents de recherche isolés par langue | ajouté |
| API | routes non dupliquées | test API/Playwright request | refus 401/403 sur appel direct webhook | ajouté partiellement |

## Tests ajoutés

- `tools/php/tests/unit/editorial_status_test.php`
- `tools/php/tests/integration/auth_permissions_test.php`
- `tools/php/tests/integration/webhook_repository_test.php`
- `tools/php/tests/integration/multisite_locale_test.php`
- `tools/python/tests/integration/test_php_functional_suites.py`
- `tools/python/tests/integration/test_webhook_ping_persistence.py`
- `frontend/admin-vue/tests/e2e/webhook-ping-persistence.spec.ts`

## Tests volontairement non automatisés dans ce lot

Les parcours suivants nécessitent des fixtures applicatives stables ou un serveur d’essai complet avant d’être fiables : upload réel d’images et génération de variantes, audit SEO de pages rendues, soumission de formulaires avec protection anti-spam, cycle complet création–révision–publication–archivage, et comparaison des projections publiques après publication. Ils ne doivent pas être remplacés par une inspection de texte ; ils restent explicitement des écarts de couverture.

## Règle de stabilité

Les tests PHP interrogent des états métier et des lignes persistées. Le scénario Playwright utilise des rôles accessibles et des attributs métier (`data-delivery-id`, `data-delivery-status`) plutôt que des classes CSS ou des textes marketing.
