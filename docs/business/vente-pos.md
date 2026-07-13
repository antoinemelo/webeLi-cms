---
title: POS Vente
audience:
  - administrator
  - superadministrator
status: draft
last_verified: 2026-07-13
source_of_truth: code
source_paths:
  - frontend/admin-vue/src/views/modules/SalePosView.vue
  - backend/src/Application/Api/Admin/SaleAdminApiController.php
  - backend/src/Modules/Sale/Services/SaleCheckoutService.php
  - backend/src/Modules/Sale/Services/SalePosService.php
  - backend/src/Modules/Sale/Repositories/SalePosRepository.php
  - backend/src/Modules/Sale/Services/SalePaymentService.php
owners:
  - sale
document_type: guide
generated: false
---
# POS Vente

Le POS back-office est un canal authentifié du moteur Vente. Il réutilise strictement les paniers, commandes, prix, taxes, paiements, stocks, reçus et retours communs : il n'existe aucune table de commande ou panier parallèle propre au POS.

Chaque opération est rattachée à un `POSContext` explicite : site, canal POS, emplacement de stock, caisse, appareil éventuel, session, opérateur IAM, devise et langue. La commande partagée conserve ces références pour l'audit.

## Ouvrir une session

1. Ouvrez **Modules > Vente > POS**.
2. Choisissez la caisse, liée à un canal POS et un emplacement de stock actif.
3. Renseignez le fond de caisse et ouvrez la session.
4. Utilisez uniquement les moyens de paiement autorisés pour cette caisse.

Une session ouverte par l'opérateur courant est obligatoire pour toute vente POS. Les moyens v1 sont espèces, carte manuelle et terminal externe ; seuls les paiements espèces modifient le cash attendu. Le mode hors-ligne est explicitement hors périmètre v1.

Les permissions sont séparées : `sale.pos.sessions.open`, `sale.pos.sessions.close`, `sale.pos.discounts.manage`, `sale.pos.refunds.manage`, `sale.pos.cash.correct` et `sale.pos.receipts.reprint`.

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

Le checkout POS crée une commande commune `sale_orders` préfixée `POS-`, consomme les réservations dans l'emplacement figé par la session et enregistre le paiement via les providers communs. La commande trace le canal, l'emplacement, la caisse, l'appareil, la session et l'opérateur. Le workflow accepte une clé d'idempotence afin d'éviter une double vente en cas de rejeu.

## Recu et envoi

Le reçu POS est persisté à partir de la commande et de ses lignes snapshot. Il existe en français et en anglais, possède un numéro stable et inclut date, opérateur, lignes, taxes, paiements et remboursements. Il exclut les payloads techniques des providers. L'impression doit utiliser le flux reçu, pas l'impression brute de toute la page. Toute réimpression exige un motif et écrit un audit avec reçu, session et opérateur. L'envoi par courriel utilise le reçu courant et un destinataire fourni au moment de l'action.

## Mouvements et retours

Les entrées, sorties et corrections de cash exigent un montant et un motif. Chaque mouvement est append-only et recalcule le cash attendu ; une modification ou suppression directe est interdite. Un retour POS utilise `sale_returns` et `sale_return_lines` et reste lié à la commande d'origine.

## Cloturer la session

1. Controlez le cash attendu.
2. Saisissez le montant compte.
3. Fermez la session.

La clôture compare le cash attendu au cash compté. Un écart non nul exige une justification avant fermeture. La session conserve attendu, compté, écart, justification, opérateurs et horodatages, puis écrit `sale.pos.session.closed`.

Le rapport et l'export CSV des sessions incluent le contexte caisse, les mouvements, le nombre et le total des commandes ainsi que la réconciliation de clôture.

## Limites v1

- pas de mode hors-ligne ;
- saisie scanner traitée comme recherche code-barres, sans pilote matériel dédié ;
- pas de terminal de paiement connecte ;
- pas de rendu monnaie automatise avance ;
- pas de mode hors-ligne ni de synchronisation différée.
