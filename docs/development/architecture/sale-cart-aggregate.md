---
title: Agrégat panier Sale commun
description: Contrat, politiques de canal, concurrence et règles de recalcul du panier web, POS et administratif.
audience:
  - developer
  - administrator
status: stable
last_verified: 2026-07-12
source_of_truth: code
source_paths:
  - database/modules/sale.sql
  - database/migrations/sale/0010_cart_aggregate_contract.sql
  - backend/src/Modules/Sale/Services/SaleCartService.php
  - backend/src/Modules/Sale/Repositories/SaleCartRepository.php
owners:
  - sale
document_type: architecture
generated: false
---

# Agrégat panier Sale commun

L'audit du modèle historique a confirmé que `sale_carts` portait déjà le site, le canal, la devise, le statut, la version, l'expiration et le lien unique vers la commande. La migration `0010_cart_aggregate_contract.sql` complète ces données sans supprimer ni reconstruire les paniers existants : elle classe les anciens paniers à token en `web`, les paniers du canal caisse en `pos`, les autres en `admin`, puis recopie le hash public historique.

Le même agrégat sert les trois canaux. Son contexte immuable associe un seul site, un seul canal et une seule devise. `cart_kind` sélectionne la politique : un panier web peut recevoir un token public opaque et expire ; un panier POS exige un opérateur authentifié, peut référencer une session de caisse et interdit le token public ; un panier administratif exige l'API administrateur. Le token brut n'est jamais persisté et la lecture publique filtre simultanément le canal, le type `web` et son hash.

Chaque ligne référence le `sellable_id` exact et conserve les références produit/variante de diagnostic, les options et personnalisations validées, le snapshot de prix, les remises et taxes calculées, la classe de fulfillment, la disponibilité et la version de calcul. Le navigateur ne fournit jamais les prix ni les totaux faisant autorité.

## Mutations et concurrence

`SaleCartService` centralise création, ajout, quantité, suppression, recalcul et fusion. Le checkout centralise validation et conversion ; le service de checkout invité centralise abandon et expiration. Toute mutation significative revendique atomiquement la version observée avec `expected_version`. Une requête obsolète reçoit `REVISION_CONFLICT` (HTTP 409). Le recalcul charge de nouveau chaque sellable depuis le catalogue puis recalcule lignes et total côté serveur.

Si le prix courant diffère du prix de travail, le panier applique le nouveau prix avant la poursuite du checkout, conserve `previous_unit_price_minor` et renseigne `price_changed_at`. L'interface signale alors que le prix a été actualisé. La commande reste un snapshot définitif et l'unicité de `source_cart_id` empêche une seconde commande depuis le même panier.

## Fusion invité vers compte

La fusion est permise uniquement entre deux paniers web actifs du même site, canal et devise. Le panier source doit être anonyme et le panier cible doit être vide d'identité ou déjà rattaché au même client. Les lignes sont réajoutées par la mutation normale, avec leurs options et personnalisations, puis les réservations source sont libérées et le panier invité devient `abandoned`.

Les fusions web/POS/admin, inter-sites, inter-canaux, inter-devises, entre identités différentes, depuis un panier non actif ou vers le même panier sont interdites.

## Interfaces

Le Storefront fournit un compteur, un tiroir clavier-compatible, une page `/cart`, les changements de quantité, la suppression, les erreurs annoncées et une mise en page mobile. La caisse conserve recherche, scan, ajout rapide, quantité, client facultatif et total, tout en envoyant la version du panier. Les contrats OpenAPI publics et les types SDK sont générés depuis les contrats headless versionnés.
