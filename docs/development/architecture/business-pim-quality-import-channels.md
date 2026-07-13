---
title: Qualité PIM, import/export et visibilité par canal
audience:
  - developer
  - administrator
status: draft
last_verified: 2026-07-12
source_of_truth: manual
source_paths:
  - database/modules/business.sql
  - database/migrations/business/0008_pim_quality_import_channels.sql
  - database/migrations/business/0009_pim_channel_visibility_site_guard.sql
  - backend/src/Modules/Business/Services/BusinessProductCompletenessService.php
  - backend/src/Modules/Business/Services/CatalogCsvService.php
  - backend/src/Modules/Business/Services/BusinessCatalogSellableReadService.php
owners:
  - business
document_type: specification
generated: false
---
# Qualité PIM, import/export et visibilité par canal

## Complétude explicable

Les règles de `business_product_completeness_rules` sont configurables par site, type de produit, canal et sévérité. Une règle `block` empêche la publication ; une règle `warn` réduit le score sans bloquer. Les exigences couvrent les champs produit, attributs, variantes, prix, taxe, asset principal, langue et contenu CMS associé.

Le résultat expose séparément `missing` et `warnings`. Chaque élément contient un code stable, le champ concerné, un libellé, le poids, la sévérité et la source. Le score est donc explicable et la décision de publication ne dépend jamais du score seul.

## Visibilité contextuelle

`business_product_channel_visibility` complète les indicateurs historiques du produit avec un statut et une période par canal. En l'absence de ligne, les indicateurs existants restent la valeur par défaut compatible. Lorsqu'une ligne existe, le produit n'est publiable que si son statut est `active` et si l'instant courant appartient à `[starts_at, ends_at[`.

Les lectures vendables et les exports filtrés appliquent le site, le canal, le statut et cette période. Une ligne d'un autre site ne peut pas être utilisée pour rendre un produit visible.

## Import catalogue v1

Le format stable est `pim.catalog.v1`. Les clés de rapprochement sont, dans l'ordre, `external_id`, `product_id` ou `product_slug` pour le produit, puis `variant_id` ou le SKU global pour la variante. Un identifiant trouvé sur un autre site provoque une erreur localisée.

La prévisualisation valide toutes les lignes et fournit le diff champ par champ. Elle n'écrit rien. L'application n'a lieu que si toutes les lignes sont valides et s'exécute dans une transaction unique. Une erreur empêche donc tout import partiel.

Une clé d'idempotence est associée au checksum du fichier. Rejouer le même couple renvoie le journal précédent sans écrire ; réutiliser la clé avec un contenu différent est refusé. Un second import au contenu déjà présent produit des lignes `noop` explicites.

`business_catalog_import_runs` conserve uniquement un résumé : version, checksum, compteurs, statut et acteur. Les données CSV complètes ne sont pas journalisées.

## Export et réimport

Chaque ligne exportée porte `format_version`, `site_id` et `external_id`. Les filtres `channel` et `status` sont appliqués côté serveur, avec la période de visibilité. Les prix d'achat restent conditionnés à la permission existante et aucune donnée secrète n'est exportée.

Un export `pim.catalog.v1` peut être prévisualisé puis réimporté. Les colonnes calculées sont acceptées mais ne pilotent aucune écriture ; seules les colonnes métier documentées sont rapprochées.
