---
title: POS Vente
audience:
  - administrator
  - superadministrator
status: draft
last_verified: 2026-07-12
source_of_truth: code
source_paths:
  - frontend/admin-vue/src/views/modules/SalePosView.vue
  - backend/src/Application/Api/Admin/SaleAdminApiController.php
  - backend/src/Modules/Sale/Services/SaleCheckoutService.php
  - backend/src/Modules/Sale/Services/SalePaymentService.php
owners:
  - sale
document_type: guide
generated: false
---
# POS Vente

Le POS back-office est une caisse simple pour vendre des variantes et bundles vendables depuis le catalogue Opérations. Le POS reel pourra ensuite etre porte par une webapp separee ; la v1 conserve l'onglet POS dans Vente pour tester le flux operationnel.

## Ouvrir une session

1. Ouvrez **Modules > Vente > POS**.
2. Ouvrez une session de caisse.
3. Renseignez l'avance de caisse si necessaire.
4. Utilisez la session ouverte pour les encaissements cash.

Une session ouverte est obligatoire pour un paiement cash. Les autres moyens de paiement peuvent etre enregistres sans mouvement de caisse direct.

## Ajouter des articles

La grille POS affiche les variantes vendables et les bundles disponibles pour le canal POS. Une carte utilise le snapshot vendable courant :

- nom produit ;
- nom variante ou options de variante ;
- stock disponible si suivi ;
- prix final ;
- prix barre et remise si une offre s'applique.

Cliquer sur une carte ajoute l'article au panier POS. Les prix restent recalcules cote serveur depuis le snapshot, pas depuis le navigateur.

## Gerer le panier

Dans le panier :

- modifiez la quantite ;
- supprimez une ligne avec l'action de suppression ;
- ajoutez un montant supplementaire ou une reduction globale avec un montant positif ou negatif ;
- verifiez le total, les offres, le verse et le solde a payer.

Une avance reduit le solde a payer. Si le montant verse depasse le total, le solde devient negatif et doit etre traite comme rendu ou trop-percu selon le contexte metier.

## Encaisser

1. Choisissez le moyen de paiement.
2. Indiquez le montant verse.
3. Validez l'encaissement.

Le checkout POS cree une commande prefixee `POS-`, consomme les reservations de stock et enregistre le paiement. Le workflow accepte une cle d'idempotence afin d'eviter une double vente en cas de rejeu.

## Recu et envoi

Le reçu POS est persisté à partir de la commande et de ses lignes snapshot. Il existe en français et en anglais, possède un numéro stable et inclut date, opérateur, lignes, taxes, paiements et remboursements. Il exclut les payloads techniques des providers. L'impression doit utiliser le flux reçu, pas l'impression brute de toute la page. L'envoi par courriel utilise le reçu courant et un destinataire fourni au moment de l'action.

## Cloturer la session

1. Controlez le cash attendu.
2. Saisissez le montant compte.
3. Fermez la session.

La cloture ecrit un evenement `sale.pos.session.closed` et conserve l'ecart de caisse.

## Limites v1

- pas de mode hors-ligne ;
- pas de scanner materiel dedie ;
- pas de terminal de paiement connecte ;
- pas de rendu monnaie automatise avance ;
- pas de multi-caisse complexe.
