---
title: Module Vente
audience:
  - developer
  - administrator
status: draft
last_verified: 2026-07-08
source_of_truth: code
source_paths:
  - backend/src/Modules/Sale/module.json
  - backend/src/Modules/Sale/SaleModuleProvider.php
  - backend/src/Modules/Sale/Repositories/
  - backend/src/Modules/Sale/Services/
  - frontend/admin-vue/src/views/modules/SalePosView.vue
  - database/modules/sale.sql
  - docs/development/architecture/sale-module-plan.md
owners:
  - sale
  - business
document_type: architecture
generated: false
---

# Module Vente

Le module Vente est le squelette officiel du domaine commercial DEC CMS. Il
utilise la base dediee `storage/database/sale.sqlite` et le schema
`database/modules/sale.sql`.

## Relation avec Operations

Operations reste responsable du CRM et du catalogue produit. Vente depend du
module `business`, mais ne cree pas de cle etrangere vers `business.sqlite`.
Les commandes, paiements et mouvements de stock conservent les identifiants
Operations utiles ainsi que des snapshots transactionnels.

Cette separation evite qu'une modification ulterieure du catalogue ou d'une
fiche client change l'historique d'une commande deja validee.

## Snapshots vendables et clients

La couche d'integration entre Operations et Vente passe par quatre services
internes :

- `BusinessCatalogSellableReadService` lit une variante vendable depuis le
  catalogue Operations et produit un snapshot stable.
- `BusinessCrmRelationSnapshotService` produit un snapshot client depuis une
  entreprise et/ou un contact CRM.
- `SaleCatalogSnapshotService` copie le snapshot catalogue dans
  `sale_catalog_variant_refs`.
- `SaleCustomerSnapshotService` copie le snapshot client dans
  `sale_customer_refs`.

La variante reste l'unite vendable. Une variante inactive ou non vendable ne
doit pas etre ajoutee au panier par les workflows Vente. Les prix de vente sont
copies en unites mineures et incluent les ajustements de variante ainsi que les
remises catalogue actives. Le prix d'achat peut exister dans le snapshot admin
pour les marges, mais il est retire des payloads publics.

Le client est optionnel : le POS peut vendre sans contact CRM. Quand un contact
est fourni, le snapshot inclut aussi son entreprise liee. Ces snapshots sont
ensuite copies dans le panier puis dans la commande afin d'eviter tout recalcul
dynamique apres validation.

## Adaptations catalogue ciblees

Le catalogue Operations fournit deja les champs necessaires a Vente pour la v1 :

- types produit supportes : `physical`, `service`, `gift_card` ;
- variante comme unite vendable ;
- statut produit et statut variante ;
- flags `is_ecommerce_enabled` et `is_pos_enabled` ;
- SKU et code-barres variante ;
- prix achat, prix vente, ajustements de variante et remises catalogue ;
- stock catalogue denormalise.

`BusinessCatalogSellableReadService` expose la recherche vendable par canal,
SKU, code-barres, type produit ou texte libre. Le service convertit les montants
decimaux du catalogue en unites mineures pour Vente.

Le stock catalogue reste un resume informatif gere par Operations. Le stock
transactionnel de Vente utilise `sale_inventory_items`,
`sale_stock_reservations` et `sale_stock_movements` pour les reservations,
ventes, retours et corrections. Les prochains workflows devront ecrire dans
Vente et ne pas modifier directement le stock catalogue hors service dedie.

## Services domaine

La couche backend Vente expose des repositories fins et des services de
workflow sans interface UI :

- `SaleCartService` cree un panier actif et ajoute une ligne depuis un snapshot
  catalogue vendable.
- `SaleCheckoutService` convertit un panier actif en commande placee et copie
  les lignes comme snapshots transactionnels.
- `SalePaymentService` enregistre un paiement manuel/POS sans autoriser le
  surpaiement.
- `SaleInventoryService` reserve, consomme ou libere le stock transactionnel.
- `SaleEventService` journalise les evenements `sale.*`.
- `SaleIdempotencyService` rejoue une reponse deja completee pour les workflows
  critiques qui recoivent une cle d'idempotence.

Les invariants couverts par les tests unitaires sont :

- un panier converti ne peut pas etre converti une seconde fois ;
- une variante non vendable est refusee par la couche snapshot ;
- une ligne de panier reserve le stock transactionnel si la variante suit le stock ;
- le checkout consomme la reservation et ne recalcule pas la commande depuis le catalogue ;
- le paiement ne peut pas depasser le total de la commande ;
- les evenements principaux sont emis.

## API admin privee

Vente expose des endpoints admin prives sous `/admin/api/sale/...`. Ils sont
declares par `SaleModuleProvider`, routes via `SaleAdminApiController`, et
proteges par les permissions `sale.*`.

Les groupes couverts sont :

- canaux : `/admin/api/sale/channels` ;
- paniers : `/admin/api/sale/carts`, lignes, recalcul et checkout ;
- commandes : `/admin/api/sale/orders`, annulation, evenements et recu ;
- paiements : methodes, paiements de commande et remboursement ;
- POS : bootstrap, catalogue caisse, sessions cash, panier caisse, checkout et recu ;
- stock : items, ajustements et mouvements ;
- rapports : quotidien, commandes et sessions POS.

Les actions de checkout, ajout de ligne et paiement acceptent une cle
d'idempotence via payload ou en-tete `Idempotency-Key` lorsque le workflow la
supporte. Les routes Vente restent admin-only : le module ne declare aucune
route headless publique par defaut.

## API e-commerce publique optionnelle

Vente prepare aussi une API e-commerce publique minimale sous
`/api/v1/sale/channels/{code}/...`. Elle n'est utilisable que lorsque les
routes API publiques de modules sont activees cote configuration et que le canal
vise est explicitement `channel_type=ecommerce`, `status=active` et
`is_public=1`. Le seed `web-main` reste en `draft` et non public.

Les endpoints declares sont :

- `GET /api/v1/sale/channels/{code}/bootstrap` ;
- `POST /api/v1/sale/channels/{code}/cart` ;
- `GET /api/v1/sale/channels/{code}/cart/{token}` ;
- `POST /api/v1/sale/channels/{code}/cart/{token}/lines` ;
- `PATCH /api/v1/sale/channels/{code}/cart/{token}/lines/{line_id}` ;
- `DELETE /api/v1/sale/channels/{code}/cart/{token}/lines/{line_id}` ;
- `POST /api/v1/sale/channels/{code}/checkout`.

Le panier public retourne un token opaque uniquement a la creation. La base ne
stocke que `cart_token_hash`. Les payloads publics ne retournent ni prix
d'achat, ni snapshots CRM internes, ni hash de token. Le checkout public cree
une commande `source=ecommerce` et accepte une cle d'idempotence. Aucun listing
public de commandes n'est expose.

Ces routes sont anonymes pour permettre un usage storefront, mais restent
couvertes par le rate-limit public `/api/v1/sale` et par le garde-fou du canal
public actif.

## POS admin v1

La premiere experience caisse est disponible dans le back-office sous
`/admin/app/sale/pos`. Elle reste volontairement simple : un ecran unique pour
chercher un produit, ajouter une variante vendable, modifier les quantites,
encaisser et afficher un recu imprimable.

Les endpoints dedies sont :

- `GET /admin/api/sale/pos/bootstrap` ;
- `GET /admin/api/sale/pos/catalog` ;
- `GET /admin/api/sale/pos/variants` ;
- `POST /admin/api/sale/pos/sessions/open` ;
- `POST /admin/api/sale/pos/sessions/{id}/close` ;
- `POST /admin/api/sale/pos/carts` ;
- `POST /admin/api/sale/pos/carts/{id}/lines` ;
- `PATCH /admin/api/sale/pos/carts/{id}/lines/{line_id}` ;
- `POST /admin/api/sale/pos/checkout` ;
- `GET /admin/api/sale/pos/orders/{id}/receipt`.

La vente POS peut etre realisee sans client CRM. Les prix restent autoritaires
cote serveur via les snapshots catalogue Operations. Le paiement cash exige une
session caisse ouverte ; la cloture calcule le cash attendu, le cash compte et
la difference. Le checkout accepte une cle d'idempotence pour eviter un double
encaissement sur rejeu.

La v1 n'inclut pas le mode hors-ligne, les scanners materiels, l'impression
native ou l'integration reelle d'un terminal de paiement.

## Back-office Vente v1

L'interface admin principale est disponible sous `/admin/app/sale`. Elle expose
quatre entrees simples :

- Tableau de bord : ventes du jour, commandes recentes, paiements recents et
  sessions caisse ouvertes ;
- Commandes : liste, fiche en lecture d'abord, lignes snapshot, paiements,
  evenements, annulation explicite, paiement manuel et impression recu ;
- POS : redirection vers l'ecran caisse `/admin/app/sale/pos` ;
- Reglages : lecture des canaux, moyens de paiement et sessions POS.

La vue reste volontairement operationnelle et non generique. Les actions
sensibles restent des boutons explicites et les permissions sont portees par les
endpoints admin `/admin/api/sale/...`.

## Perimetre du squelette

Le provider `App\Modules\Sale\SaleModuleProvider` declare :

- la base `sale.sqlite` ;
- les permissions `sale.*` initiales ;
- la navigation admin Vente ;
- les endpoints admin prives attendus ;
- des blueprints admin-only pour canaux, paniers, commandes, paiements, POS et stock ;
- aucune route headless publique par defaut.

Les endpoints admin sont declares comme contrat de module. Leur implementation
metier arrive dans les etapes suivantes.

## Source canonique

- Schema SQL : `database/modules/sale.sql`.
- Manifest module : `backend/src/Modules/Sale/module.json`.
- Provider module : `backend/src/Modules/Sale/SaleModuleProvider.php`.
- Plan d'architecture : `docs/development/architecture/sale-module-plan.md`.

Les commandes de validation restent :

```bash
python3 tools/cms.py rebuild
python3 tools/cms.py migrate --plan
python3 tools/cms.py validate
```
