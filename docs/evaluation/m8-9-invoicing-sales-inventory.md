---
title: M8.9 — Facturation, ventes et inventaire administrateur
audience:
  - evaluator
  - administrator
  - developer
status: draft
last_verified: 2026-07-17
source_of_truth: code
source_paths:
  - backend/src/Modules/Sale/Services/SaleOrderDocumentService.php
  - backend/src/Modules/Sale/Services/SaleImportExportReportService.php
  - frontend/admin-vue/src/views/modules/SaleView.vue
  - frontend/admin-vue/src/views/modules/business/OperationsInventoryView.vue
  - database/modules/sale.sql
owners:
  - sale
  - business
document_type: evaluation
generated: false
---

# M8.9 — Facturation, ventes et inventaire administrateur

## Périmètre démontré

Le module Ventes émet des confirmations, factures et notes de crédit à partir des instantanés de commande Sale. Une facture finale n'est pas créée depuis un panier et, avec la politique par défaut, exige une commande payée. Une commande différée non payée ne peut donc pas produire de facture finale.

La politique est configurée par site sous **Ventes > Réglages > Politiques d’exécution** : déclencheur (`paid` ou validation comptable explicite), séries, vendeur, mention configurée et traitement des bons cadeaux. Le logiciel ne déduit aucune règle juridique universelle de ces choix. Tant que le vendeur n'est pas renseigné, l'interface signale que la conformité fiscale n'est pas validée.

Les numéros ont la forme `SERIE-SITE-ANNEE-SEQUENCE`. L'allocation est transactionnelle et un numéro consommé n'est jamais réutilisé. Le document contient les instantanés vendeur/client/adresses/lignes/taxes/remises/livraison/totaux/paiements/remboursements ainsi qu'une empreinte SHA-256. Les triggers interdisent suppression et réécriture ; une correction financière utilise une note de crédit.

L'envoi et le renvoi d'une facture ou note de crédit passent par l'outbox transactionnelle. Le journal conserve un hash du destinataire, l'opérateur, le document, le site et le statut. Une mise en file n'est pas présentée comme une livraison réussie : le succès dépend du traitement de l'outbox.

Le tableau de bord Ventes calcule ses indicateurs depuis les commandes et lignes figées, jamais depuis les prix PIM courants. Il expose avec leur définition : commandes, brut lignes, remises, taxes, livraison, total commandé, ventes nettes après remboursements, payé, remboursé, panier moyen, unités et ventes de bons cadeaux. Les filtres serveur couvrent période, canal/source, statuts commande/paiement/fulfillment, devise, moyen de paiement, produit/variante/type/catégorie/groupe et présence d'un remboursement. Le drill-down est plafonné à 200 commandes et le CSV réutilise les mêmes filtres.

Sous **Opérations > Inventaire**, les quantités restent des projections du ledger Sale : Business ne possède pas de second stock modifiable. La recherche couvre produit, variante, SKU, code-barres et emplacement ; les filtres distinguent stock faible, rupture et incohérence. Réception, retour, perte, correction et comptage sont prévisualisés avant/après, motivés, permissionnés et inscrits comme mouvements compensatoires. Les réservations, transferts, inventaires complets, réconciliation et reconstruction restent dans les outils avancés.

## Permissions

- `sale.sales.read` : indicateurs et drill-down ;
- `sale.exports.manage` : exports audités et limités au site ;
- `sale.documents.issue` : émission ;
- `sale.documents.resend` : envoi et renvoi via outbox ;
- `business.catalog.stock.write` : variations ordinaires ;
- `sale.inventory.count`, `sale.inventory.approve`, `sale.inventory.repair` : opérations avancées distinctes.

Les réponses CSV contenant potentiellement des données client utilisent `Cache-Control: private, no-store` et produisent une ligne dans `sale_admin_export_audit`.

## Limites explicites

- Le rendu officiel disponible est HTML/impression et texte. Aucun PDF fiscal signé ou archivé n'est revendiqué ; `pdf_media_id` prépare une chaîne future mais sa présence vide n'est pas une preuve de PDF.
- La politique fiscale et le traitement des bons cadeaux doivent être validés pour chaque juridiction et organisation.
- La mise en file d'un document est démontrée ; sa remise effective dépend du worker/outbox et du provider configuré.
- Les valorisations d'inventaire utilisent les prix d'achat/vente courants pour piloter le stock. Elles ne sont pas un grand livre comptable historique.
- Les agrégats multi-devis restent séparables par filtre ; l'interface ne prétend pas convertir ni additionner économiquement des devises différentes.

## Preuves automatisées

- `php tools/php/tests/unit/sale_invoicing_sales_inventory_47_test.php` : 19 assertions ;
- `php tools/php/tests/unit/sale_order_dossier_workflow_test.php` : 25 assertions, dont facture différée ;
- `npm run build` dans `frontend/admin-vue` : contrôle TypeScript et build Vite.
- `php tools/php/tests/run.php` : suite PHP fonctionnelle réussie ; quatre intégrations HTTP explicitement ignorées faute de bind TCP dans le bac à sable ;
- `python3 tools/cms.py validate --category configuration --category database --category content --category permissions --category api --category operations --category security --category documentation --category shared` : tous les validateurs demandés réussis ;
- `python3 tools/cms.py e2e --use-built-assets --spec tests/e2e/sale-invoicing-sales-inventory-47.spec.ts` : 1 scénario Chromium réussi sur instance fraîche ;
- migration `0014_invoicing_sales_inventory.sql` appliquée avec succès à une copie de la base Sale existante.

La suite Python orchestrée complète et l’ensemble des scénarios E2E historiques n’ont pas été rejoués dans ce contrôle ciblé ; ils ne sont donc pas déclarés réussis ici.
