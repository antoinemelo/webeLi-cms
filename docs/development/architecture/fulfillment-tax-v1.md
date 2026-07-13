---
title: Fulfillment et fiscalité v1
audience:
  - developer
  - administrator
  - api-integrator
status: draft
last_verified: 2026-07-12
source_of_truth: code
source_paths:
  - backend/src/Modules/Sale/Services/SaleFulfillmentService.php
  - backend/src/Modules/Sale/Pricing/SalePricingService.php
  - database/migrations/sale/0007_fulfillment_tax_v1.sql
  - tools/php/tests/unit/sale_fulfillment_tax_test.php
owners:
  - sale
document_type: specification
generated: false
---
# Fulfillment et fiscalité v1

## Fulfillment

Les zones et méthodes sont propres à un site. Une zone contient une liste simple de pays ISO et, facultativement, des préfixes postaux. Une méthode active définit un type `shipping`, `pickup` ou `none`, un tarif fixe en unités mineures, un seuil de gratuité, la nécessité d’une adresse, sa période d’activité et son ordre d’affichage.

Le navigateur transmet uniquement le code choisi. `SaleFulfillmentService` relit la configuration active, vérifie la zone, la composition du panier et calcule le montant. Les produits physiques, bundles et types inconnus refusent `none`. Les services, produits numériques et bons cadeaux utilisent `none` sans adresse de livraison ; une méthode de livraison ne leur est permise que par `allow_non_physical` explicite. L’adresse de facturation reste requise.

La méthode calculée complète est stockée dans `shipping_method_snapshot_json` avec son tarif et la règle appliquée. La commande copie ce JSON et `shipping_total_minor`; le trigger d’immutabilité interdit ensuite leur réécriture. Une modification future du tarif ne change donc jamais une commande historique.

Limites v1 : tarif fixe uniquement, une zone par méthode, correspondance pays/préfixe, frais de fulfillment non taxés, aucune distance, poids, dimensions, transporteur temps réel ou promesse de délai.

## Fiscalité

Business possède les classes fiscales (`code`, taux décimal, pays). Le snapshot vendable transmet le code, le pays, le taux en basis points et le mode TTC/HT à Vente. Vente calcule exclusivement en entiers :

- TTC : `taxe = arrondi(gross × taux / (10000 + taux))` ;
- HT : `taxe = arrondi(net × taux / 10000)` ;
- arrondi : moitié supérieure (`integer_half_up`) à chaque ligne, jamais sur un flottant de total de commande.

Chaque ligne conserve le taux, le code de classe, le mode TTC/HT, le montant taxable et la taxe dans son snapshot. `sale_order_tax_lines` matérialise ces valeurs pour le reçu et le rapport `/admin/api/sale/reports/taxes`. Le rapport fulfillment agrège séparément les méthodes et frais historiques.

Limites v1 : un taux par classe, pays informatif de configuration, aucune règle internationale, exonération conditionnelle, numéro TVA, nexus, OSS/IOSS, ventilation cantonale ou service fiscal externe.
