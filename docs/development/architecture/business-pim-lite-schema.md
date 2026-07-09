---
title: Schéma PIM-lite Opérations
audience:
  - developer
  - administrator
  - evaluator
status: draft
last_verified: 2026-07-08
source_of_truth: code
source_paths:
  - database/modules/business.sql
  - backend/src/Modules/Business/BusinessModuleProvider.php
  - backend/src/Application/Api/Admin/BusinessPimApiController.php
  - backend/src/Application/PublicApi/PublicCatalogApiHandler.php
  - backend/src/Modules/Business/Services/BusinessPimAdminService.php
  - backend/src/Modules/Business/Services/BusinessProductAssetService.php
  - backend/src/Modules/Business/Services/BusinessProductBundleService.php
  - backend/src/Modules/Business/Services/BusinessCatalogSellableReadService.php
  - backend/src/Modules/Business/Repositories/PublicCatalogRepository.php
  - backend/src/Modules/Sale/Services/SaleCatalogSnapshotService.php
  - tools/php/tests/unit/business_pim_lite_schema_test.php
  - tools/php/tests/unit/business_pim_lite_fixtures_test.php
  - tools/php/tests/unit/business_pim_lite_blueprints_test.php
  - tools/php/tests/unit/business_product_asset_service_test.php
  - tools/php/tests/unit/business_pim_api_controller_test.php
  - tools/php/tests/unit/business_catalog_public_api_test.php
  - tools/php/tests/unit/business_sellable_snapshot_service_test.php
owners:
  - business
document_type: architecture
generated: false
---
# Schéma PIM-lite Opérations

Le PIM-lite etend le schema natif `business.sqlite` sans creer de module autonome et sans deplacer le catalogue existant. Les tables ajoutees couvrent les assets produit, les metadonnees media, les attributs configurables, la completude et les relations entre produits.

## Tables ajoutées

| Table | Role |
|---|---|
| `business_product_assets` | Assets lies a un produit ou une variante, avec role, canal et visibilite. |
| `business_asset_metadata` | Droits, licence, credit, source et metadonnees libres d'un media. |
| `business_asset_renditions` | Emplacement pret pour futures renditions par canal, sans pipeline image impose. |
| `business_attribute_groups` | Groupes d'attributs PIM. |
| `business_attributes` | Definitions d'attributs configurables. |
| `business_attribute_options` | Options d'attributs `select` ou `multi_select`. |
| `business_product_attribute_values` | Valeurs d'attributs au niveau produit. |
| `business_variant_attribute_values` | Valeurs d'attributs au niveau variante. |
| `business_product_completeness_rules` | Regles de completude par scope et canal. |
| `business_product_completeness_scores` | Score calcule, verdict `is_sellable` et exigences manquantes. |
| `business_product_relations` | Relations produit : accessoires, alternatives, upsell, cross-sell et similaires. |
| `business_product_bundles` | Composition d'une offre composee portee par un produit `bundle` vendable distinct. |
| `business_bundle_components` | Composants produit/variante et quantites de l'offre composee. |

## Assets produit

`business_product_assets` est le seul modele media metier du catalogue Opérations. Les fichiers restent geres par la logique Medias/Assets du CMS et sont references par `media_id`.

La branche PIM-lite repart from scratch : l'ancien modele `business_product_media` n'est pas conserve dans le schema natif, les fixtures ou les services. Les medias produits ne dupliquent donc pas les medias CMS ; ils decrivent uniquement l'usage metier d'un `media_id` existant pour un produit, une variante, un canal et un role.

`BusinessProductAssetService` est le point d'acces commun pour :

- resoudre l'asset principal d'une variante puis le fallback produit ;
- lister les assets actifs par produit, variante, canal et visibilite ;
- assigner un media CMS a un produit ou une variante ;
- archiver un asset sans supprimer le media CMS.

Le snapshot vendable, le catalogue public et le POS utilisent ce service afin de garder la meme priorite d'image entre gestion produit, stock et ventes.

Roles autorises :

- `main`
- `gallery`
- `variant`
- `thumbnail`
- `document`
- `technical_sheet`
- `brand_logo`
- `packaging`
- `seo`
- `internal`

Canaux autorises :

- `all`
- `public`
- `ecommerce`
- `pos`
- `admin`
- `pdf`

Deux indexes uniques partiels garantissent une seule image principale active par produit/canal et par variante/canal.

## Attributs

Les attributs sont definis dans `business_attributes` et peuvent etre rattaches a un groupe. Les types restent limites aux besoins PIM-lite :

- texte et contenu : `text`, `textarea`, `rich_text` ;
- valeurs structurees : `number`, `decimal`, `boolean`, `date`, `url`, `file` ;
- choix : `select`, `multi_select` ;
- mesures : `dimension`, `weight`, `color`.

Les valeurs sont separees entre produit et variante afin de conserver la variante comme objet vendable.

## Complétude

`business_product_completeness_rules` decrit ce qui est attendu par scope :

- `product`
- `variant`
- `asset`
- `price`
- `tax`
- `channel`

`business_product_completeness_scores` stocke le resultat calcule :

- `score` borne entre 0 et 100 ;
- `is_sellable` pour le verdict exploitable par Vente ;
- `missing_json` pour la liste des informations manquantes ;
- `channel` pour distinguer POS, e-commerce, public, admin ou PDF.

`BusinessPimAdminService` fournit un recalcul minimal deterministe pour les champs de base deja disponibles : nom, slug, taxe, prix de vente et asset produit. Les regles avancees par famille produit, canal ou attribut restent extensibles au-dessus de ce socle sans changer les endpoints.

## Compatibilité Vente

Vente continue de lire le catalogue via `BusinessCatalogSellableReadService` et `SaleCatalogSnapshotService`. Les nouvelles tables PIM-lite servent a enrichir le diagnostic et les assets du snapshot, pas a creer des commandes ou stocks transactionnels dans `business.sqlite`.

## Offres composées

Les offres composees vivent dans l'onglet **Offres** d'Opérations, pas dans une route headless publique. Elles decrivent une composition vendable que Vente pourra figer dans un panier, une commande ou une ligne POS.

Un bundle est d'abord un produit catalogue de type `bundle`. Ce produit vendable possede son propre SKU, slug, nom, descriptifs, canaux, prix de base et offres/reductions. Les produits composants restent des produits catalogue independants et peuvent continuer a etre vendus seuls avec leurs propres identifiants, descriptifs, prix et offres.

`business_product_bundles` stocke la composition attachee a ce produit `bundle` ou a l'une de ses variantes, le mode de prix et le mode de disponibilite :

- `fixed` : le produit `bundle` porte son propre prix catalogue et les reductions existantes peuvent cibler ce produit ou sa variante ;
- `sum_components` : mode prepare pour calculer une somme indicative des composants ;
- `discount_components` : mode prepare pour une somme remisee ;
- `components` : la disponibilite se lit depuis les composants ;
- `virtual` : disponibilite virtuelle ;
- `none` : pas de suivi de disponibilite.

`business_bundle_components` stocke les composants, leur quantite positive et leur caractere requis. Le service refuse les boucles simples de composition et les quantites nulles ou negatives.

Le bundle ne decrémente pas le stock. Les reservations, annulations, retours et mouvements transactionnels restent dans Vente. Le snapshot vendable ajoute seulement :

- `is_bundle` ;
- `bundle_components` ;
- `bundle_pricing_mode` ;
- `bundle_stock_mode` ;
- `bundle_available_quantity` ;
- exigences manquantes liées aux composants non vendables.

Les composants retournes par le snapshot ne contiennent pas de prix d'achat. Les prix d'achat et marges restent controles par `business.catalog.purchase_prices.read`.

## Service Sellable Variant Snapshot

`BusinessCatalogSellableReadService` est la lecture interne stable pour Vente, POS, e-commerce, exports et futures couches IA admin. Son contrat principal est :

- `getSellableVariantSnapshot(siteId, variantId, context)` ;
- `listSellableVariants(siteId, filters)` ;
- `explainSellability(siteId, variantId, context)`.

Le contexte accepte notamment `channel`, `currency`, `language`, `include_purchase_price` et `include_internal_fields`. Les canaux supportes sont `admin`, `pos`, `ecommerce`, `public` et `quote`.

Le snapshot retourne les identifiants produit/variante, SKU, prix en minor units, taxe, disponibilite, image principale PIM, remises appliquees et exigences manquantes. Les anciennes cles consommees par Vente (`business_variant_id`, `unit_price_minor`, `unit_purchase_price_minor`) restent presentes en contexte interne pour compatibilite.

Les prix d'achat, marges et champs internes ne sont jamais inclus par defaut. Ils ne sont presents que si le contexte serveur demande explicitement `include_purchase_price=true`; `publicPayload()` les retire toujours.

La vendabilite refuse explicitement les variantes inactives, produits inactifs, SKU absent, prix de vente absent, taxe absente, canal desactive, visibilite publique manquante et stock indisponible sans backorder. Les scores `business_product_completeness_scores` peuvent ajouter des exigences manquantes.

`SaleCatalogSnapshotService` consomme ce service au lieu de recomposer les donnees catalogue.

## Blueprints admin

`BusinessModuleProvider` declare des blueprints admin pour les ressources PIM-lite suivantes :

- `business.product_asset`
- `business.asset_metadata`
- `business.attribute_group`
- `business.attribute`
- `business.attribute_option`
- `business.product_attribute_value`
- `business.variant_attribute_value`
- `business.completeness_rule`
- `business.completeness_score`
- `business.product_relation`
- `business.product_bundle`
- `business.bundle_component`
- `business.sellable_variant_snapshot`

Ces blueprints documentent les champs, types, validations et permissions pour les contrats admin, l'import/export futur, la decouverte IA et la maintenance. Ils ne remplacent pas l'interface Opérations dediee et ne creent pas de formulaires admin generes.

Tous ces blueprints sont admin-only : `headless.enabled=false`, `headless.public=false` et `public_headless_routes=[]`. Aucune route publique `/api/v1/business/*` ou `/api/v1/pim/*` n'expose les donnees PIM-lite.

`business.sellable_variant_snapshot` est un agregat de lecture interne pour Vente et l'IA admin. Les champs de prix d'achat et de marge sont marques sensibles/admin-only et exigent `business.catalog.purchase_prices.read`.

## API admin PIM-lite

Les endpoints PIM-lite sont declares exclusivement sous `/admin/api/business/pim/...`. Ils utilisent la session admin, l'IAM, l'isolation site et les permissions catalogue existantes :

- lecture : `business.catalog.read` ;
- ecriture PIM, assets, attributs, completude et bulk : `business.catalog.write` ;
- prix d'achat et marges dans le snapshot vendable : `business.catalog.purchase_prices.read`.

Routes assets :

- `GET /admin/api/business/pim/products/{id}/assets`
- `POST /admin/api/business/pim/products/{id}/assets`
- `PATCH /admin/api/business/pim/assets/{id}`
- `DELETE /admin/api/business/pim/assets/{id}`
- `POST /admin/api/business/pim/assets/{id}/set-main`

Routes attributs :

- `GET /admin/api/business/pim/attribute-groups`
- `POST /admin/api/business/pim/attribute-groups`
- `PATCH /admin/api/business/pim/attribute-groups/{id}`
- `DELETE /admin/api/business/pim/attribute-groups/{id}`
- `GET /admin/api/business/pim/attributes`
- `POST /admin/api/business/pim/attributes`
- `PATCH /admin/api/business/pim/attributes/{id}`
- `DELETE /admin/api/business/pim/attributes/{id}`
- `POST /admin/api/business/pim/attributes/{id}/options`
- `PATCH /admin/api/business/pim/attribute-options/{id}`
- `DELETE /admin/api/business/pim/attribute-options/{id}`

Routes valeurs, completude et vendabilite :

- `GET|PUT /admin/api/business/pim/products/{id}/attributes`
- `GET|PUT /admin/api/business/pim/variants/{id}/attributes`
- `GET|PUT|DELETE /admin/api/business/pim/products/{id}/bundle`
- `GET|POST /admin/api/business/pim/bundles/{id}/components`
- `PATCH|DELETE /admin/api/business/pim/bundle-components/{id}`
- `GET /admin/api/business/pim/products/{id}/completeness`
- `POST /admin/api/business/pim/products/{id}/recalculate-completeness`
- `GET /admin/api/business/pim/variants/{id}/sellable-snapshot`
- `GET /admin/api/business/pim/sellable-variants`

Routes bulk :

- `POST /admin/api/business/pim/products/bulk-update`
- `POST /admin/api/business/pim/products/bulk-asset-assign`
- `POST /admin/api/business/pim/products/bulk-recalculate`

Ces endpoints ne creent pas de routes headless publiques. Les contrats admin correspondants sont exposes par `BusinessModuleProvider::apiContracts()`.

## API publique catalogue sûre

Les endpoints publics existants `/api/v1/catalog/products`, `/api/v1/catalog/products/{slug}` et `/api/v1/catalog/variants/{id}` peuvent exposer des donnees PIM utiles pour l'e-commerce, mais uniquement depuis les ressources marquees publiques :

- `main_asset` ;
- `gallery_assets` ;
- `public_attributes` ;
- `is_sellable_public` ;
- prix de vente publics et remises publiques.

Les assets publics proviennent de `business_product_assets` avec `is_public=1` et un role non interne. Le payload ne contient pas les droits d'usage, sources, notes internes, tokens, prix d'achat, marges, stock exact ni exigences de completude detaillees.

Les attributs publics proviennent uniquement de `business_attributes.is_public=1`. Les attributs internes restent disponibles pour l'admin PIM mais ne sortent pas dans l'API headless.

## Fixtures de démonstration

Le schema natif business contient des fixtures PIM-lite minimales pour tester Catalogue+ apres un rebuild :

- plusieurs marques et categories ;
- produits actifs POS/e-commerce et produit volontairement incomplet ;
- variante active et variante brouillon ;
- assets `main`, asset de variante et `technical_sheet` fictif ;
- groupes d'attributs `textile`, `service`, `technique`, `seo` ;
- attributs `couleur`, `taille`, `duree`, `matiere`, `niveau`, `poids` ;
- regles et scores de completude, dont un produit POS vendable et un produit non vendable avec `missing_json`.

## Validation

Le test `tools/php/tests/unit/business_pim_lite_schema_test.php` verifie :

- presence des tables dans le schema natif ;
- colonnes critiques ;
- indexes principaux ;
- contraintes enum ;
- FK internes ;
- unicite de l'image principale active ;
- stockage de `is_sellable` dans les scores de completude.

Le test `tools/php/tests/unit/business_pim_lite_fixtures_test.php` verifie les fixtures PIM-lite chargees depuis le schema natif.

Le test `tools/php/tests/unit/business_pim_lite_blueprints_test.php` verifie que les blueprints PIM-lite sont declares, admin-only, sans exposition headless publique, et que les champs sensibles du snapshot vendable sont proteges.

Le test `tools/php/tests/unit/business_product_asset_service_test.php` verifie la source unique `business_product_assets`, la priorite variante, le fallback produit, l'assignation, l'archivage et l'absence du modele legacy `business_product_media`.

Le test `tools/php/tests/unit/business_pim_api_controller_test.php` verifie les routes et contrats admin PIM, l'absence de routes headless publiques, les endpoints assets/attributs/valeurs/completude/bulk, la protection IAM et le masquage des prix d'achat pour un profil limite.

Le test `tools/php/tests/unit/business_sellable_snapshot_service_test.php` verifie le snapshot vendable interne : prix en minor units, remises, masquage des prix d'achat, image variante puis fallback produit, refus de canal, stock indisponible et explication des variantes non vendables.
