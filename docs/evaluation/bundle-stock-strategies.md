---
title: "Bundles et stratégies de stock M6.3"
audience:
  - evaluator
  - administrator
  - developer
status: stable
last_verified: 2026-07-14
source_of_truth: implementation
source_paths:
  - database/modules/business.sql
  - database/modules/sale.sql
  - backend/src/Modules/Business/Services/BusinessProductBundleService.php
  - backend/src/Modules/Sale/Services/SaleInventoryService.php
  - backend/src/Modules/Sale/Services/SaleReturnService.php
  - frontend/admin-vue/src/views/modules/BusinessCatalogView.vue
owners:
  - business
  - sale
document_type: evaluation
generated: false
---
# M6.3 — Bundles et stock composé

Un bundle possède obligatoirement une stratégie métier explicite. `OWN_STOCK` en fait un article de stock autonome. `COMPONENT_DERIVED` produit un plan aplati de composants et calcule la quantité vendable par le minimum de `stock disponible / ratio`. `NON_STOCKED` ne crée ni réservation ni mouvement physique. L’ancien champ technique `stock_mode` reste une projection de compatibilité déterministe et ne constitue plus un choix concurrent.

## Configuration et disponibilité

L’assistant du catalogue présente d’abord les trois stratégies et leur conséquence, puis les options avancées. Il permet de rechercher, ajouter, doser et réordonner les composants, y compris des bundles imbriqués. L’estimation immédiate expose l’état global et le composant limitant. Les cycles, ratios non positifs, composants non vendables et variantes absentes sont bloquants.

Les bundles imbriqués sont aplatis jusqu’à huit niveaux. Les feuilles identiques sont fusionnées et leurs ratios additionnés. La disponibilité affichée dans `/shop` est globale : le détail public peut expliquer les composants inclus, si `components_public` l’autorise, mais ne révèle ni stock exact interne ni algorithme de calcul.

## Réservation, consommation et retours

Pour `COMPONENT_DERIVED`, Sale réserve atomiquement les feuilles suivies à l’emplacement du canal, en conservant le lien vers le bundle parent. La consommation écrit un mouvement immuable `bundle_consumption`; un rejeu ne consomme jamais deux fois. Les opérateurs voient quel composant est réservé pour quel bundle.

Un retour de bundle complet remet en stock chaque feuille suivie selon son ratio. Un retour de composant seul n’est accepté que sous la politique `COMPONENTS_ALLOWED` et uniquement pour une feuille du plan historique de la commande. `OWN_STOCK` remet en stock le bundle ; `NON_STOCKED` ne remet rien en stock.

## Limites v1

- `ALLOW_PARTIAL` est modélisé mais refusé tant que le fulfillment ne peut pas représenter et suivre un reliquat ; la politique active est donc `REQUIRE_ALL`.
- profondeur maximale : huit bundles ; un dépassement rend la configuration indisponible ;
- l’estimation catalogue est globale, tandis que la réclamation transactionnelle se fait sur l’emplacement configuré pour le canal ;
- les ratios sont décimaux dans le catalogue mais arrondis au supérieur lors d’une demande physique entière ;
- la composition historique de la ligne de commande pilote les retours, jamais la configuration courante ;
- aucun mécanisme de migration n’est requis : les schémas canoniques sont reconstruits from scratch.

## Preuves reproductibles

```bash
php tools/php/tests/unit/business_bundle_stock_strategies_test.php
php tools/php/tests/unit/sale_inventory_service_test.php
php tools/php/tests/unit/sale_internal_sales_test.php
python3 tools/python/qualification/bundle_stock_strategy_gate.py
```

Ces scénarios couvrent ratios et facteur limitant, imbrication, cycle, concurrence sur le dernier composant, emplacement de canal, absence de double mouvement, trois stratégies et retours complets ou par composant.
