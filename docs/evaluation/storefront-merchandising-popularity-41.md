---
title: Merchandising, promotions et popularité — point 41
audience:
  - evaluator
status: stable
last_verified: 2026-07-17
source_of_truth: procedure
source_paths:
  - backend/src/Application/Commerce/StorefrontMerchandisingService.php
  - backend/src/Application/Business/StorefrontProjectionService.php
  - backend/src/Application/Commerce/ShopConfigurationService.php
  - database/schema/core.sql
  - frontend/admin-vue/src/views/content/ShopSystemEditorView.vue
  - tools/php/tests/unit/storefront_merchandising_test.php
owners:
  - commerce
  - business
  - core
document_type: evaluation
generated: false
---
# Merchandising, promotions et popularité — point 41

## Conclusion contrôlée

Les huit sections configurables du Shop, les promotions classées par montant ou pourcentage, les groupes et catégories applicables, les nouveautés fondées sur la première publication e-commerce, la popularité produit et les mots-clés populaires sont **démontrés** au niveau serveur et unitaire. La configuration Studio, son aperçu explicatif et le rendu SSR commun aux trois thèmes sont également **démontrés** dans Chromium sur une instance isolée. Confiance globale : **élevée**.

## Faits et preuves

| Conclusion | Statut | Preuve | Limite | Confiance |
|---|---|---|---|---|
| promotions actives et achetables, tri montant ou pourcentage | démontré | index Storefront normalisé, `StorefrontMerchandisingService`, assertions dédiées | dépend de la fraîcheur de la projection de prix | élevée |
| nouveauté = première publication e-commerce | démontré | historique conservé à chaque rebuild et test avant/après modification de stock | pas d’import d’un historique antérieur à la migration 082 | élevée |
| popularité produit agrégée par site et langue | démontré | compteur journalier, fenêtre configurable et test de classement | aucune personnalisation individuelle, volontairement | élevée |
| mot-clé populaire = recherche normalisée avec résultat | démontré | rejet des recherches sans résultat ou sensibles, assertion de normalisation | moteur de détection sensible conservateur | élevée |
| exclusion des previews, bots et rafraîchissements abusifs | démontré | garde-fous serveur et déduplication HMAC testés | liste de robots à maintenir | élevée |
| aucune IP, adresse e-mail, compte, panier ou jeton brut | démontré | schéma agrégé, hash HMAC éphémère, inspection des lignes de test | l’agent utilisateur contribue au hash sans être persisté brut | élevée |
| sélection stable sans audience | démontré | repli déterministe sur première publication puis identifiant | ce repli n’est pas une recommandation comportementale | élevée |
| configuration Studio par section | démontré | titre, ordre clavier/souris, activation, limite, affichage, règle, état vide, fenêtre, override et valeurs par défaut ; build Vue vert | aperçu compte les données du dernier brouillon chargé jusqu’à l’enregistrement | moyenne |
| rendu SSR dans les trois thèmes | démontré | scénario Chromium sur Default, Aurora et Pulse, cartes canoniques, dimensions d’image et absence de débordement | Firefox, WebKit et lecteur d’écran non exécutés | élevée |

## Modèle de données et confidentialité

- `storefront_product_publication_history` conserve la première et la dernière publication ainsi que l’état public courant ; une reconstruction ne transforme pas une mise à jour en nouveauté.
- `storefront_analytics_daily` ne conserve que `(site, langue, jour, type, clé agrégée, compteur)`.
- `storefront_analytics_dedup` conserve un HMAC à durée bornée ; l’adresse IP, l’agent utilisateur et la langue navigateur ne sont jamais enregistrés en clair.
- Les recherches ressemblant à une adresse e-mail, une URL, un numéro long ou un jeton sont rejetées. Les recherches sans résultat ne sont pas comptées.
- La rétention des agrégats et la fenêtre de déduplication sont configurables dans Studio et bornées côté serveur.

## Commandes exécutées le 2026-07-17

```text
php -l backend/src/Application/Commerce/StorefrontMerchandisingService.php
php -l backend/src/Application/Commerce/ShopConfigurationService.php
php -l backend/src/Application/Business/StorefrontProjectionService.php
php -l backend/src/Application/Frontend/FrontendPageController.php
php -l backend/src/Application/Frontend/ResolvePublicRoute.php
résultat : aucune erreur de syntaxe

php tools/php/tests/unit/storefront_merchandising_test.php
[OK] UNIT storefront merchandising and privacy 41 (26 assertions)

php tools/php/tests/unit/storefront_projection_test.php
[OK] UNIT storefront sellables and projections (39 assertions)

php tools/php/tests/unit/shop_configuration_service_test.php
[OK] Shop system configuration and activation 39 (35 assertions)

npm run build  (depuis frontend/admin-vue)
succès : vue-tsc --noEmit puis build Vite

python3 tools/cms.py validate --category database --category configuration --category content --category documentation
succès, code 0

php tools/php/tests/run.php
succès : suite fonctionnelle PHP complète ; quatre scénarios HTTP ignorés explicitement faute de port TCP dans le sandbox

python3 tools/cms.py migrate --database core --plan
succès : migration 082 identifiée comme seule migration en attente

python3 tools/cms.py migrate --database core --apply --backup --yes
succès : sauvegarde SQLite horodatée créée, puis migration 082 appliquée

reconstruction ciblée via `StorefrontProjectionService::rebuild()` pour les Shops actifs
succès : 7 produits, 4 collections, canal 3, langue fr ; intégrité SQLite ok, 7 premières publications actives

python3 tools/cms.py e2e --use-built-assets --spec storefront-merchandising-popularity-41.spec.ts
premier passage : échec, projection vide car le test ciblé ne la reconstruisait pas
réexécution après correction de l’isolation : 1 scénario Chromium réussi en 30,4 s sur Default, Aurora et Pulse
```

## Contradictions et limites

- La documentation des points 39 et 40 indiquait que la popularité et le merchandising spécialisé n’étaient pas implémentés. Cette limite était exacte lors de ces contrôles ; elle est levée par le point 41 pour les sélections décrites ici.
- Aucun profil client, compte, panier ou historique individuel n’intervient dans le classement. Toute promesse de recommandation personnalisée serait **absente** et contradictoire avec cette architecture.
- Les données de popularité commencent après l’application de la migration 082 ; il n’existe pas de reconstruction rétroactive fiable à partir des journaux HTTP.
- Le navigateur couvert est Chromium. Firefox, WebKit, zoom 200 % et lecteur d’écran n’ont pas été exécutés.
