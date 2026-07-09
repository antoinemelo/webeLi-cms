---
title: Tests PIM-lite Opérations
audience:
  - developer
  - evaluator
status: draft
last_verified: 2026-07-09
source_of_truth: code
source_paths:
  - tools/php/tests/unit/business_pim_lite_schema_test.php
  - tools/php/tests/unit/business_pim_lite_fixtures_test.php
  - tools/php/tests/unit/business_pim_lite_blueprints_test.php
  - tools/php/tests/unit/business_product_asset_service_test.php
  - tools/php/tests/unit/business_product_completeness_service_test.php
  - tools/php/tests/unit/business_pim_api_controller_test.php
  - tools/php/tests/unit/business_sellable_snapshot_service_test.php
  - tools/php/tests/integration/business_pim_http_test.php
  - tools/php/tests/integration/sale_uses_business_sellable_snapshot_test.php
  - tools/php/tests/run.php
owners:
  - business
  - sale
document_type: guide
generated: false
---
# Tests PIM-lite Opérations

Cette page regroupe les tests qui protegent le PIM-lite, le snapshot vendable et la frontiere avec Vente.

## Commandes

Depuis la racine du depot :

```bash
python3 tools/cms.py validate
python3 tools/cms.py docs check
python3 tools/cms.py test --timeout 400 --target-duration 200
```

Pour une verification PHP ciblee :

```bash
php tools/php/tests/run.php
```

Le runner PHP execute la suite fonctionnelle declaree dans `tools/php/tests/run.php`.

## Couverture principale

| Test | Couverture |
|---|---|
| `business_pim_lite_schema_test.php` | Tables, colonnes, indexes, enums et absence de modele media legacy. |
| `business_pim_lite_fixtures_test.php` | Fixtures PIM-lite chargees apres rebuild from scratch. |
| `business_pim_lite_blueprints_test.php` | Blueprints admin PIM, admin-only, champs sensibles. |
| `business_product_asset_service_test.php` | Assets produit, image principale, fallback variante/produit. |
| `business_product_completeness_service_test.php` | Score, attributs requis, variantes, canaux et stock. |
| `business_pim_api_controller_test.php` | Endpoints admin PIM, protections, assets, attributs, valeurs, completude et bulk. |
| `business_sellable_snapshot_service_test.php` | Snapshot vendable, masquage prix d'achat, assets, canaux, stock, bundles. |
| `business_pim_http_test.php` | Routes admin PIM protegees et absence de routes PIM publiques anonymes. |
| `sale_uses_business_sellable_snapshot_test.php` | Vente consomme le snapshot Business et refuse les variantes non vendables. |

## Ajouter une règle de complétude

Quand une nouvelle exigence produit est ajoutee :

1. ajouter ou adapter la logique dans `BusinessProductCompletenessService` ;
2. recalculer le score via `BusinessPimAdminService` ou l'endpoint admin ;
3. verifier que `business_product_completeness_scores.missing_json` contient un code explicite ;
4. ajouter une assertion dans `business_product_completeness_service_test.php` ;
5. verifier que `BusinessCatalogSellableReadService` relaie le blocage dans `missing_requirements` si la regle rend la variante non vendable.

## Ajouter un champ de snapshot

Quand Vente a besoin d'un nouveau champ catalogue :

1. l'ajouter dans `BusinessCatalogSellableReadService` ;
2. verifier s'il est public, admin-only ou sensible ;
3. mettre a jour `publicPayload()` si le champ doit etre retire des payloads publics ;
4. adapter `SaleCatalogSnapshotService` seulement si la copie Vente doit changer ;
5. ajouter une assertion dans `business_sellable_snapshot_service_test.php` et, si la frontiere Vente est touchee, dans `sale_uses_business_sellable_snapshot_test.php`.

## Contrôles de sécurité

Les tests doivent continuer a prouver que :

- les prix d'achat et marges ne sortent pas en payload public ;
- les routes PIM publiques non prevues ne repondent pas en `200` anonyme ;
- les endpoints admin PIM exigent une authentification et les permissions catalogue ;
- les assets publics ne transportent pas les notes ou droits internes ;
- Vente ne lit pas les tables Business directement dans ses workflows critiques.

## Limite locale

`business_pim_http_test.php` peut etre ignore automatiquement si l'environnement ne permet pas de lancer un serveur PHP local. Dans un environnement avec bind TCP disponible, il doit verifier les statuts HTTP reels.
