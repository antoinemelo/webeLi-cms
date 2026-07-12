---
title: Checkout public invité Sale
audience:
  - developer
  - api-integrator
status: draft
last_verified: 2026-07-12
source_of_truth: manual
source_paths:
  - database/migrations/sale/0005_guest_public_checkout.sql
  - backend/src/Modules/Sale/Services/SaleGuestCheckoutService.php
  - backend/src/Application/PublicApi/PublicSaleApiHandler.php
  - backend/src/Application/Frontend/PublicSaleCheckoutController.php
  - frontend/admin-vue/tests/e2e/public-guest-checkout.spec.ts
owners:
  - sale
document_type: specification
generated: false
---
# Checkout public invité Sale

Le panier reste l’unique agrégat modifiable. `checkout_step` décrit le parcours `cart → identity → addresses → delivery → review → validated`; la conversion terminale utilise ensuite la machine à états existante pour produire une commande `placed`.

## Sécurité et autorité serveur

Le token public contient 256 bits aléatoires et seul son SHA-256 est stocké. Chaque lecture ou mutation recherche simultanément canal, hash, statut actif et expiration. Une ligne appartenant à un autre panier est donc inaccessible, même avec un identifiant valide.

À l’étape `review` et juste avant le placement, chaque variante est relue via `SaleCatalogSnapshotService`. Les prix et taxes sont recalculés, les réservations de stock sont vérifiées et les valeurs du navigateur (`grand_total_minor`, remises ou frais) sont ignorées. Une variante non vendable ou une réservation insuffisante bloque le placement.

Le rate-limit public applique une enveloppe spécifique au checkout. Les réponses d’erreur restent structurées en codes `sale.checkout.*`, sans trace ni payload provider.

## Données et snapshots

L’identité invitée exige e-mail, prénom et nom ; le téléphone est facultatif. La facturation exige ligne, code postal, ville et pays ISO à deux lettres. L’adresse de livraison dépend de la méthode de fulfillment configurée : livraison physique, retrait local ou aucun fulfillment pour un service/produit numérique. Le navigateur ne fixe jamais le tarif. Les moyens de paiement v1 restent `bank_transfer`/`manual`, sans secret en ligne.

Le consentement CGV est obligatoire et horodaté. Le consentement marketing est nullable et distinct : `false` est conservé comme refus explicite. Au placement, identité, adresses, livraison, méthode de paiement et consentements sont copiés dans la commande puis protégés par trigger d’immuabilité.

## Idempotence et récupération

`Idempotency-Key` est obligatoire pour le placement. Le même panier et la même clé rejouent la commande ; une clé différente rencontre le panier déjà converti. L’unicité SQL de `source_cart_id` ferme également la course entre deux requêtes concurrentes.

Un panier actif est récupérable jusqu’à `expires_at`. `DELETE /api/v1/sale/channels/{code}/cart/{token}` l’abandonne et libère ses réservations.

## Surface publique

- `PATCH /api/v1/sale/channels/{code}/cart/{token}/checkout` enregistre une étape et recalcule ;
- `POST /api/v1/sale/channels/{code}/checkout` valide et place ;
- `DELETE /api/v1/sale/channels/{code}/cart/{token}` abandonne ;
- `/checkout?channel=…&cart_token=…` fournit le parcours SSR minimal responsive.
