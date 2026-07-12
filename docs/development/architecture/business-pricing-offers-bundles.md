---
title: Pricing, offres et compositions Business
audience:
  - developer
  - administrator
status: draft
last_verified: 2026-07-12
source_of_truth: manual
source_paths:
  - database/modules/business.sql
  - database/migrations/business/0007_pricing_offers_bundles.sql
  - backend/src/Modules/Business/Catalog/CatalogPricingService.php
  - backend/src/Modules/Business/Services/CatalogPriceListService.php
  - backend/src/Modules/Business/Services/BusinessProductBundleService.php
  - tools/php/tests/unit/business_pricing_offers_bundles_test.php
owners:
  - business
document_type: specification
generated: false
---
# Pricing, offres et compositions Business

## Frontières métier

| Concept | Propriétaire | Rôle |
|---|---|---|
| Produit simple | PIM Business | Identité commerciale et variantes. |
| Bundle | PIM Business | Produit vendable composé, avec prix et disponibilité propres. |
| Kit | PIM Business | Composition logistique prédéfinie, explicitement marquée `kit`. |
| Liste de prix | Pricing Business | Prix contextualisé par site, devise, canal, segment et période. |
| Remise | Pricing Business | Réduction appliquée après la résolution du prix. |
| Upsell / cross-sell | Catalogue Business | Suggestion, jamais ajout automatique au panier. |
| Bon cadeau | PIM Business | Produit `gift_card` associé à une politique de valeur, distinct d’une remise. |
| Panier / commande | Sale | Snapshot immuable du résultat fourni par Business. |

Sale n’interroge pas les tables de pricing. Il demande un snapshot vendable au port catalogue, puis stocke le prix, la devise, la taxe, la remise et la composition reçus.

## Résolution déterministe

Le service `CatalogPricingService` est l’unique résolveur. Son contexte contient le site porté par le produit, la devise, le canal, le segment client facultatif et la date de calcul.

Une liste et son élément doivent être actifs dans leurs deux périodes de validité. Les règles incompatibles avec la devise, le canal ou le segment sont exclues avant le classement.

L’ordre de priorité est le suivant :

1. variante avant produit ;
2. segment exact avant règle générique ;
3. canal exact avant `all` ;
4. priorité de liste croissante ;
5. priorité d’élément croissante.

Si deux règles au même rang et aux mêmes priorités produisent des montants différents, la résolution échoue avec `business.pricing.ambiguous_price_rule`. L’identifiant technique ne sert jamais à masquer un conflit métier.

La règle retenue peut définir un prix fixe, un delta en montant ou un delta en pourcentage. Le prix ne peut pas devenir négatif. `compare_at_amount` porte le prix barré indicatif sans modifier le calcul.

Les remises actives sont ensuite classées ainsi : variante, produit, catégorie, marque ; segment exact ; canal exact ; priorité croissante. Un conflit équivalent échoue avec `business.pricing.ambiguous_discount_rule`. Une remise en montant doit utiliser la devise résolue.

Tous les montants métier sont arrondis à deux décimales par le résolveur. Sale les convertit en unités mineures entières et fige ces valeurs dans son snapshot.

## Exemples reproductibles

Prix catalogue : `100.00 CHF`.

| Contexte | Règle retenue | Résultat |
|---|---|---:|
| ecommerce, sans segment | liste Web, fixe `90.00` | `90.00 CHF` |
| ecommerce, segment `vip` | liste VIP variante, fixe `80.00` | `80.00 CHF` |
| ecommerce, segment `vip`, remise `10 %` | `80.00 - 10 %` | `72.00 CHF` |
| pos | liste POS, fixe `95.00` | `95.00 CHF` |

Le test `tools/php/tests/unit/business_pricing_offers_bundles_test.php` exécute ces scénarios, les périodes expirées et le refus d’un conflit de même priorité.

## Bundles et kits

`composition_type` distingue `bundle` et `kit`. Les quantités sont strictement positives et un composant doit être actif. La validation parcourt tout le graphe : un cycle direct ou indirect, par exemple A → B → C → A, est refusé.

`stock_mode` indique si la disponibilité vient des composants, d’un stock virtuel ou d’aucun stock. `unavailable_strategy` précise le comportement :

- `reject` : composition non vendable ;
- `backorder` : commande différée autorisée ;
- `contact` : affichage sur demande, sans ajout silencieux au panier.

La composition complète est incluse dans le snapshot transmis à Sale. Une modification ultérieure du bundle ne modifie donc pas les lignes déjà figées.

## Bons cadeaux et recommandations

Une politique de bon cadeau ne peut être liée qu’à un produit de type `gift_card`. Elle définit la devise, une valeur fixe ou ouverte, les bornes facultatives et la durée de validité. Elle ne crée aucune remise.

Les relations `upsell` et `cross_sell` utilisent `business_product_relations`. Elles restent des recommandations de catalogue : Sale ne les transforme jamais automatiquement en lignes de panier.
