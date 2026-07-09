---
title: Revue finale PIM-lite Opérations
audience:
  - developer
  - administrator
  - evaluator
status: draft
last_verified: 2026-07-09
source_of_truth: code
source_paths:
  - docs/development/architecture/business-pim-lite.md
  - docs/development/architecture/business-pim-lite-schema.md
  - docs/development/architecture/business-sellable-snapshot.md
  - docs/development/architecture/business-product-assets.md
  - docs/development/testing-validation/business-pim-lite.md
  - database/modules/business.sql
  - backend/src/Application/Api/Admin/BusinessPimApiController.php
  - backend/src/Modules/Business/Services/BusinessProductAssetService.php
  - backend/src/Modules/Business/Services/BusinessProductCompletenessService.php
  - backend/src/Modules/Business/Services/BusinessCatalogSellableReadService.php
  - backend/src/Modules/Sale/Services/SaleCatalogSnapshotService.php
  - frontend/admin-vue/src/views/modules/BusinessCatalogView.vue
  - tools/php/tests/unit/business_product_completeness_service_test.php
  - tools/php/tests/unit/business_sellable_snapshot_service_test.php
  - tools/php/tests/integration/sale_uses_business_sellable_snapshot_test.php
owners:
  - business
  - sale
document_type: review
generated: false
---
# Revue finale PIM-lite Opérations

## Décision

**GO pour reprendre Vente au point 16**, avec le contrat suivant :

- Vente consomme le catalogue via `SaleCatalogSnapshotService`.
- `SaleCatalogSnapshotService` consomme `BusinessCatalogSellableReadService`.
- Les workflows Vente ne lisent pas directement les tables `business_*`.
- Les prix, libelles, taxes, remises, assets et diagnostics utiles sont figes dans les snapshots Vente au moment de la transaction.

Le PIM-lite atteint le niveau attendu pour une reprise pragmatique de Vente : il améliore la lisibilite business du catalogue, explique les produits incomplets, normalise les assets produit/variante, protege les prix d'achat et fournit un snapshot vendable testable.

## Réponses aux questions de revue

| Question | Décision |
|---|---|
| Le PIM-lite améliore-t-il l'usage business ? | Oui. La liste produits expose SKU, statut, canaux, prix, stock, médias, complétude et actions ciblées. |
| L'UI permet-elle de voir les produits incomplets ? | Oui. Les tags et indicateurs signalent image manquante, prix manquant, variante inactive et completude. |
| Les assets produit/variante sont-ils bien gérés ? | Oui. `business_product_assets` est la source métier, avec fallback variante puis produit. |
| Les attributs restent-ils génériques ? | Oui. Groupes, attributs, options et valeurs restent configurables et non codés par type produit. |
| La complétude est-elle simple et utile ? | Oui. Le score reste explicite et les exigences manquantes sont consommées par le snapshot. |
| Le snapshot vendable est-il stable ? | Oui. `BusinessCatalogSellableReadService` expose prix en unités mineures, canaux, tax, assets, stock et `missing_requirements`. |
| Vente consomme-t-il le snapshot ? | Oui. `SaleCatalogSnapshotService` refuse une variante non vendable et stocke la référence snapshot. |
| Les prix achat sont-ils protégés ? | Oui. Ils sont séparés des prix de vente et retirés des payloads publics. |
| L'API publique est-elle sûre ? | Oui. Les endpoints publics catalogue n'exposent que les données publiques et nettoient achat, marges, stock exact et champs internes. |
| Les tests couvrent-ils les cas critiques ? | Oui pour schema, fixtures, assets, attributs, completude, snapshot, API admin, API publique et frontiere Vente. |
| La documentation permet-elle de reprendre Vente ? | Oui. Les documents PIM-lite, snapshot, assets et tests décrivent le chemin de reprise. |

## Critères GO

| Critère | Statut |
|---|---|
| Snapshot vendable complet | GO |
| Variantes non vendables expliquées | GO |
| Image principale résolue | GO |
| Canal POS/e-commerce respecté | GO |
| Prix achat/vente séparés | GO |
| Tests passent | GO |

## Actions obligatoires avant de reprendre Vente

1. Garder `SaleCatalogSnapshotService::snapshotForVariant()` comme seule entrée catalogue pour les workflows Vente.
2. Refuser toute lecture directe `business_products`, `business_product_variants`, `business_product_base_prices` ou tables PIM depuis les services transactionnels Vente.
3. Copier les champs de snapshot dans les lignes de panier/commande au moment de la transaction.
4. Continuer a retirer prix d'achat, marges et champs internes des surfaces publiques.
5. Conserver les tests `sale_uses_business_sellable_snapshot_test.php` et `business_sellable_snapshot_service_test.php` comme garde-fous.

## Actions v1.1

- Ajouter une passe E2E plus visuelle sur les modales produit, assets et attributs.
- Ajouter des regles de completude configurables par famille produit plus riches.
- Ajouter une revue UX après usage réel sur un catalogue plus volumineux.
- Stabiliser les exports/imports CSV PIM avancés si l'utilisateur les exploite en production.
- Définir la synchronisation contrôlée entre stock catalogue Opérations et stock transactionnel Vente.
- Documenter les cas limites des bundles : composants optionnels, disponibilité partielle et prix calculé depuis composants.

## Risques restants

| Risque | Niveau | Suivi |
|---|---|---|
| Couplage futur de Vente aux tables Business | Moyen | Bloquer par revue de code et tests de dépendances. |
| UI produit encore dense pour gros catalogue | Moyen | Traiter en v1.1 après retours d'usage. |
| Regles de completude encore simples | Faible | Étendre dans `BusinessProductCompletenessService` sans changer le contrat snapshot. |
| Bundles v1 volontairement simples | Moyen | Les garder comme produits porteurs vendables, puis enrichir après premiers workflows Vente. |
| Stock catalogue vs stock transactionnel | Moyen | Vente doit devenir source transactionnelle pour reservations, retours et mouvements. |

## Tests exécutés

Commandes exécutées pendant la clôture PIM-lite :

```bash
python3 tools/cms.py rebuild
python3 tools/cms.py validate
python3 tools/cms.py docs generate
python3 tools/cms.py docs check
python3 tools/cms.py migrate --plan
python3 tools/cms.py smoke
php tools/php/tests/unit/business_pim_lite_fixtures_test.php
php tools/php/tests/unit/business_catalog_schema_test.php
php tools/php/tests/run.php
```

Résultats observés :

- rebuild : OK ;
- validate : OK ;
- docs generate : OK ;
- docs check : OK ;
- migrate plan : OK, aucune migration à appliquer ;
- smoke : OK, 0 avertissement après correction du manifest release ;
- suite PHP fonctionnelle : OK ;
- test HTTP PIM : skip local si bind TCP indisponible, comportement conforme aux autres tests HTTP isolés.

## Conclusion

Le chantier PIM-lite est suffisamment stable pour reprendre Vente. La reprise doit se faire en préservant la frontière Business/Vente : Business prépare et qualifie les produits, Vente fige les transactions à partir de snapshots.
