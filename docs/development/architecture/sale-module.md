---
title: Module Vente
audience:
  - developer
  - administrator
status: draft
last_verified: 2026-07-10
source_of_truth: code
source_paths:
  - backend/src/Modules/Sale/module.json
  - backend/src/Modules/Sale/SaleModuleProvider.php
  - backend/src/Modules/Sale/Repositories/
  - backend/src/Modules/Sale/Services/
  - backend/src/Modules/Sale/Payments/
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

## Documentation complementaire

- [Guide utilisateur Vente](../../business/vente.md) : canaux, commandes,
  encaissement, recus, rapports et limites v1.
- [POS Vente](../../business/vente-pos.md) : session caisse, panier POS,
  paiement, recu et cloture.
- [Commandes Vente](../../business/vente-commandes.md) : statuts, snapshots,
  paiements, remboursements et stock.
- [Schema base Vente](sale-database-schema.md) : tables, montants, snapshots,
  idempotence, stock et outbox.
- [Tests et validation Vente](../testing-validation/sale-module.md) : suites
  PHP/Python et invariants critiques.
- [API interne Vente](../../api/admin-internal/sale.md) : routes admin,
  permissions et idempotence.

## Relation avec Operations

Operations peut rester responsable du CRM et du catalogue produit, mais Vente
ne depend plus directement du module `business`. Vente consomme un catalogue
vendable et des snapshots client via des ports optionnels :

- `SellableCatalogPort` pour lire une variante vendable et rechercher un
  catalogue de vente ;
- `CustomerSnapshotPort` pour copier un client externe dans `sale_customer_refs` ;
- `CrmActivitySink` pour une future projection CRM des evenements Vente ;
- `CmsAccountBridge` pour une future liaison compte/profil CMS.

L'adaptateur `BusinessSellableCatalogAdapter` branche le catalogue Operations
quand `business` est actif. `BusinessCustomerSnapshotAdapter` branche les
snapshots CRM. Si `business` est inactif ou non livre, Vente reste demarrable :
les commandes, paiements, rapports et historiques existants restent lisibles,
mais l'ajout au panier depuis un catalogue externe retourne
`sale.catalog.integration_unavailable`. Les commandes, paiements et mouvements
de stock conservent les identifiants Operations utiles ainsi que des snapshots
transactionnels, sans cle etrangere vers `business.sqlite`.

Cette separation evite qu'une modification ulterieure du catalogue ou d'une
fiche client change l'historique d'une commande deja validee.

## Snapshots vendables et clients

La couche d'integration entre Operations et Vente passe par des ports Sale et
des adaptateurs optionnels :

- `BusinessSellableCatalogAdapter` adapte `BusinessCatalogSellableReadService`
  au port `SellableCatalogPort`.
- `BusinessCustomerSnapshotAdapter` adapte `BusinessCrmRelationSnapshotService`
  au port `CustomerSnapshotPort`.
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

Le port `SellableCatalogPort` expose la recherche vendable par canal, SKU,
code-barres, type produit ou texte libre. L'adaptateur Operations convertit les
montants decimaux du catalogue en unites mineures pour Vente.

Le stock catalogue reste un resume informatif gere par Operations. Le stock
transactionnel de Vente utilise `sale_inventory_items`,
`sale_stock_reservations` et `sale_stock_movements` pour les reservations,
ventes, retours et corrections. Les prochains workflows devront ecrire dans
Vente et ne pas modifier directement le stock catalogue hors service dedie.

## Services domaine

La couche backend Vente expose des repositories fins et des services de
workflow sans interface UI :

- `SalePricingService` calcule de facon deterministe les totaux de lignes et
  de paniers a partir des snapshots vendables Operations.
- `SaleCartService` cree un panier actif et ajoute une ligne depuis un snapshot
  catalogue vendable.
- `SaleCheckoutService` convertit un panier actif en commande placee et copie
  les lignes comme snapshots transactionnels.
- `SalePaymentService` enregistre les paiements et remboursements via les
  providers v1, sans autoriser le surpaiement ou le remboursement excessif.
- `SaleInventoryService` reserve, consomme ou libere le stock transactionnel.
- `SaleStockReservationService` porte les reservations de panier, leur
  expiration et leur consommation au checkout.
- `SaleStockMovementService` historise les ajustements, ventes, releases et
  retours restockes.
- `SaleEventService` journalise les evenements `sale.*` et alimente
  `sale_outbox` avec une enveloppe stable `schema_version=1`.
- `SaleIdempotencyService` verrouille les workflows critiques, rejoue une
  reponse deja completee et conserve les erreurs associees aux cles echouees.

Les invariants couverts par les tests unitaires sont :

- un panier converti ne peut pas etre converti une seconde fois ;
- une variante non vendable est refusee par la couche snapshot ;
- les prix d'achat du PIM ne participent jamais aux totaux client ;
- une taxe incluse est extraite du prix final, une taxe exclue est ajoutee au
  prix final ;
- les remises catalogue ou manuelles ne peuvent jamais produire un total
  negatif ;
- une ligne de panier reserve le stock transactionnel si la variante suit le stock ;
- le checkout consomme la reservation et ne recalcule pas la commande depuis le catalogue ;
- le paiement ne peut pas depasser le total de la commande et un rejeu
  idempotent ne cree pas de second paiement ;
- un remboursement ne peut pas depasser le paiement source et un rejeu
  idempotent ne cree pas de second remboursement ;
- un produit non tracke ne cree pas de reservation de stock ;
- un produit tracke reserve le stock disponible, refuse le stock insuffisant,
  expire les reservations de panier et consomme la reservation au checkout ;
- tout changement de stock transactionnel ecrit un mouvement dans
  `sale_stock_movements`, y compris les retours restockes ;
- les evenements principaux sont emis.

## Pricing, taxes et totaux

Le moteur de pricing Vente utilise exclusivement des montants entiers en unites
mineures. Pour une ligne, `regular_unit_price_minor * quantity` donne le
sous-total avant remise. `unit_price_minor * quantity` represente le prix de
vente courant issu du snapshot catalogue, apres ajustement de variante et remise
catalogue simple. L'ecart positif entre les deux devient une remise catalogue.

Les remises Vente de ligne sont ensuite appliquees sur ce montant restant, avant
taxe. Une remise en montant est plafonnee au montant de ligne disponible ; une
remise en pourcentage utilise des basis points. Le total d'une ligne est donc
toujours superieur ou egal a zero.

Pour les taxes, `tax_rate_basis_points` reste la seule unite de taux. Si
`tax_included=1`, la taxe est extraite du total de ligne. Si `tax_included=0`,
elle est ajoutee au total de ligne. L'arrondi est un arrondi entier half-up sur
les unites mineures. Le `shipping_total_minor` reste a `0` en v1.

Le panier somme les lignes calculees par `SalePricingService`. Le checkout copie
ensuite les montants, libelles, SKU, taxe et remise dans `sale_order_lines` :
une commande placee ne depend plus du prix courant dans Operations.

## Providers paiement et idempotence

La v1 definit l'interface `PaymentProvider` avec les operations
`createIntent`, `recordPayment`, `capture`, `refund`, `void` et `supports`.
`PaymentProviderRegistry` expose les providers locaux `cash`, `manual_card`,
`external_terminal`, `bank_transfer` et `test`. Ces providers ne declenchent
aucun envoi externe : ils produisent une reference deterministe et marquent le
payload provider avec `external_call=false`.

Chaque encaissement cree d'abord une entree `sale_payment_intents`, puis une
transaction `sale_payment_transactions`. Les transactions reussies de type
`payment` ou `capture` creent une allocation dans `sale_payment_allocations`.
Les remboursements creent une ligne `sale_refunds`, une transaction de type
`refund` sans allocation positive et mettent a jour `refunded_total_minor` ainsi
que le statut de paiement de la commande.

`SaleIdempotencyService::run` encapsule les actions critiques avec
`sale_idempotency_keys` :

- `checkout.place_order` ;
- `payment.capture` ;
- `refund.create` ;
- `pos.complete_sale`.

Une meme cle, pour un meme scope et une meme empreinte de requete, rejoue la
reponse completee. Une cle deja en traitement retourne une erreur de verrou
logique. Une meme cle avec un payload different est refusee. Si le callback
echoue, le statut `failed` et le message d'erreur sont conserves, puis la meme
exception est propagee au caller.

## Stock transactionnel

Le stock catalogue Operations reste une reference d'affichage et de recherche.
Le stock Vente est la source transactionnelle pour les reservations, les ventes
et les retours. Les tables concernees sont `sale_inventory_items`,
`sale_stock_reservations` et `sale_stock_movements`.

Lorsqu'une ligne de panier cible une variante trackee, Vente cree ou retrouve
son item de stock local depuis le snapshot vendable Operations. Une reservation
active augmente `reserved_quantity`, diminue `available_quantity` et cree un
mouvement `reservation`. Les produits non trackes ne creent aucune reservation.

La disponibilite du snapshot distingue trois cas :

- `in_stock` : stock positif ou produit non tracke, livrable immediatement ;
- `backorder` : stock a zero mais livraison differee activee, commandable avec
  un delai `backorder_delivery_days` ;
- `contact_us` : stock a zero et livraison differee desactivee, visible en
  catalogue mais non vendable en POS/e-commerce.

Si le stock disponible Vente est insuffisant et que le backorder est autorise,
Vente reserve uniquement la quantite disponible et laisse passer la commande
pour le reliquat sans mouvement de stock negatif. Si le backorder est desactive,
l'ajout ou l'augmentation de ligne est refuse avec une erreur metier.

Les reservations de panier ont une date `expires_at`. Le service
`SaleStockReservationService::expireDue()` libere les reservations actives
arrivees a echeance, marque leur statut `expired` et ecrit un mouvement
`release`. La suppression ou la diminution d'une ligne libere aussi la quantite
reservee excedentaire.

Au checkout, les reservations actives du panier passent a `consumed`,
`reserved_quantity` est remis a jour, `on_hand_quantity` diminue, et un
mouvement `sale` negatif est ecrit avec la commande comme reference. Une
annulation de commande tente de liberer les reservations non consommees du
panier source ; les reservations deja consommees ne sont pas modifiees.

Un retour qui doit restocker utilise `SaleStockMovementService::restockReturn`.
Il augmente `on_hand_quantity`, recalcule `available_quantity` et ecrit un
mouvement `return`. La synchronisation eventuelle vers le stock resume du
catalogue doit passer par un event/outbox dedie et ne bloque pas la vente.

## Events, outbox et IA

Chaque evenement metier Vente ecrit d'abord dans `sale_events`, puis une entree
`pending` correspondante dans `sale_outbox`. Le `topic` est identique a
`event_type` et le payload outbox contient `event_id`, `event_type`, `site_id`,
`aggregate`, `payload`, `created_by_iam_user_id` et `created_at`.

Les contrats publics des topics sont centralises dans
`SaleIntegrationEventContracts`. Les consommateurs CRM, CMS ou IA ne sont pas
branches en v1 : ils devront lire `sale_outbox` via un worker/adaptateur dedie,
marquer les messages `processed` ou `failed`, et ne jamais bloquer checkout,
paiement, POS ou stock transactionnel.

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

Les actions de checkout, ajout de ligne, paiement, remboursement et checkout
POS acceptent une cle d'idempotence via payload ou en-tete `Idempotency-Key`
lorsque le workflow la supporte. Les routes d'administration Vente restent
admin-only ; l'API publique décrite ci-dessous possède une activation runtime
distincte.

## API e-commerce publique optionnelle

Vente prepare aussi une API e-commerce publique minimale sous
`/api/v1/sale/channels/{code}/...`. Elle n'est utilisable que lorsque les
routes API publiques de modules sont activees cote configuration et que le canal
vise est explicitement `channel_type=ecommerce`, `status=active` et
`is_public=1`. Le seed de développement/test rend `web-main` actif et public.
En production, `APP_PUBLIC_API_MODULE_ROUTES` reste désactivé par défaut et doit
être activé explicitement pour charger ces routes.

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
- des routes headless e-commerce optionnelles, inactives tant qu'aucun canal
  `ecommerce` public et actif n'est configure ;
- le contrat d'evenements `integration.sale.events.v1` pour la future
  projection CRM/CMS ;
- le contrat `integration.sale.ai_contexts.v1` pour les contextes IA locaux.

Les endpoints admin, services domaine, imports/exports, rapports, POS, recus,
paiements, remboursements, idempotence et outbox sont couverts par les tests
PHP du module.

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
