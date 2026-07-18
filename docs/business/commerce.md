---
title: Shop système et E-Commerce dans Ventes
audience:
  - administrator
  - developer
status: current
last_verified: 2026-07-17
source_of_truth: code
source_paths:
  - database/schema/core.sql
  - backend/src/Application/Commerce/ShopConfigurationService.php
  - backend/src/Application/Api/Admin/SaleEcommerceAdminApiController.php
  - backend/src/Application/Frontend/ResolvePublicRoute.php
  - frontend/admin-vue/src/views/modules/SaleEcommerceSettings.vue
  - frontend/admin-vue/src/views/content/ShopSystemEditorView.vue
owners:
  - core
  - business
  - sale
document_type: guide
generated: false
---
# Shop système et E-Commerce dans Ventes

La configuration des Shops se trouve exclusivement sous **Ventes > Réglages > E-Commerce**. Elle orchestre, pour chaque couple `(site_id, langue)`, une page système Studio, un canal Storefront Sale, la route publique `/shop`, une entrée de menu, le panier et les projections publiques.

Il n’existe pas de module Commerce autonome. Les anciennes URL `/commerce` et `/modules/commerce` redirigent vers la section canonique et l’ancienne API `GET /admin/api/commerce/shops` reste un alias de lecture. Aucun de ces alias ne possède de modèle ou de cycle d’activation propre.

## Audit initial du lot 39

La matrice suivante caractérise l’état observé avant modification ; le code seul n’a pas été considéré comme preuve d’un parcours opérationnel.

| Élément audité | État initial | Faits observés | Décision du lot 39 |
|---|---|---|---|
| `cms_sales_channel_storefronts` et canaux Sale | présent | mapping site/canal et canal Storefront actifs, sans portée par langue | conservé comme compatibilité site, complété par une configuration locale canonique |
| résolution multisite, base path et langues | présent | `sites`, `site_domains`, `site_languages.url_prefix` et helpers de chemins localisés | réutilisée sans routeur concurrent |
| routes `/shop`, collections et produits | partiel | routes SSR présentes dès qu’un mapping Storefront était actif | remplacées par un contrôle commun d’activation `(site, langue)` |
| projections Storefront | présent | rebuild déterministe vers Core et lecture SSR/headless depuis Core | réutilisé dans l’activation et la réparation |
| panier dans les thèmes | à remplacer | bouton, tiroir et assets injectés indépendamment d’un état Shop localisé | rendu conditionnel dans les trois thèmes supportés |
| page système Studio | absent | aucune entité protégée `/shop` dans la liste Pages | éditeur système dédié, sans `content_entry` produit |
| menu Boutique | absent | aucun effet piloté par une activation locale | entrée dérivée de la configuration active, sans doublon persistant |
| matrice E-Commerce | partiel | lecture seule des mappings existants | actions Activer, Désactiver et Réparer avec conséquences explicites |
| permissions | partiel | lecture rattachée aux réglages Sale, aucune mutation Shop | contrôles serveur séparés pour lire, éditer, publier et activer |
| module Commerce autonome | absent | aucune carte active ; redirection déjà canonique | absence conservée |

## Modèle canonique

`cms_shop_configurations` appartient au Core et possède une ligne unique par `(site_id, language_code)`. La ligne conserve :

- le statut `inactive`, `activating`, `active` ou `error` ;
- le canal Storefront, son code et la devise ;
- la route verrouillée `/shop`, le thème, le menu, son libellé et sa position ;
- la visibilité du panier et la politique d’exposition des quantités ;
- un brouillon JSON et un snapshot publié versionnés ;
- les dates d’activation, de publication et de reconstruction ;
- un diagnostic de reprise non sensible et l’auteur IAM de la dernière modification.

Le schéma canonique et les seeds sont modifiés directement. Aucune migration n’est utilisée. Le seed de démonstration active explicitement le Shop français ; les autres langues restent inactives.

## Cycle Studio et activation

Le cycle éditorial et le cycle public sont volontairement séparés :

1. ouvrir la matrice ne crée aucune donnée ;
2. enregistrer dans Studio initialise ou met à jour uniquement le brouillon ;
3. publier dans Studio fige un snapshot, sans rendre `/shop` public ;
4. **Activer** valide le canal et le thème, reconstruit les projections, puis rend route, menu et panier visibles pour ce seul couple site/langue ;
5. **Désactiver** masque ces effets sans supprimer configuration, produits ou commandes ;
6. **Réparer** reprend la même ligne après un échec, sans dupliquer la configuration ou le mapping.

Pendant `activating` et après un échec `error`, la route reste non publique. Si une reconstruction échoue, le mapping de compatibilité revient vers un autre Shop actif du site quand il existe. Une réactivation déjà réussie est idempotente.

## Page système dans Studio

Une configuration initialisée apparaît dans **Studio > Pages** avec le badge **Système** (`System` en anglais). L’éditeur autorise le titre, l’introduction, le SEO, l’ordre et la visibilité des sections, le thème, le menu et les paramètres d’affichage.

Le type système, le couple site/langue et la route `/shop` sont verrouillés et accompagnés d’une explication. La page n’est pas un `content_entry` ordinaire : elle ne peut donc pas être supprimée, remplacée ou dupliquée par les actions génériques. Les fiches `/shop/products/{slug}` continuent à être rendues depuis les DTO Storefront ; l’activation ne crée aucune page CMS par produit.

Dans la liste Pages, le bouton de la cellule conserve la route logique lisible `/shop`, comme les autres pages. Sa destination d’ouverture et son infobulle utilisent en revanche le chemin public complet, calculé à partir du sous-répertoire d’installation configuré par `APP_BASE_PATH` et transmis à l’administration par `window.__AMCMS_ADMIN__.basePath`. Aucun nom de sous-répertoire n’est fixé dans le produit : `/edu`, `/eve` ou une installation à la racine suivent le même contrat.

L’aperçu Studio est explicitement signalé comme non public et affiche le brouillon courant. La publication Studio n’est jamais assimilée à l’activation du Shop.

## Permissions serveur

| Opération | Permission |
|---|---|
| lire la matrice ou la page système | `sale.settings.manage` ou `content.read` |
| enregistrer le brouillon Studio | `content.revisions.save` |
| publier le snapshot Studio | `content.publish` |
| activer, désactiver ou réparer | `sale.settings.manage` |

La matrice ne retourne que les sites autorisés sur lesquels au moins une permission de lecture Shop est effective. Masquer une action dans Vue ne remplace jamais le contrôle du contrôleur API. Toutes les mutations exigent aussi un jeton CSRF valide.

## API d’administration

| Méthode et route | Usage |
|---|---|
| `GET /admin/api/sale/ecommerce/shops` | matrice des sites et langues autorisés |
| `GET /admin/api/sale/ecommerce/shops/{site_id}/{locale}` | configuration et options Studio |
| `PUT /admin/api/sale/ecommerce/shops/{site_id}/{locale}` | enregistrer le brouillon |
| `POST .../{locale}/publish` | publier le snapshot Studio |
| `POST .../{locale}/activate` | activer explicitement |
| `POST .../{locale}/deactivate` | désactiver sans perte |
| `POST .../{locale}/repair` | reprendre une activation en erreur |

## Contrat public

Un Shop inactif répond `404` sur `/shop`, ses collections et ses fiches produit. Les endpoints `/api/v1/storefront/*` appliquent le même verrou et ne retombent pas sur un catalogue Business non filtré. Le chemin `/shop` est réservé : une page CMS ordinaire ne peut pas prendre sa place pendant l’inactivité.

Un Shop actif ajoute son entrée au menu configuré et le bouton panier dans les thèmes Default, Aurora et Pulse. La langue suit `site_languages.url_prefix` et le base path courant. Le thème Shop ne remplace pas le thème des autres pages du site.

## Limites du lot

Le lot 39 rend l’activation et la page système opérationnelles ; le lot 40 alimente la recherche, les facettes et les tris. Depuis le lot 41, les huit sections de merchandising sont alimentées par le moteur Storefront : promotions achetables, groupes et catégories applicables, première publication e-commerce, popularité produit et mots-clés agrégés. La popularité reste volontairement anonyme, par site et langue, sans profil client ni recommandation individuelle. Les preuves et limites sont consignées dans [l’évaluation du point 41](../evaluation/storefront-merchandising-popularity-41.md).
## Bons cadeaux

Un produit Business de type `gift_card` est émis par Ventes uniquement lorsque sa commande est entièrement payée. Le Shop accepte ensuite un code dans le checkout, affiche seulement le montant applicable et laisse un provider encaisser le complément éventuel. Un bon ne peut pas acheter un autre bon et un seul bon est accepté par commande.

Le code complet n’est pas stocké. Le destinataire le révèle une seule fois depuis le lien sécurisé reçu ; le back-office n’affiche que les quatre derniers caractères. Toute émission, utilisation, expiration, annulation ou correction rejoint le journal immuable Sale.
