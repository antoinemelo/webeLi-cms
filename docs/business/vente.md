---
title: Vente
audience:
  - administrator
  - superadministrator
status: draft
last_verified: 2026-07-10
source_of_truth: code
source_paths:
  - frontend/admin-vue/src/views/modules/SaleView.vue
  - frontend/admin-vue/src/views/modules/SalePosView.vue
  - backend/src/Application/Api/Admin/SaleAdminApiController.php
  - backend/src/Modules/Sale/SaleModuleProvider.php
  - database/modules/sale.sql
owners:
  - sale
  - business
document_type: guide
generated: false
---
# Vente

Le module Vente regroupe les commandes, paniers, paiements, POS, recus, remboursements, stock transactionnel, imports, exports et rapports. Il utilise le catalogue Opérations comme source de produits vendables, puis copie les informations utiles dans des snapshots afin que l'historique d'une commande reste stable.

## Acces

Ouvrez **Modules > Vente**.

Les permissions principales sont :

- `sale.read` pour consulter le tableau de bord ;
- `sale.orders.read` et `sale.orders.manage` pour lire ou gerer commandes et paniers ;
- `sale.pos.use` pour utiliser la caisse ;
- `sale.cash.manage` pour ouvrir et fermer une session de caisse ;
- `sale.payments.read` et `sale.payments.manage` pour consulter ou enregistrer des paiements ;
- `sale.refunds.manage` pour creer un remboursement ;
- `sale.stock.read` et `sale.stock.manage` pour consulter ou ajuster le stock Vente ;
- `sale.reports.read` pour consulter rapports et exports ;
- `sale.settings.manage` pour gerer les canaux et reglages.

## Comprendre les canaux

Un canal definit le contexte de vente :

- `admin` : saisie manuelle back-office ;
- `pos` : vente en caisse ;
- `ecommerce` : API publique optionnelle, inactive tant qu'un canal n'est pas actif et public.

Les canaux portent la devise, le mode de taxe et l'etat public. Le seed de développement installe `admin-manual`, `pos-main` et un canal `web-main` actif/public afin de permettre les tests e-commerce. En production, les routes API publiques des modules restent désactivées tant que `APP_PUBLIC_API_MODULE_ROUTES=1` n'est pas configuré explicitement.

## Tableau de bord

L'onglet tableau de bord donne une vue courte des ventes du jour, commandes recentes, paiements recents et sessions de caisse ouvertes. Les statistiques restent ici : les onglets Commandes et POS sont reserves aux listes et aux actions operationnelles.

## Creer une commande

Une commande peut venir d'un panier admin, du POS ou de l'API e-commerce publique optionnelle.

1. Choisissez un canal de vente.
2. Ajoutez des variantes vendables depuis le catalogue Opérations.
3. Verifiez les quantites et le total.
4. Validez le panier pour creer une commande `placed`.
5. Enregistrez le paiement si necessaire.

Au moment du checkout, Vente copie le SKU, le nom produit, le nom variante, les prix, taxes, remises et devise dans `sale_order_lines`. Une modification ulterieure du catalogue ne modifie pas la commande.

## Encaisser

Les paiements v1 sont locaux : cash, carte manuelle, terminal externe, virement ou provider de test. Ils ne declenchent pas encore de paiement online complet. Un paiement ne peut pas depasser le total restant de la commande.

Pour le POS cash, une session de caisse ouverte est requise. La cloture calcule le cash attendu, le montant compte et l'ecart.

## Recus

Une vente POS retourne un recu lisible par l'interface. Le recu utilise les snapshots Vente et non le catalogue courant. Il peut etre imprime via le flux dedie de l'application et envoye par courriel si un destinataire est fourni.

## Annuler et rembourser

Annuler une commande change le statut de commande et tente de liberer les reservations non consommees. Un remboursement est separe du paiement original : il cree une transaction de type `refund`, met a jour le montant rembourse et conserve la trace du paiement source.

## Rapports, imports et exports

Les rapports v1 couvrent :

- ventes du jour ;
- ventes par canal ;
- ventes par moyen de paiement ;
- sessions POS ;
- stock basique ;
- remboursements.

Les exports CSV couvrent commandes, lignes, paiements, sessions POS, mouvements de stock et retours/remboursements. Les prix d'achat ne sont jamais exportes sans permission et demande explicite.

L'import v1 concerne le stock : preview obligatoire pour verifier le CSV, puis application des ajustements. Chaque ajustement applique ecrit un mouvement dans le stock transactionnel.

## Limites v1

- pas de marketplace ;
- pas de paiement online complet ;
- pas de shipping avance ;
- pas de POS hors-ligne ;
- pas de fiscalite multi-pays avancee ;
- pas de facturation comptable complete ;
- pas de synchronisation CRM/CMS activee par defaut.

## Voir aussi

- [POS Vente](vente-pos.md)
- [Commandes Vente](vente-commandes.md)
- [Architecture Vente](../development/architecture/sale-module.md)
- [API admin Vente](../api/admin-internal/sale.md)
