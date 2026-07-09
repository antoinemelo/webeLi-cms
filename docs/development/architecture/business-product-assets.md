---
title: Assets produit Business PIM-lite
audience:
  - developer
  - administrator
  - evaluator
status: draft
last_verified: 2026-07-09
source_of_truth: code
source_paths:
  - database/modules/business.sql
  - backend/src/Modules/Business/Services/BusinessProductAssetService.php
  - backend/src/Application/Api/Admin/BusinessPimApiController.php
  - backend/src/Application/PublicApi/PublicCatalogApiHandler.php
  - backend/src/Modules/Business/Repositories/PublicCatalogRepository.php
  - tools/php/tests/unit/business_product_asset_service_test.php
owners:
  - business
document_type: architecture
generated: false
---
# Assets produit Business PIM-lite

Les assets produit relient les medias CMS au catalogue Opérations. Ils ne remplacent pas la mediatheque du CMS.

```text
media_assets
  fichier, stockage, validation media CMS

business_product_assets
  usage metier du media pour un produit, une variante, un role et un canal
```

## Pourquoi ne pas mélanger médias CMS et médias produit

Le CMS garde la source technique du fichier : stockage, type, validation, cycle de vie et exposition generale.

Business ajoute le contexte commercial :

- ce media est l'image principale d'un produit ;
- ce media est propre a une variante ;
- ce media est un document technique ;
- ce media est public, POS, e-commerce, PDF ou interne ;
- ce media a des metadonnees de licence ou de source.

Cette separation evite de dupliquer les fichiers et permet de changer l'usage catalogue sans casser la mediatheque.

## Tables

| Table | Role |
|---|---|
| `business_product_assets` | Lien produit/variante/media, role, canal, public/interne, tri. |
| `business_asset_metadata` | Licence, credit, source, droits et notes. |
| `business_asset_renditions` | Emplacement pour variantes futures par canal. |

L'ancien modele `business_product_media` ne doit pas revenir dans le schema natif.

## Service canonique

`BusinessProductAssetService` est le point d'acces a utiliser pour :

- lister les assets actifs d'un produit ;
- assigner un media a un produit ou une variante ;
- definir une image principale ;
- archiver un asset ;
- resoudre l'image principale d'une variante avec fallback produit.

Ne pas refaire ces priorites dans un repository public, POS ou Vente.

## Priorité d'image

Pour un affichage catalogue, la priorite attendue est :

1. image principale de la variante pour le canal demande ;
2. image principale du produit pour le canal demande ;
3. fallback compatible `all` ou public selon le contexte ;
4. aucune image si rien n'est disponible.

Le snapshot vendable expose `main_asset`, `main_asset_id`, `main_media_id` et `main_media_url` quand une image est resolue.

## Public, e-commerce, POS et interne

Un asset public doit etre explicitement marque public et utiliser un role compatible. Les roles internes ou documents non publics ne doivent pas sortir dans l'API publique.

Canaux courants :

- `admin` : back-office uniquement ;
- `public` : visible hors back-office ;
- `ecommerce` : storefront ;
- `pos` : caisse ;
- `pdf` : document imprime ou export ;
- `all` : fallback general.

## Relation avec l'API publique catalogue

L'API publique catalogue peut retourner les assets publics seulement. Elle ne doit pas retourner :

- notes internes ;
- droits d'usage internes ;
- metadonnees de travail ;
- assets marques internes ;
- assets de produits non publics ;
- prix d'achat ou diagnostic de completude detaille.

## Tests attendus

`tools/php/tests/unit/business_product_asset_service_test.php` doit rester le test de reference pour :

- source unique `business_product_assets` ;
- absence du legacy `business_product_media` ;
- priorite variante puis produit ;
- assignation et archivage ;
- image principale unique par canal.
