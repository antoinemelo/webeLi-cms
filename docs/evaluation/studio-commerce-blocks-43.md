---
title: Blocs Commerce dans Studio — point 43
audience:
  - evaluator
status: stable
last_verified: 2026-07-17
source_of_truth: procedure
source_paths:
  - backend/src/Application/Business/ProductContentLinkService.php
  - backend/src/Application/Content/BlockDocumentNormalizer.php
  - backend/src/Application/Schema/NativeFieldBlueprintRegistry.php
  - frontend/admin-vue/src/components/editor/CommerceBlockDataEditor.vue
  - frontend/theme-default/templates/partials/storefront-block.twig
  - database/migrations/core/083_studio_commerce_blocks.sql
owners:
  - studio
  - commerce
  - business
  - core
document_type: evaluation
generated: false
---
# Blocs Commerce dans Studio — point 43

## Conclusion contrôlée

Les identifiants historiques `featured_product`, `product_card`, `product_grid`, `collection_grid`, `product_detail` et `add_to_cart` sont désormais des blocs de composition Studio déclarés dans les Blueprints natifs. Une révision ne stocke que des identifiants stables, un mode de sélection et des options de présentation. Le preview, le SSR, le headless et la génération statique hydratent les données depuis les projections Storefront de Core ; aucune seconde entité produit éditoriale ni fiche produit CMS n'est créée.

Statut global : **démontré** pour le contrat serveur, les neuf modes de sélection, les trois thèmes, la compilation Studio et le parcours Chromium ciblé jusqu'à la commande invitée. L'export de la route est démontré, mais le statut global de l'export était `partial` dans l'instance isolée : l'exploitation statique complète reste **partiellement démontrée**. Confiance : **élevée** sur l'architecture et le parcours SSR/headless, **moyenne** sur la chaîne statique complète.

## Caractérisation de l'existant

| Identifiant | Avant le point 43 | Après correction | Compatibilité |
|---|---|---|---|
| `featured_product` | graine SQL, hydratation d'un `product_id`, partial public | Blueprint Studio et sélecteur projeté | `product_id` conservé |
| `product_card` | graine SQL, hydratation d'un `product_id`, carte canonique | Blueprint Studio et options d'affichage | identifiant et données historiques conservés |
| `product_grid` | `product_ids` ou ancien `collection_id` uniquement | neuf modes, override manuel, pagination et état vide | `product_ids` et `collection_id` restent interprétés |
| `collection_grid` | liste explicite de catégories | Blueprint natif, limite, colonnes et état vide | `collection_ids` conservé |
| `product_detail` | hydratation d'un `product_id` vers le partial commun | Blueprint Studio explicite | `product_id` conservé |
| `add_to_cart` | exigeait un `sellable_id` et ajoutait directement | accepte produit ou vendable ; redirige vers la fiche si le choix de variante est ambigu | `sellable_id`, quantité et libellé conservés |

Contradiction corrigée : la présence dans `editor_block_types`, dans les templates et dans `ProductContentLinkService` pouvait laisser croire que les blocs étaient utilisables dans Studio. Ils étaient absents du type TypeScript, du catalogue `BlockEditor` et du registre de Blueprints natifs ; leur création visuelle n'était donc pas démontrée.

## Contrat de sélection

`product_grid` accepte exactement un `selection_mode` principal : `explicit`, `brand`, `category`, `group`, `attribute`, `promotion`, `new`, `popular` ou `relation`. Les paramètres communs sont le site, la langue et le canal implicites, la limite, l'ordre, le nombre de colonnes, l'affichage du prix, de la promotion, de la disponibilité et de l'action, la pagination, l'état vide et `manual_product_ids` comme surcharge ordonnée.

- `explicit` conserve une liste ordonnée d'identifiants produit ;
- marque, catégorie et groupe acceptent leur identifiant stable ou leur slug/code projeté ;
- attribut n'utilise que les attributs publics et filtrables de la projection ;
- promotion ne retient qu'une remise positive sur un produit commandable ;
- nouveauté trie la projection courante selon sa date stable de première publication ;
- popularité utilise les agrégats anonymisés existants, avec repli déterministe si aucune mesure n'est disponible ;
- relation lit les relations typées du produit lié à la page ou de `source_product_id`.

Les DTO `storefront.product.v3` ajoutés à `data.product`, `data.items` ou `data.resolved` sont des données d'exécution. Le composant Studio n'émet jamais ces clés dans la révision.

## Studio et rendu

Le composant spécialisé fournit recherche nom/SKU, image, disponibilité, sélection explicite, critères simples, override réordonnable au clavier, résultat projeté et avertissement pour les références dépubliées, incomplètes ou absentes du canal. Les critères sont conservés après une erreur. Les produits de l'aperçu ouvrent Opérations dans un nouvel onglet ; ce lien exige `business.catalog.read` via les deux endpoints d'administration.

Le rendu des trois thèmes réutilise la carte canonique du point 42. Prix, promotion, disponibilité et actions sont conditionnels. Une référence explicite disparue ne réutilise jamais son ancien DTO ; un emplacement neutre préserve la grille, et l'état vide configuré ne révèle aucune information technique.

`add_to_cart` ajoute directement un vendable explicite et commandable, ou l'unique vendable commandable du produit. Avec plusieurs variantes commandables et aucun vendable explicite, le CTA ouvre la fiche produit : aucune variante arbitraire n'est choisie.

## Publication, cache et invalidation

- Une modification de règle ou d'ordre appartient au document CMS et suit brouillon, publication, historique et restauration de révision.
- Prix, promotion, stock, visibilité, produit et relations alimentent les invalidations Storefront existantes. Après reconstruction, le prochain SSR/headless hydrate la nouvelle projection sans republier la page.
- L'aperçu Studio appelle exactement `ProductContentLinkService::hydrateStorefrontBlocks`, comme SSR et headless.
- L'export statique passe par `StaticExportRenderer` et fige la projection au moment de sa construction. Il doit être reconstruit après une invalidation ; les mutations du panier restent des appels runtime vers l'API Sale et ne sont jamais exportées statiquement.

## Preuves et limites

| Conclusion | Statut | Preuve principale | Limite | Confiance |
|---|---|---|---|---|
| six identifiants compatibles et créables dans Studio | démontré | registre natif, catalogue Vue, migration 083 | activation d'un Blueprint personnalisé peut modifier ses libellés | élevée |
| neuf modes et override ordonné | démontré | `ProductContentLinkService`, test unitaire 84 assertions | popularité dépend de la présence d'agrégats | élevée |
| pas de DTO produit persisté dans la révision | démontré | données émises par `CommerceBlockDataEditor` et hydratation serveur | audit d'une base historique tierce non exécuté | élevée |
| preview identique au public/headless | démontré | comparaison du produit et du prix dans le gate Chromium | comparaison pixel à pixel non réalisée | élevée |
| choix de variante sûr | démontré | assertions `requires_variant_choice` et partial | parcours multi-variante navigateur à rejouer | élevée |
| trois thèmes et mobile | démontré pour les templates, partiellement démontré au navigateur | 29 assertions de rendu sur trois thèmes ; gate mobile sur le thème actif | Aurora et Pulse non rejoués dans ce gate précis | moyenne à élevée |
| achat complet depuis une page marketing | démontré | gate Chromium : CTA clavier, panier, checkout et commande `SALE-…` | un seul navigateur et un seul produit physique | élevée |
| restauration et republication d'une révision | démontré | restauration de la révision publiée puis republication dans le gate | aucun conflit concurrent simulé | élevée |
| export statique de la route | partiellement démontré | route présente dans `routes_exported` | statut global `partial`, cause non capturée dans ce gate | moyenne |

## Commandes exécutées le 2026-07-17

```text
php -l backend/src/Application/Business/ProductContentLinkService.php
php -l backend/src/Application/Schema/NativeFieldBlueprintRegistry.php
php -l backend/src/Application/Api/Admin/BusinessPimApiController.php
résultat : aucune erreur de syntaxe

npm run build (depuis frontend/admin-vue)
premier passage : échec TypeScript TS18046 dans la normalisation d'une liste
correction appliquée, second passage : succès ; 252 modules transformés et build Vite produit

php tools/php/tests/unit/storefront_projection_test.php
premier passage après extension : 4 assertions en échec, révélant que le tri annulait la priorité de l'override manuel
correction appliquée
passage final : [OK] UNIT storefront sellables and projections (90 assertions)

php tools/php/tests/unit/business_pim_api_controller_test.php
[OK] UNIT business PIM admin API (167 assertions, dont routes, contrats et permissions preview/recherche)

php tools/php/tests/unit/storefront_product_rendering_test.php
premier passage après ajout des options : 3 échecs utiles, le filtre Twig `default(true)` remplaçait aussi les valeurs `false`
correction appliquée
passage final : [OK] UNIT storefront product rendering 42 (32 assertions sur Default, Aurora et Pulse)

python3 tools/cms.py migrate --database core --plan
résultat : migration 083 seule en attente

python3 tools/cms.py migrate --database core --apply --backup --yes
résultat : sauvegarde SQLite horodatée créée et migration 083 appliquée

python3 tools/cms.py validate --category database --category configuration --category content --category api --category documentation
résultat final : succès, dont 7 296 contrôles i18n, 652 contrôles API et 1 501 contrôles documentaires

python3 tools/cms.py docs check
premier passage : `routes.md` et `schema-versions.md` obsolètes
python3 tools/cms.py docs generate
second `docs check` : documentation générée à jour

python3 tools/cms.py e2e --use-built-assets --spec studio-commerce-blocks-43.spec.ts
premier passage : échec de publication, le normaliseur historique convertissait le type Commerce en Markdown vide
correction : branchement des six types et suppression explicite des DTO runtime à la normalisation
passage suivant : le produit choisi était un bon cadeau, impropre au scénario de livraison physique ; fixture ciblée corrigée
passage final : 1 scénario Chromium réussi en 25,8 s — preview/headless, publication, export de route, restauration/republication, mobile, clavier, panier et commande invitée

php tools/php/tests/run.php
résultat : suite fonctionnelle PHP complète verte ; quatre scénarios HTTP ignorés explicitement faute de bind TCP, couvert ici par le gate Playwright isolé

python3 tools/cms.py migrate --database core --plan
résultat final : aucune migration en attente

sqlite3 storage/database/core.sqlite 'PRAGMA integrity_check;'
résultat : ok

git diff --check
résultat : succès
```

## Éléments restant à vérifier

- expliquer et corriger le statut global `partial` de l'export isolé, puis inspecter le ZIP/HTML final ; la route cible elle-même figure bien dans `routes_exported` ;
- auditer un lecteur d'écran réel, Firefox et WebKit ;
- mesurer la sélection sur un catalogue volumineux : l'hydratation actuelle charge les DTO publics du contexte avant filtrage, avec une limite de rendu de 100 éléments.
