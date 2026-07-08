---
title: Audit préalable Opérations CRM et Catalogue pour Vente
audience:
  - developer
  - administrator
status: draft
last_verified: 2026-07-08
source_of_truth: code
source_paths:
  - backend/src/Modules/Business/BusinessModuleProvider.php
  - backend/src/Modules/Business/module.json
  - database/modules/business.sql
  - database/migrations/business/0001_init.sql
  - database/migrations/business/0002_catalog_pricing.sql
  - database/migrations/business/0003_catalog_schema.sql
  - backend/src/Modules/Business/Catalog
  - backend/src/Modules/Business/Repositories
  - backend/src/Modules/Business/Services
  - backend/src/Application/Api/Admin/BusinessCatalogApiController.php
  - backend/src/Application/Api/Admin/BusinessCrmApiController.php
  - docs/business/catalogue.md
  - docs/business/catalogue-api.md
  - docs/development/architecture/business-module.md
  - docs/development/architecture/business-catalog-functional-spec.md
owners:
  - sale
  - business
document_type: audit
generated: false
---
# Audit préalable Opérations CRM et Catalogue pour Vente

Cet audit fixe l'etat actuel du module technique `business`, expose a l'utilisateur sous **Opérations**, avant la creation du module **Vente** (`sale`). Il sert a eviter les duplications de catalogue, les collisions de schema et les lectures directes fragiles entre domaines.

## Synthese

Opérations possede deja deux domaines utiles a Vente :

- CRM : entreprises, contacts, consentements, memos, messages et activite ;
- Catalogue : marques, categories, produits, variantes, options, prix, remises simples, taxes, stock simple et medias.

Vente doit consommer ces donnees via services de snapshot et conserver ses propres objets transactionnels dans `sale.sqlite` : paniers, commandes, paiements, remboursements, POS, recu, reservations, mouvements transactionnels et evenements. Aucune table Vente ne doit etre ajoutee a `business.sqlite`.

## Module Opérations existant

Le module `business` est un module systeme active par defaut :

| Element | Etat |
|---|---|
| Provider | `App\Modules\Business\BusinessModuleProvider` |
| Manifeste | `backend/src/Modules/Business/module.json` |
| Base | `storage/database/business.sqlite` |
| Schema natif | `database/modules/business.sql` |
| Migrations | `database/migrations/business/*` |
| Routes admin | `/admin/api/business/*` |
| Routes publiques limitees | `/business/memos/share/{token}`, `/business/unsubscribe/{token}` |
| Routes headless CRM publiques | aucune |

Les futurs fichiers Vente doivent suivre la meme gouvernance module : manifeste, provider, base dediee, schema natif, migrations, permissions, contrats, docs et tests.

## Tables CRM utiles

| Table | Proprietaire | Usage potentiel Vente |
|---|---|---|
| `business_companies` | Opérations | Client organisationnel optionnel, adresse ou facturation future. |
| `business_contacts` | Opérations | Client personne optionnel, email, telephone, rattachement IAM eventuel. |
| `crm_consents` | Opérations | Consentements communication ; ne doit pas bloquer une vente POS sans marketing. |
| `crm_contact_channels` | Opérations | Coordonnees par canal. |
| `business_activity_log` | Opérations | Activite CRM ; Vente pourra emettre ses propres evenements ou publier des references, sans ecrire directement partout. |

Vente doit pouvoir fonctionner sans client CRM, notamment pour le POS. Le lien vers CRM doit rester optionnel et snapshotte si necessaire.

## Tables catalogue utiles

| Table | Role actuel | Recommandation Vente |
|---|---|---|
| `business_product_brands` | Marques | Lire via snapshot produit, ne pas dupliquer. |
| `business_product_categories` | Categories hierarchiques simples | Lire via snapshot produit, ne pas dupliquer. |
| `business_products` | Produit logique, type, statut, canaux, taxe, stock tracking | Source canonique produit Opérations. |
| `business_product_options` | Options generiques | Lire pour afficher variantes. |
| `business_product_option_values` | Valeurs d'options | Lire pour snapshot variante. |
| `business_product_option_links` | Options disponibles par produit | Source de configuration, pas transactionnelle. |
| `business_product_variants` | Objet vendable : SKU, barcode, stock simple | Source canonique vendable avant snapshot Vente. |
| `business_product_variant_option_values` | Combinaison option/valeur de variante | Inclure dans snapshot de ligne si utile a l'affichage recu. |
| `business_product_base_prices` | Prix achat/vente de base | Lire via service prix, ne jamais recalculer apres commande placee. |
| `business_product_variant_price_adjustments` | Ajustements achat/vente par variante | Lire via service prix. |
| `business_catalog_discounts` | Remises simples | Lire au moment du calcul panier/commande. |
| `business_tax_classes` | Classes de taxe simples | Lire pour snapshot taxe ; ne pas creer un tax engine international en v1. |
| `business_stock_movements` | Historique stock catalogue simple | Ne pas en faire seul la source transactionnelle Vente. |
| `business_product_media` | Medias produit/variante | Lire pour affichage POS/e-commerce. |
| `business_product_tags`, `business_product_tag_links` | Tags catalogue | Utiles pour recherche/filtrage, pas transactionnels. |

Les tables historiques `business_catalog_products`, `business_catalog_variants`, `business_catalog_variant_price_adjustments` et `business_catalog_offers` existent encore via `0002_catalog_pricing.sql`. Les nouveaux developpements Vente doivent privilegier les tables `business_products`, `business_product_variants`, `business_product_base_prices`, `business_product_variant_price_adjustments` et `business_catalog_discounts`.

## Services et contrats existants

| Service ou contrat | Etat | Usage recommande pour Vente |
|---|---|---|
| `CatalogProductServiceContract` | create/update/archive produit | Ne pas utiliser pour vendre ; utile seulement si Vente devait creer un produit, ce qui est hors v1. |
| `CatalogVariantServiceContract` | create/archive variante | Ne pas utiliser dans le checkout. Vente doit lire une variante vendable. |
| `CatalogDiscountServiceContract` | creation/remise active par variante | Reutiliser indirectement pour les remises simples. |
| `CatalogStockServiceContract` | mouvement stock simple | A adapter prudemment : Vente a besoin de reservations/mouvements transactionnels idempotents. |
| `CatalogPricingService` | prix regulier, prix final, marge, payload public | Base logique du futur snapshot prix vendable. |
| `CatalogVisibilityService` | nettoyage payload public et visibilite e-commerce | Utile pour l'e-commerce public, moins pour POS/admin. |
| `BusinessCatalogPricingRepository` | snapshot prix actuel d'une variante | Bon point de depart pour `SaleCatalogSnapshotService`, mais les montants actuels sont manipules en decimal/formatted. |
| `PosCatalogRepository` | produits/variantes actifs POS, barcode, options, taxe, media | Source importante pour POS Vente. |
| `CatalogStockRepository` | stock actuel et mouvements simples | A consommer avec prudence ; ne couvre pas encore l'idempotence Vente. |

Manque principal : un service interne stable du type `SaleCatalogSnapshotService` ou `BusinessSellableSnapshotService` qui retourne un payload vendable immutable avec variante, produit, prix, taxe, remise, stock disponible et metadonnees d'affichage.

## Proprietaires de donnees

### Rester proprietaire Opérations

- CRM : entreprises, contacts, consentements, memos, messages.
- Catalogue : marques, categories, produits, options, variantes, medias, prix catalogue, remises catalogue, classes de taxe simples.
- Stock catalogue courant et mouvements simples existants.

### Devenir proprietaire Vente

- canaux de vente ;
- caisses, points POS et sessions ;
- paniers et lignes de panier ;
- commandes et lignes de commande ;
- snapshots produit/variante/prix/taxe/remise au moment de la vente ;
- paiements, transactions et remboursements ;
- reservations de stock liees a panier/commande ;
- mouvements de stock transactionnels lies a commande/retour ;
- recus ;
- retours ;
- evenements Vente et idempotence.

## Stock : position recommandee

Le stock catalogue actuel est utile pour l'affichage et les premiers controles de disponibilite. Il ne suffit pas comme source transactionnelle complete pour Vente.

Recommandation :

1. conserver `business_product_variants.stock_quantity` et `stock_reserved` comme resume courant visible dans Opérations ;
2. creer dans `sale.sqlite` les reservations et mouvements transactionnels Vente ;
3. synchroniser les mouvements confirmes vers le stock catalogue via un service unique ;
4. ne jamais modifier le stock directement depuis plusieurs services disperses.

## Permissions Opérations a ne pas modifier

Permissions CRM/messaging/mailing existantes :

- `business.crm.read`
- `business.crm.manage`
- `business.memo.read`
- `business.memo.manage`
- `business.memo.share`
- `business.mailing.read`
- `business.mailing.manage`
- `business.messaging.send`
- `business.messaging.admin`

Permissions catalogue existantes :

- `business.catalog.read`
- `business.catalog.write`
- `business.catalog.prices.read`
- `business.catalog.prices.write`
- `business.catalog.purchase_prices.read`
- `business.catalog.discounts.write`
- `business.catalog.stock.write`

Vente doit declarer ses propres permissions `sale.*` au lieu d'elargir les permissions `business.*`.

## Risques de couplage direct

- Lire directement les tables `business_products` ou `business_product_variants` depuis plusieurs services Vente.
- Recalculer une commande placee avec `CatalogPricingService` apres modification du catalogue.
- Utiliser les prix formattes comme source de calcul au lieu de montants en unites mineures.
- Melanger stock catalogue simple et reservations Vente sans idempotence.
- Lier une commande obligatoirement a un contact CRM, ce qui casserait le POS anonyme.
- Reutiliser les routes publiques catalogue comme surface de checkout.

## Adaptations catalogue necessaires

Avant ou pendant la creation de Vente, prevoir :

1. un service de snapshot vendable, avec montants convertis en unites mineures ;
2. une methode de lecture variante POS/e-commerce stable incluant produit, SKU, barcode, options, media, taxe, devise, prix final, remise appliquee et stock disponible ;
3. une convention de canal compatible avec `admin`, `pos` et `ecommerce` ;
4. une strategie d'arrondi unique ;
5. une separation claire entre prix d'achat, prix de vente regulier, remise et prix final ;
6. une API interne qui ne retourne jamais les prix d'achat si l'appelant n'a pas la permission adequate.

## Fichiers probables des prochains prompts

Fichiers a creer pour Vente :

- `backend/src/Modules/Sale/module.json`
- `backend/src/Modules/Sale/SaleModuleProvider.php`
- `database/modules/sale.sql`
- `database/migrations/sale/0001_init.sql`
- `backend/src/Modules/Sale/Repositories/*`
- `backend/src/Modules/Sale/Services/*`
- `backend/src/Application/Api/Admin/SaleAdminApiController.php`
- `docs/development/architecture/sale-module-plan.md`
- `docs/user-guide/sale/README.md`
- tests PHP unitaires et integration `sale_*`

Fichiers Opérations a modifier avec parcimonie :

- services catalogue pour exposer un snapshot vendable ;
- tests catalogue pour figer ce contrat ;
- docs catalogue pour clarifier que Vente consomme le catalogue sans le posseder.

## Conclusion

Le socle Opérations est suffisant pour commencer Vente, a condition de ne pas le court-circuiter. Le prochain travail doit definir l'architecture `sale` et le contrat de snapshot avant tout schema transactionnel. La duplication du catalogue dans `sale.sqlite` serait une erreur ; la copie doit se limiter aux snapshots immuables de lignes de panier/commande.
