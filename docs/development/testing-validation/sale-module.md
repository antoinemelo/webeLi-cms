---
title: Tests et validation Vente
audience:
  - developer
  - evaluator
status: draft
last_verified: 2026-07-10
source_of_truth: code
source_paths:
  - tools/php/tests/unit/sale_module_contracts_test.php
  - tools/php/tests/unit/sale_snapshot_services_test.php
  - tools/php/tests/unit/sale_pricing_service_test.php
  - tools/php/tests/unit/sale_idempotency_service_test.php
  - tools/php/tests/unit/sale_inventory_service_test.php
  - tools/php/tests/unit/sale_domain_workflows_test.php
  - tools/php/tests/unit/sale_admin_api_controller_test.php
  - tools/php/tests/unit/sale_public_api_handler_test.php
  - tools/php/tests/integration/sale_uses_business_sellable_snapshot_test.php
  - tools/python/tests/test_sale_module_smoke.py
  - tools/php/tests/run.php
owners:
  - sale
  - business
document_type: guide
generated: false
---
# Tests et validation Vente

Cette page liste les tests qui protegent le module Vente et la frontiere avec Opérations.

## Commandes rapides

Depuis la racine du depot :

```bash
php tools/php/tests/unit/sale_module_contracts_test.php
php tools/php/tests/unit/sale_domain_workflows_test.php
php tools/php/tests/unit/sale_admin_api_controller_test.php
php tools/php/tests/unit/sale_public_api_handler_test.php
php tools/php/tests/integration/sale_uses_business_sellable_snapshot_test.php
python3 tools/cms.py validate
python3 tools/cms.py docs check
python3 tools/cms.py smoke
```

Pour la qualification complete :

```bash
php tools/php/tests/run.php
python3 tools/cms.py test --timeout 300 --target-duration 120
```

## Couverture principale

| Test | Couverture |
|---|---|
| `sale_module_contracts_test.php` | Provider, permissions, blueprints, routes, contrats API, schema SQL, seeds, invariants SQL et absence d'exports publics. |
| `sale_snapshot_services_test.php` | Copie de snapshots vendables et clients, masquage public et conservation des donnees transactionnelles. |
| `sale_pricing_service_test.php` | Totaux, taxes incluses/exclues, remises, prix d'achat ignore dans les totaux client et total non negatif. |
| `sale_idempotency_service_test.php` | Rejeu, verrou logique, payload different et erreurs conservees. |
| `sale_inventory_service_test.php` | Reservations, backorder, expiration, consommation, mouvements et retours restockes. |
| `sale_domain_workflows_test.php` | Panier, checkout, paiement, remboursement, events, outbox, snapshots immuables et refus de variante non vendable. |
| `sale_admin_api_controller_test.php` | Surface admin : routes, POS, recus, exports, imports, rapports, contextes IA et permissions. |
| `sale_public_api_handler_test.php` | API e-commerce optionnelle, canal non public refuse, token opaque, payload public sans prix d'achat et checkout idempotent. |
| `sale_uses_business_sellable_snapshot_test.php` | Integration Opérations/Vente par snapshot vendable, sans lecture directe non maitrisee. |
| `test_sale_module_smoke.py` | Manifest, base, tables, provider, permissions, migrations et montants mineurs. |

## Invariants critiques

Les tests doivent continuer a prouver que :

- un panier converti ne peut pas produire deux commandes ;
- un paiement ou remboursement idempotent ne cree pas de doublon ;
- aucun total client ne devient negatif ;
- une commande placee ne se recalcule pas depuis le catalogue courant ;
- tout changement de stock transactionnel ecrit un mouvement ;
- les prix d'achat ne sont pas publics ;
- l'API e-commerce ne repond pas pour un canal brouillon ou non public ;
- Vente reste demarrable sans dependance dure a Opérations.

## Ajouter un workflow

1. Ajouter le comportement dans le service domaine avant le controleur.
2. Ajouter un test unitaire service pour l'invariant.
3. Ajouter un test API uniquement si la surface HTTP change.
4. Mettre a jour `SaleModuleProvider::apiContracts()` si une route est ajoutee.
5. Regenerer la documentation si les routes ou contrats changent.

## Ajouter un evenement

1. Ajouter le topic dans `database/modules/sale.sql`.
2. Ajouter le contrat dans `SaleIntegrationEventContracts`.
3. Emettre via `SaleEventService`.
4. Tester `sale_events` et `sale_outbox`.
5. Documenter le payload dans l'architecture ou l'API admin si expose.

## Limites de test locales

Les tests HTTP avec serveur PHP peuvent etre ignores si l'environnement local ne permet pas le bind TCP. Les tests unitaires PHP et la suite Python hors serveur doivent rester verts.
