---
title: Routes canoniques de convergence du back-office
audience:
  - administrator
  - developer
status: current
last_verified: 2026-07-17
source_of_truth: code
source_paths:
  - frontend/admin-vue/src/router/index.ts
  - frontend/admin-vue/src/router/navigation.ts
  - backend/src/Modules/Business/BusinessModuleProvider.php
  - backend/src/Modules/Sale/SaleModuleProvider.php
owners:
  - core
  - business
  - sale
document_type: reference
generated: false
---

# Routes canoniques de convergence du back-office

Cette carte décrit les destinations visibles. Une URL ne constitue jamais une autorisation : les API appliquent la permission du site et, pour les diagnostics, la permission avancée dédiée.

| Tâche | Route canonique | Retour ou lien principal | Permission minimale |
|---|---|---|---|
| Cockpit transversal | `/` | espace d’origine conservé | selon les cartes autorisées |
| Ventes à traiter | `/sale` | dossier Commande | `sale.read` ou permission métier Ventes |
| Commandes | `/sale/orders` | Relation ou file Ventes | `sale.orders.read` |
| Paiements | `/sale/payments` | dossier Commande, factures incluses | `sale.payments.read` |
| Point de vente | `/sale/pos` | dossier Commande créé | `sale.pos.use` |
| Réglages Ventes | `/sale/settings` | Ventes | `sale.settings.manage` |
| Opérations à traiter | `/business` | objet Relation, Produit ou Offre | permission Business correspondante |
| Relations | `/business/relations` | filtres et relation sélectionnée dans l’URL | `business.crm.read` ou permission relationnelle |
| Produits | `/business/products-stock` | produit et variante sélectionnés | `business.catalog.read` |
| Marketing | `/business/offers-marketing` | offre sélectionnée | `business.catalog.read` ou permission marketing |
| Audiences | `/business/offers-marketing/audiences` | Marketing | `business.segment.read` |
| Réglages Opérations | `/business/settings` | objet affecté | permission de réglage correspondante |
| Sites E-Commerce | `/sale/settings?section=ecommerce` | page système Studio ou `/shop` actif | `sale.settings.manage` |
| Page système Boutique | `/contents/pages/system-shop?site_id={id}&language_code={code}` | liste Pages ou réglages E-Commerce | `content.read` ; mutation selon action éditoriale |

## Outils avancés

| Diagnostic | Route | Permissions cumulatives |
|---|---|---|
| Ledger et réconciliation stock | `/sale/advanced/stock` | `sale.advanced_tools.manage` + `sale.stock.read` |
| Réservations | `/sale/advanced/reservations` | `sale.advanced_tools.manage` + `sale.stock.read` |
| Logistique interne | `/sale/advanced/logistics` | `sale.advanced_tools.manage` + permission logistique |
| Rapprochement de profils depuis une Relation | `/business/relations/advanced/profiles` | `business.advanced_tools.manage` + permissions de rapprochement Sale |
| Revue de rattachement de formulaires | `/business/relations/advanced/form-links` | `business.advanced_tools.manage` + `business.form_links.review` |
| Reconstruction de projection Storefront | action depuis Produit/Réglages | `business.advanced_tools.manage` + `business.catalog.write` |

## Redirections maintenues

| Ancienne URL | Destination |
|---|---|
| `/sale/stock` | `/sale/advanced/stock` |
| `/sale/reservations` | `/sale/advanced/reservations` |
| `/sale/operations` | `/sale/advanced/logistics` |
| `/sale/identities` | `/business/relations/advanced/profiles` |
| `/business/catalog` | `/business/products-stock` |
| `/business/segments` | `/business/offers-marketing/audiences` |
| `/commerce` | `/sale/settings?section=ecommerce` |
| `/modules/commerce` | `/sale/settings?section=ecommerce` |

Les redirections ne maintiennent aucun second écran ou modèle métier concurrent.
