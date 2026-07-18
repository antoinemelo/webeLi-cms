---
title: Vente
audience:
  - administrator
  - superadministrator
status: draft
last_verified: 2026-07-14
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
- `sale.pos.sessions.open` et `sale.pos.sessions.close` pour ouvrir et fermer une session de caisse ;
- `sale.pos.cash.correct`, `sale.pos.discounts.manage`, `sale.pos.refunds.manage` et `sale.pos.receipts.reprint` pour les actions POS sensibles ;
- `sale.payments.read` et `sale.payments.manage` pour consulter ou enregistrer des paiements ;
- `sale.payments.confirm` pour confirmer un paiement manuel ou rapprocher un virement ;
- `sale.payments.test` pour les scénarios déterministes, disponibles uniquement hors production ;
- `sale.refunds.manage` pour creer un remboursement ;
- `sale.stock.read` et `sale.stock.manage` pour consulter ou ajuster le stock Vente ;
- `sale.inventory.repair` pour appliquer une reconstruction contrôlée après diagnostic et sauvegarde ;
- `sale.customer_accounts.manage` pour revoir, lier, fusionner ou séparer des identités avec audit ;
- `sale.fulfillment.manage` pour préparer, expédier et remettre une commande ;
- `sale.transfers.manage` pour piloter un transfert entre emplacements ;
- `sale.inventory.count` pour saisir un comptage et `sale.inventory.approve` pour valider ses écarts ;
- `sale.reports.read` pour consulter les rapports historiques ;
- `sale.sales.read` pour consulter les indicateurs calculés depuis les instantanés de commandes ;
- `sale.exports.manage` pour produire un export audité et limité au site ;
- `sale.documents.issue` et `sale.documents.resend` pour séparer émission et envoi des documents ;
- `sale.settings.manage` pour gerer les canaux et reglages.

## Comprendre les canaux

Un canal definit le contexte de vente :

- `admin` : saisie manuelle back-office ;
- `pos` : vente en caisse ;
- `ecommerce` : API publique optionnelle, inactive tant qu'un canal n'est pas actif et public.

Les canaux portent la devise, le mode de taxe et l'etat public. Le seed de développement installe `admin-manual`, `pos-main` et un canal `web-main` actif/public afin de permettre les tests e-commerce. En production, les routes API publiques des modules restent désactivées tant que `APP_PUBLIC_API_MODULE_ROUTES=1` n'est pas configuré explicitement.

## À traiter

Le tableau de bord ne présente que les commandes qui réclament une action : paiement à reprendre, disponibilité à confirmer, solde à demander, préparation à démarrer ou commande à clôturer. La priorité et la prochaine action sont dérivées des états réels de commande, paiement et livraison ; elles ne constituent pas une machine d’état supplémentaire.

## Creer une commande

Une commande peut venir d'un panier admin, du POS ou de l'API e-commerce publique optionnelle.

1. Choisissez un canal de vente.
2. Ajoutez des variantes vendables depuis le catalogue Opérations.
3. Verifiez les quantites et le total.
4. Validez le panier pour creer une commande `placed`.
5. Enregistrez le paiement si necessaire.

Au moment du checkout, Vente copie le SKU, le nom produit, le nom variante, les prix, taxes, remises et devise dans `sale_order_lines`. Une modification ulterieure du catalogue ne modifie pas la commande.

## Encaisser

L'onglet **Paiements** regroupe les sessions et leur prochaine action. Le formulaire guidé préremplit le solde, montre l'impact et accepte une référence, un commentaire ou une preuve facultative. Un paiement ne peut pas dépasser le total restant.

Le paiement manuel et le virement restent explicitement en attente tant qu'un opérateur autorisé ne les confirme pas. Un paiement partiel conserve la session ouverte. Le bouton **Virements à rapprocher** affiche la file par ancienneté. Les instructions client permettent de copier, imprimer ou télécharger bénéficiaire, montant, devise et référence.

Le provider déterministe est réservé au développement et signalé par un badge `MODE TEST`. Il n'est ni enregistré ni activable lorsque l'environnement est `production`.

Pour le POS, une session de caisse ouverte par l'opérateur est requise. La clôture calcule le cash attendu, le montant compté et l'écart, avec justification obligatoire si l'écart est non nul.

Pour un produit sur commande, le dossier reste **En attente de disponibilité** et aucune intention de paiement n’est créée par défaut. La disponibilité attendue, la politique de prix, un éventuel acompte et le délai de paiement sont figés dans les conditions acceptées. Lorsque le produit devient disponible, l’action correspondante crée de manière idempotente un lien ou une demande de paiement. Une redirection de navigateur n’est jamais une preuve d’encaissement.

## Documents

Confirmation de commande, facture, note de crédit, bon de livraison et ticket POS sont distincts. Une facture finale n’est possible qu’après paiement démontré ; une note de crédit exige un remboursement ; un bon de livraison exige une expédition ou remise. Chaque document émis est un snapshot versionné qui ne peut être ni modifié ni supprimé. Une correction produit un document correctif ou une nouvelle version, jamais une réécriture silencieuse.

## Annuler et rembourser

Annuler une commande change le statut de commande et tente de liberer les reservations non consommees. Un remboursement est separe du paiement original : il cree une transaction de type `refund`, met a jour le montant rembourse et conserve la trace du paiement source.

Les statuts de panier, commande, paiement, fulfillment, retour et remboursement suivent des machines distinctes. Chaque transition est historisée avec un identifiant de corrélation. Après validation, les snapshots produit, client, adresses et méthode de livraison sont immuables ; une modification ultérieure du PIM ou du CRM ne réécrit jamais la commande.

## Exécuter la commande

La fiche Commande regroupe articles et conditions, synthèse financière, préparation/livraison, documents, messages et historique unifié. Son en-tête montre numéro, relation, canal, total, état global et prochaine action. Les actions impossibles indiquent le blocage, par exemple paiement requis ou emplacement manquant.

Les outils avancés regroupent les files transversales et diagnostics :

- **Préparations** : la livraison quotidienne se pilote depuis le dossier ; la file avancée sert au traitement transversal. Allocation d’un emplacement, progression par ligne et quantité, problème conservé, expédition ou retrait local. Un retrait passe par `prêt`, puis `remis`, avec le code client et une preuve opérateur minimale. Une commande peut avoir plusieurs fulfillments partiels ; les allocations ouvertes ne peuvent jamais dépasser la quantité commandée.
- **Transferts** : demande, expédition, transit, réception et écarts. La demande ne change aucun stock. L’expédition écrit uniquement la sortie d’origine ; la réception écrit uniquement l’entrée de destination. Une annulation après départ est refusée.
- **Inventaires** : session par emplacement, comptage progressif ou à l’aveugle, sauvegarde ligne par ligne, revue des écarts, puis validation séparée. La validation produit un mouvement `inventory_adjustment` par écart motivé.

Le stock réservé est consommé une seule fois lors de la confirmation commerciale de la commande. La préparation physique ne le débite jamais une seconde fois. Les retours peuvent être remis en stock vendable, en quarantaine ou dans un emplacement non vendable.

Dans **Opérations > Inventaires**, le diagnostic de reconstruction compare le stock affiché au journal immuable, aux réservations, aux opérations logistiques et à la projection Shop. **Lancer l'aperçu** est sans effet de bord. En cas d'écart, l'écran montre sa gravité, sa cause probable, l'impact public et les valeurs avant/après. La réparation n'est proposée qu'à un opérateur autorisé ; elle exige un motif, crée automatiquement une sauvegarde Sale et Business, puis fournit une preuve téléchargeable. Une quantité physique constatée différente devient un mouvement correctif explicite et non une modification silencieuse du total.

Dans le compte Shop, le client retrouve le statut métier, les fulfillments partiels, le lieu et le code de retrait, ainsi que la référence de suivi disponible.

Le rapprochement entre commandes invitées, compte IAM et fiche Relation se traite depuis **Opérations > Relations > Outils avancés > Rapprochement de profils**. Ventes conserve les données transactionnelles et les outils de décision, mais n’expose plus d’onglet Identités concurrent. Aucun lien n’est créé sur le nom seul et aucune fusion n’est automatique. L’opérateur autorisé peut lier, conserver séparé, reporter, prévisualiser une fusion champ par champ ou annuler une fusion auditée. Le client Shop ne voit jamais cette structure interne.

La chronologie CRM reçoit commandes, paiements, retours, remboursements, bons cadeaux et comptes par outbox. Elle reste filtrable et lisible comme une histoire client. Les événements sans identité restent en attente et peuvent être rattachés ultérieurement sans bloquer Vente.

Les segments Commerce sont calculés depuis cette chronologie projetée. Ils peuvent qualifier nouveau/récurrent, fréquence, montant, canal, produit/catégorie, retour ou bon cadeau, mais ne pilotent pas le classement public du shop. Un achat ou une création de compte ne crée jamais un consentement marketing ; préférences, consentements et nécessité transactionnelle restent distincts.

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
- pas d’achat d’étiquette transporteur ni de calcul de tournée ;
- pas de POS hors-ligne ;
- pas de fiscalite multi-pays avancee ;
- documents commerciaux immuables, mais pas de comptabilité générale, numérotation fiscale multi-pays ou export comptable complet ;
- rappels et annulation automatique du paiement différé à brancher à un scheduler d’exploitation ;
- pas de synchronisation CRM/CMS activee par defaut.

## Voir aussi

- [POS Vente](vente-pos.md)
- [Commandes Vente](vente-commandes.md)
- [Architecture Vente](../development/architecture/sale-module.md)
- [API admin Vente](../api/admin-internal/sale.md)
