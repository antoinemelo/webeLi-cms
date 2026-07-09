---
title: Plan d'architecture du module Vente
audience:
  - developer
  - administrator
status: draft
last_verified: 2026-07-08
source_of_truth: analysis
source_paths:
  - /home/amelo/Documents/DEV/Ecol_WebeLi/web/_VENTE/03_STRATEGIE_ARCHITECTURE_MODULE_VENTE.md
  - docs/development/architecture/sale-module-guardrails.md
  - docs/development/architecture/sale-module-audit-existing.md
  - docs/development/architecture/business-pim-lite.md
  - docs/development/architecture/business-sellable-snapshot.md
  - docs/development/architecture/module-admin-governance.md
  - docs/development/extending/modules.md
  - backend/src/Module/ModuleProvider.php
  - backend/src/Modules/Business/BusinessModuleProvider.php
  - backend/src/Modules/Business/Services/BusinessCatalogSellableReadService.php
  - backend/src/Modules/Sale/Services/SaleCatalogSnapshotService.php
owners:
  - sale
  - business
document_type: architecture
generated: false
---
# Plan d'architecture du module Vente

Ce document pose l'architecture cible du module **Vente** avant creation du schema SQL. Vente est un module transactionnel separe du module `business` / **Opérations**.

```text
Opérations = CRM + Catalogue + donnees de reference
Vente = paniers + commandes + paiements + POS + stock transactionnel + evenements
```

## Objectifs

- Ajouter un domaine transactionnel robuste sans transformer DEC / webeLi en suite e-commerce lourde.
- Consommer le catalogue Opérations sans le dupliquer.
- Figer les snapshots de prix, taxe, remise, produit et variante dans les commandes.
- Permettre un POS simple qui fonctionne sans client CRM.
- Garder une API admin privee sous `/admin/api/sale/*`.
- N'ouvrir une API publique e-commerce que plus tard, par canal explicitement actif.
- Conserver le `rebuild from scratch` et les validateurs existants.

## Hors perimetre v1

- marketplace ;
- abonnements ;
- multi-entrepots avance ;
- shipping avance ;
- paiement online complet ;
- moteur fiscal international ;
- coupons et promotions complexes ;
- pipeline commercial ;
- facturation comptable complete ;
- synchronisation externe obligatoire.

## Structure cible

```text
backend/src/Modules/Sale/
  SaleModuleProvider.php
  module.json
  Services/
  Repositories/
  Workflows/
  Pricing/
  Payments/
  Pos/
  Stock/
  Receipts/
  Returns/
  Events/
  Contracts/
```

Routes et surfaces :

```text
/admin/api/sale/...
/admin/app/sale
/api/v1/sale/... uniquement si un canal e-commerce public est actif
```

Base :

```text
storage/database/sale.sqlite
database/modules/sale.sql
database/migrations/sale/
```

## Dependances

Vente depend fonctionnellement d'Opérations, mais ne doit pas creer de contrainte SQL cross-database obligatoire.

Vente consomme depuis Opérations :

- `variant_id` ;
- `product_id` ;
- SKU et barcode ;
- nom produit et nom variante ;
- options de variante ;
- prix achat et prix vente, avec permission adequate ;
- taxes ;
- remises catalogue simples ;
- media d'affichage ;
- relation CRM, contact ou entreprise optionnels.

Vente possede :

- canaux de vente ;
- paniers et lignes de panier ;
- commandes et lignes de commande ;
- paiements et transactions ;
- remboursements ;
- caisses et sessions POS ;
- reservations ;
- mouvements transactionnels ;
- recus ;
- retours ;
- evenements et idempotence.

## Domaines internes

### Channel

Canal commercial lie a un site, avec type `admin`, `pos` ou `ecommerce`, devise, regles de taxe, activation et statut public.

### Cart

Panier modifiable. Il reference un canal, un site et eventuellement un contact/entreprise CRM. Ses lignes peuvent etre recalculees tant qu'il n'est pas transforme en commande.

### Order

Commande transactionnelle. Lors du passage en `placed`, les lignes conservent les snapshots de libelle, SKU, prix, taxe, remise et devise. Une commande placee ne doit pas etre recalcuree depuis le catalogue.

### Payment

Paiements separes des commandes. Un paiement reference une commande et produit des transactions. Les providers v1 restent simples : cash, manual, terminal externe.

### POS

Point de vente, caisse et session. Le POS doit fonctionner avec un client anonyme et utiliser le catalogue POS Opérations comme source de produits vendables.

### Inventory / Stock Reservation

Reservations et mouvements transactionnels appartenant a Vente. Le stock catalogue Opérations peut rester un resume courant, mis a jour via un service unique.

### Receipt

Recu HTML imprimable lie a une commande ou une session POS. Le recu lit les snapshots Vente, pas le catalogue courant.

### Return / Refund

Retour de marchandises et remboursement financier sont separes. Un remboursement reference une transaction, une commande ou une ligne selon le cas.

### Event / Outbox

Evenements internes et outbox Vente pour tracer checkout, paiement, retour, remboursement, annulation et mouvements.

### Reporting

Rapports simples : ventes par periode, canal, caisse, statut, moyen de paiement. Les rapports ne doivent pas devenir la source de verite transactionnelle.

## Schema global cible

Le schema exact sera defini dans les prompts SQL, mais les groupes de tables attendus sont :

- `sale_channels`, `sale_channel_settings` ;
- `sale_carts`, `sale_cart_lines` ;
- `sale_orders`, `sale_order_lines` ;
- `sale_payments`, `sale_payment_transactions` ;
- `sale_pos_registers`, `sale_pos_sessions` ;
- `sale_stock_reservations`, `sale_stock_movements` ;
- `sale_receipts` ;
- `sale_returns`, `sale_return_lines`, `sale_refunds` ;
- `sale_events`, `sale_outbox`, `sale_idempotency_keys`.

Tous les montants doivent etre stockes en unites mineures (`*_minor`) avec devise explicite.

## Workflows critiques

### Ajout au panier

1. Resoudre le canal.
2. Lire un snapshot vendable depuis Opérations.
3. Verifier statut, canal, prix et stock disponible.
4. Ajouter ou mettre a jour la ligne panier.
5. Recalculer les totaux du panier.

### Passage de commande

1. Verifier idempotence.
2. Verifier panier et canal.
3. Recharger les snapshots vendables.
4. Verifier prix et disponibilite selon la politique v1.
5. Creer commande `placed`.
6. Copier les snapshots dans `sale_order_lines`.
7. Creer reservations ou mouvements selon le flux.
8. Emettre evenement `sale.order.placed`.

### Paiement POS

1. Ouvrir ou verifier une session POS.
2. Creer une commande depuis panier POS.
3. Enregistrer un paiement cash/manual/terminal externe.
4. Marquer la commande selon le statut de paiement.
5. Generer ou rendre disponible le recu.

## Invariants metier

- Une commande validee est immuable sur ses prix, libelles, taxes et remises.
- Un paiement ne modifie pas directement les lignes de commande.
- Un remboursement ne supprime jamais le paiement original.
- Un retour peut exister sans remboursement immediat.
- Un remboursement peut etre partiel.
- Le POS peut vendre sans client CRM.
- Un canal e-commerce public doit etre explicitement actif avant toute route publique Vente.
- Les prix d'achat sont admin-only.
- Les ventes utilisent des snapshots, pas des joins dynamiques permanents vers le catalogue.
- Les operations critiques doivent etre idempotentes.

## Permissions cible

Noms indicatifs a confirmer lors du provider :

| Permission | Usage |
|---|---|
| `sale.read` | Lire commandes, paniers et rapports de base. |
| `sale.manage` | Modifier configuration, canaux et commandes administratives. |
| `sale.cart.manage` | Creer et modifier paniers. |
| `sale.order.manage` | Placer, annuler ou modifier les statuts de commande selon workflow. |
| `sale.payment.manage` | Enregistrer paiements et transactions. |
| `sale.refund.manage` | Creer remboursements. |
| `sale.pos.use` | Utiliser une caisse POS. |
| `sale.pos.manage` | Administrer caisses et sessions. |
| `sale.stock.manage` | Gerer reservations et mouvements transactionnels. |
| `sale.reporting.read` | Lire rapports de vente. |

Ces permissions ne doivent pas remplacer ni elargir `business.catalog.*`.

## Phases

### Phase 1

Socle module : manifeste, provider, base, canaux, paniers, commandes draft/placed, snapshots, evenements et idempotence.

### Phase 2

POS simple : caisses, sessions, panier caisse, paiement cash/manual, recu imprimable.

### Phase 3

Paiements : transactions separees, annulations, remboursements simples, statuts et erreurs explicites.

### Phase 4

Stock transactionnel : reservations, mouvements, retours et synchronisation controlee vers le stock catalogue.

### Phase 5

E-commerce public : endpoints publics activables par canal, jamais ouverts par defaut.

## Risques

- Couplage direct aux tables Opérations au lieu d'un service de snapshot.
- Duplication du catalogue dans `sale.sqlite`.
- Utilisation de `REAL` pour les montants.
- Recalcul d'une commande historique depuis le catalogue courant.
- Confusion entre paiement, remboursement, retour et annulation.
- POS inutilisable sans client CRM.
- API publique ouverte avant gouvernance des canaux.
- Permissions `business.*` modifiees pour faire passer Vente.

## Recommandation avant SQL

Avant d'ecrire `database/modules/sale.sql`, stabiliser le contrat de snapshot vendable entre Opérations et Vente :

- identifiants produit/variante ;
- libelles ;
- SKU/barcode ;
- options ;
- prix regulier et final en unites mineures ;
- devise ;
- taxe ;
- remise appliquee ;
- disponibilite canal ;
- stock disponible indicatif ;
- champs admin-only exclus par defaut.

## Reprise de Vente au point 16

Le socle PIM-lite stabilise la reprise de Vente autour de deux invariants :

- Business reste proprietaire du catalogue et de la qualite produit ;
- Vente consomme uniquement des snapshots vendables et fige ses propres lignes transactionnelles.

Pour reprendre le point 16, utiliser `SaleCatalogSnapshotService::snapshotForVariant()` comme entree unique cote Vente. Ce service appelle `BusinessCatalogSellableReadService`, refuse une variante non vendable si `requireSellable=true`, puis stocke le dernier snapshot dans `sale_catalog_variant_refs`.

Le workflow Vente ne doit pas :

- joindre directement `business_products`, `business_product_variants` ou les tables PIM ;
- recalculer une commande placee depuis Business ;
- exposer prix d'achat, marges ou champs internes dans une surface publique ;
- copier durablement tout le catalogue dans `sale.sqlite`.

Les documents a relire avant de continuer sont :

- [Architecture PIM-lite Opérations](business-pim-lite.md) ;
- [Snapshot vendable Business vers Vente](business-sellable-snapshot.md) ;
- [Tests PIM-lite Opérations](../testing-validation/business-pim-lite.md).
