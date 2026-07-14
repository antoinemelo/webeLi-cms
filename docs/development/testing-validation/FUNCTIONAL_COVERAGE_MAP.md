---
title: Cartographie de couverture fonctionnelle
audience:
  - developer
  - evaluator
status: stable
last_verified: 2026-07-14
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
| Providers de paiement | tests séparés par adapter | contrat commun + gate PHP/Python/Playwright | capacités explicites, succès, refus/reprise, capture/remboursement, signature, doublon, réconciliation et UX Stripe/Revolut | ajouté pour M5.5 |
| Ledger de stock | quantités Business/Sale dispersées | ledger Sale + gate PHP/Python/Playwright | mouvements immuables, reconstruction, seed d’ouverture, contrat Shop, recherche scanner, assistant, erreurs et mobile | ajouté pour M6.1 |
| Réservations et disponibilité | réservation implicite sans politique de canal | concurrence PHP + gate Python + API/Playwright | déclencheurs configurables, TTL borné, expiration idempotente, backorder explicite, reprise Shop et libération opérateur auditée | ajouté pour M6.2 |
| Bundles et stock composé | modes historiques ambigus et stock du parent | tests Business/Sale + gate Python + UX opérateur | trois stratégies, ratios imbriqués, facteur limitant, cycles, concurrence, multi-location, consommation et retours | ajouté pour M6.3 |
| Reconstruction du stock | réparation historique implicite et comparaison partielle | scénario PHP + gate Python + UX opérateur | dry-run, journal/cache/réservations/logistique/Shop, sauvegarde-restauration, correction prouvée et reprise ciblée | ajouté pour M6.5 |
| Identité client | bridges nuls et fusion opaque | scénario IAM/CRM/Sale + gate Python + Playwright | invité, token post-achat, provenance, scores, doublons, multisite, aperçu, fusion et séparation sans toucher aux snapshots | ajouté pour M7.1 |
| Activités CRM événementielles | projection partielle relisant les commandes | scénario outbox + gate Python + chronologie Vue | DTO v2, désordre, panne CRM, attente/rattachement, confidentialité, rejeu et réconciliation | ajouté pour M7.2 |

## Tests ajoutés

- `tools/php/tests/unit/editorial_status_test.php`
- `tools/php/tests/integration/auth_permissions_test.php`
- `tools/php/tests/integration/webhook_repository_test.php`
- `tools/php/tests/integration/multisite_locale_test.php`
- `tools/python/tests/integration/test_php_functional_suites.py`
- `tools/python/tests/integration/test_webhook_ping_persistence.py`
- `frontend/admin-vue/tests/e2e/webhook-ping-persistence.spec.ts`
- `tools/php/tests/unit/sale_payment_provider_interchangeability_test.php`
- `tools/python/tests/test_payment_provider_gate.py`
- `frontend/admin-vue/tests/e2e/payment-provider-interchangeability.spec.ts`
- `tools/php/tests/unit/sale_inventory_reconciliation_test.php`
- `tools/python/tests/test_inventory_ledger_gate.py`
- `frontend/admin-vue/tests/e2e/sale-stock-ledger.spec.ts`
- `tools/php/tests/unit/sale_reservation_lifecycle_test.php`
- `tools/python/tests/test_reservation_availability_gate.py`
- `frontend/admin-vue/tests/e2e/sale-reservations.spec.ts`
- `tools/php/tests/unit/business_bundle_stock_strategies_test.php`
- `tools/php/tests/unit/sale_inventory_service_test.php` (scénarios bundle)
- `tools/php/tests/unit/sale_internal_sales_test.php` (retours bundle)
- `tools/python/tests/test_bundle_stock_strategy_gate.py`
- `tools/php/tests/unit/sale_stock_reconstruction_scenario_test.php`
- `tools/python/tests/test_stock_reconstruction_gate.py`
- `frontend/admin-vue/tests/e2e/sale-stock-reconstruction.spec.ts`
- `tools/python/tests/test_customer_identity_gate.py`
- `frontend/admin-vue/tests/e2e/sale-identity-review.spec.ts`

## Tests volontairement non automatisés dans ce lot

Les parcours suivants nécessitent des fixtures applicatives stables ou un serveur d’essai complet avant d’être fiables : upload réel d’images et génération de variantes, audit SEO de pages rendues, soumission de formulaires avec protection anti-spam, cycle complet création–révision–publication–archivage, et comparaison des projections publiques après publication. Ils ne doivent pas être remplacés par une inspection de texte ; ils restent explicitement des écarts de couverture.

## Règle de stabilité

Les tests PHP interrogent des états métier et des lignes persistées. Le scénario Playwright utilise des rôles accessibles et des attributs métier (`data-delivery-id`, `data-delivery-status`) plutôt que des classes CSS ou des textes marketing.
