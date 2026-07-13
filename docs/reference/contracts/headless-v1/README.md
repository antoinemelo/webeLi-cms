---
title: Contrat headless v1
audience:
  - api-integrator
  - developer
status: stable
last_verified: 2026-06-22
source_of_truth: contract
owners:
  - api
document_type: reference
generated: false
---

# Contrats API publiques headless v1

Les contrats de `docs/contracts/headless-v1/` décrivent la surface publique headless v1 réellement câblée dans `backend/routes/api.php`. Ils sont volontairement plus structurés que les premiers contrats descriptifs afin de pouvoir servir ensuite de source fiable pour générer un fichier OpenAPI, sans modifier les endpoints PHP ni le comportement runtime.

Principes :

- seules les ressources publiées sont exposées ;
- les réponses restent normalisées sous `Response::success()` avec `data` et `meta` ;
- les erreurs restent normalisées sous `error.v1` ;
- les noms de contrats existants sont conservés ;
- les contrats utilisent des objets JSON explicites pour les listes, médias, menus, taxonomies, SEO et payloads de contenu ;
- les chemins documentés sont les chemins réels de `api.php`, sans inventer de nouveaux endpoints.

## Contexte commun

Chaque endpoint de lecture accepte, lorsque le contrôleur concerné le supporte, les paramètres de contexte suivants :

| Paramètre | Rôle |
|---|---|
| `site` | `site_key` actif à cibler explicitement. Optionnel. |
| `site_id` | Identifiant de site actif. Optionnel. |
| `lang` | Code langue actif. Optionnel ; sinon langue par défaut du site. |

Si `site` et `site_id` sont absents, le site est résolu comme le front public, à partir du domaine et du base path.

## Endpoints v1 documentés

| Endpoint réel | Contrat documentaire | Contrat runtime | Rôle |
|---|---|---|---|
| `GET /api/v1/health` | `public.health.v1` | `public.health.v1` | Vérifier que l’API publique répond. |
| `GET /api/v1/openapi.json` | `public.openapi.json.v1` | OpenAPI JSON | Servir le contrat OpenAPI public JSON. |
| `GET /api/v1/openapi.yaml` | `public.openapi.yaml.v1` | OpenAPI YAML | Servir le contrat OpenAPI public YAML. |
| `GET /api/v1/route?path=/...` | `public.route.show.v1` | `public.route.show.v1` ou redirect | Résoudre une route publique en payload headless complet. |
| `GET /api/v1/content` | `public.content.index.v1` | `public.content.index.v1` | Lister les contenus publiés, avec type optionnel en query. |
| `GET /api/v1/content/{type}` | `public.content.index.v1` | `public.content.index.v1` | Lister les contenus publiés d’un type actif. |
| `GET /api/v1/content/{type}/{slug}` | `public.content.show.v1` | `public.content.show.v1` | Lire un contenu publié par type et slug. |
| `GET /api/v1/routes` | `public.routes.index.v1` | `public.routes.index.v1` | Lister les routes publiques actives/canoniques. |
| `GET /api/v1/languages` | `public.languages.index.v1` | `public.languages.index.v1` | Lister les langues actives du site. |
| `GET /api/v1/search` | `public.search.index.v1` | `public.search.index.v1` | Rechercher dans les documents publiés. |
| `GET /api/v1/menus` | `public.menus.index.v1` | `public.menus.index.v1` | Lister les menus publics actifs. |
| `GET /api/v1/menus/{key}` | `public.menus.show.v1` | `public.menus.show.v1` | Lire un menu public avec son arbre. |
| `GET /api/v1/taxonomies` | `public.taxonomies.v1` | `public.taxonomies.index.v1` | Lister les taxonomies publiques actives. |
| `GET /api/v1/taxonomies/{taxonomy}` | `public.taxonomies.v1` | `public.taxonomies.show.v1` | Lire les termes publics localisés d’une taxonomie. |
| `GET /api/v1/media` | `public.media.index.v1` | `public.media.index.v1` | Lister les médias prêts et validés. |
| `GET /api/v1/media/{id}` | `public.media.show.v1` | `public.media.show.v1` | Lire un média prêt et validé avec variantes. |
| `GET /api/v1/forms/{key}` | `public.forms.show.v1` | `public.forms.show.v1` | Lire un formulaire public actif. |
| `POST /api/v1/forms/{key}/submit` | `public.forms.submit.v1` | `public.forms.submit.v1` | Valider et enregistrer une soumission publique. |
| `GET /api/v1/catalog/brands` | `public.catalog.brands.index.v1` | `public.catalog.brands.index.v1` | Lister les marques publiques du catalogue. |
| `GET /api/v1/catalog/categories` | `public.catalog.categories.index.v1` | `public.catalog.categories.index.v1` | Lister les catégories publiques du catalogue. |
| `GET /api/v1/catalog/products` | `public.catalog.products.index.v1` | `public.catalog.products.index.v1` | Lister les produits publics e-commerce. |
| `GET /api/v1/catalog/products/{slug}` | `public.catalog.products.show.v1` | `public.catalog.products.show.v1` | Lire un produit public e-commerce. |
| `GET /api/v1/catalog/variants/{id}` | `public.catalog.variants.show.v1` | `public.catalog.variants.show.v1` | Lire une variante publique active. |
| `GET /api/v1/pos/catalog/bootstrap` | `pos.catalog.bootstrap.v1` | `pos.catalog.bootstrap.v1` | Initialiser le catalogue POS protégé. |
| `GET /api/v1/pos/catalog/products` | `pos.catalog.products.index.v1` | `pos.catalog.products.index.v1` | Lister les produits actifs POS. |
| `GET /api/v1/pos/catalog/variants` | `pos.catalog.variants.index.v1` | `pos.catalog.variants.index.v1` | Lister ou rechercher les variantes POS, notamment par barcode. |
| `GET /api/v1/pos/catalog/brands` | `pos.catalog.brands.index.v1` | `pos.catalog.brands.index.v1` | Lister les marques utilisées par le catalogue POS. |
| `GET /api/v1/pos/catalog/categories` | `pos.catalog.categories.index.v1` | `pos.catalog.categories.index.v1` | Lister les catégories utilisées par le catalogue POS. |
| `GET /api/v1/modules/{module}/{resource}/schema` | `public.module_resource_schema.v1` | `public.module_resource_schema.v1` | Découvrir le schéma headless d’une ressource métier déclarée par un module actif. |
| `GET /api/v1/content-by-path?path=/...` | `public.content.by_path.v1` | `public.content.by_path.v1` | Alias de compatibilité servi par `PublicHeadlessController::contentByPath`. |
| `GET /api/v1/sale/channels/{code}/bootstrap` | `public.sale.channels.bootstrap.v1` | `public.sale.channels.bootstrap.v1` | Initialiser un canal e-commerce public actif. |
| `POST /api/v1/sale/channels/{code}/cart` | `public.sale.cart.store.v1` | `public.sale.cart.show.v1` | Créer un panier public et retourner son token opaque. |
| `GET /api/v1/sale/channels/{code}/cart/{token}` | `public.sale.cart.show.v1` | `public.sale.cart.show.v1` | Lire un panier public actif par token opaque. |
| `POST /api/v1/sale/channels/{code}/cart/{token}/lines` | `public.sale.cart.lines.store.v1` | `public.sale.cart.lines.store.v1` | Ajouter une ligne à un panier public. |
| `PATCH /api/v1/sale/channels/{code}/cart/{token}/lines/{line_id}` | `public.sale.cart.lines.update.v1` | `public.sale.cart.lines.update.v1` | Modifier la quantité d’une ligne de panier public. |
| `DELETE /api/v1/sale/channels/{code}/cart/{token}/lines/{line_id}` | `public.sale.cart.lines.delete.v1` | `public.sale.cart.lines.delete.v1` | Supprimer une ligne d’un panier public. |
| `POST /api/v1/sale/channels/{code}/checkout` | `public.sale.checkout.v1` | `public.sale.checkout.v1` | Convertir un panier public en commande e-commerce. |
| `POST /api/v1/sale/channels/{code}/cart/{token}/payment-retry` | `public.sale.payment.retry.v1` | `public.sale.payment.retry.v1` | Relancer un paiement refusé sans perdre la commande ni le panier converti. |
| `POST /api/v1/sale/payments/test/{reference}/simulate` | `public.sale.payment.test.simulate.v1` | `public.sale.payment.test.simulate.v1` | Piloter un scénario déterministe, uniquement hors production. |

## Structure minimale exigée

Chaque contrat JSON doit contenir au minimum :

- `contract` ;
- `method` ou `methods` ;
- `path` ou `paths` ;
- `response` ;
- `errors`.

Pour préparer une génération OpenAPI fiable, les contrats ajoutent aussi :

- `summary` ;
- `description` ;
- `query_params` ;
- `path_params` ;
- `security` ;
- `examples`.

Le validateur refuse les anciennes descriptions trop vagues comme `published list item`, `public media summary`, `tree item` ou `same normalized payload` dans les schémas de réponse.

## Sources de données publiques

Les endpoints de contenu et de recherche s’appuient sur `public_content_snapshots`, `content_entry_publications`, `routes`, `seo_metadata` et `search_documents`. Les médias publics sont limités aux `media_assets` avec `lifecycle_status = ready` et `validation_status = valid`. Les menus et taxonomies ne retournent que les éléments actifs. Les endpoints catalogue publics s’appuient sur `business.sqlite` et n’exposent que les produits actifs, publics et e-commerce, sans prix d’achat, marge ni stock exact. Les endpoints POS s’appuient sur les mêmes tables catalogue mais ne retournent que `status = active` et `is_pos_enabled = true`.

## Sécurité

- Les brouillons, révisions de travail, documents de preview et routes admin ne font pas partie de cette surface.
- Les erreurs ne doivent pas exposer de trace technique.
- Les endpoints de lecture peuvent être protégés par Bearer token selon la configuration de sécurité existante.
- Les endpoints publics de formulaires (`/api/v1/forms/{key}` et `/api/v1/forms/{key}/submit`) restent anonymes afin que le frontend public n’expose aucun secret applicatif.
- Les endpoints publics Sale e-commerce restent anonymes, mais exigent un canal actif/public et un token panier opaque pour les opérations panier.
- Les mutations Sale critiques acceptent `Idempotency-Key` en en-tête ou `data.idempotency_key` dans le payload lorsqu'il est explicitement documenté.
- Les scopes attendus restent : `routes:read`, `content:read`, `media:read`, `search:read`, `menus:read`, `taxonomies:read`, `catalog:read` ou l’alias de compatibilité `headless:read`. Le catalogue POS est séparé et exige `pos.catalog.read`.

## Génération OpenAPI v1

Depuis la racine du projet `mod/`, le fichier public OpenAPI est généré à partir des contrats JSON de ce dossier :

```bash
python3 tools/cms.py docs generate
```

Le générateur crée `docs/public-api/openapi.v1.json` et `docs/public-api/openapi.v1.yaml`, puis synchronise `docs/reference/contracts/public-api/openapi.v1.json` et `docs/reference/contracts/public-api/openapi.v1.yaml`. La génération reste sans dépendance Python externe : elle documente les chemins `/api/v1/*`, déclare `BearerAuth`, reprend les paramètres, headers et corps contractuels, normalise les erreurs avec `PublicApiError` et expose les schémas réutilisables principaux, y compris les schémas Sale publics.

## Validation locale

Depuis la racine du projet `mod/` :

```bash
python3 tools/cms.py docs generate
python3 tools/cms.py validate --validator API_SPEC
python3 tools/cms.py docs check
```

La suite consolidée `API_SPEC` est le contrôle statique et non destructif des contrats headless : elle vérifie les routes, les handlers, l’alignement routes publiques/OpenAPI, la synchronisation de l’OpenAPI de référence et la présence des types SDK générés pour Sale.


## Documentation publique développeur

La documentation statique destinée aux développeurs front-end tiers se trouve dans `docs/public-api/` :

- `index.html` : page d’entrée publiable telle quelle ;
- `quickstart.md` : premiers appels et paramètres communs ;
- `authentication.md` : Bearer token et scopes ;
- `errors.md` : structure des erreurs publiques ;
- `examples.md` : exemples `fetch` simples et orientation vers les intégrations `examples/headless-next/` et `examples/headless-nuxt/` ;
- `openapi.v1.json` : contrat machine-readable généré depuis les contrats headless v1.

Des exemples front-end légers sont également fournis dans `examples/headless-next/` et `examples/headless-nuxt/`. Ils montrent la configuration `AMCMS_BASE_URL`, `AMCMS_TOKEN`, `AMCMS_SITE`, `AMCMS_LANG`, l’usage du SDK TypeScript quand il est disponible, le fallback `fetch` natif, les appels route/menu/content/search et une gestion propre des erreurs publiques.

Cette documentation reste volontairement limitée à la surface réellement disponible : API de lecture sous `/api/v1/`, contenus publiés, médias prêts/valides, menus actifs, taxonomies actives, recherche publique et découverte de schémas headless de ressources modules actives. Elle ne promet ni GraphQL, ni mutation publique, ni brouillons, ni preview avancée.

Validation ciblée :

```bash
python3 tools/cms.py validate --category api --category security
```

## Reconstruction de base

Aucune migration n’est requise pour cette évolution documentaire. Les scripts existants de reconstruction/validation de base restent inchangés : ces contrats ne modifient ni le schéma SQL, ni les seeds, ni le comportement runtime.

## Garde de sécurité documentaire

Le dépôt confirme explicitement que les listes headless s’appuient sur `content_entry_publications.workflow_status = published` et que les contrats de réponse/erreur restent compatibles avec `error.v1`. Cette section est aussi vérifiée par `API_SPEC` et `SECURITY_BASELINE` pour éviter une régression documentaire entre sécurité, contrats et routes publiques.

## Authentification Bearer et CORS headless par site

Lorsque `APP_PUBLIC_API_AUTH_ENABLED` est activé, les endpoints headless peuvent être protégés par un token stateless transmis avec :

```http
Authorization: Bearer <token>
```

Les tokens sont enregistrés dans `api_tokens`. Le token brut n’est pas stocké ; seul `token_hash` est conservé, calculé avec `hash('sha256', $token)`. Les scopes reconnus sont `headless:read`, `routes:read`, `content:read`, `media:read`, `search:read`, `menus:read`, `taxonomies:read`, `catalog:read` et `pos.catalog.read`. L’alias `headless:read` donne accès aux scopes spécialisés publics via `scope_aliases`; `content:read` couvre aussi `routes:read` pour préserver la compatibilité des clients headless existants. `pos.catalog.read` n’est pas inclus dans `headless:read`.

Sur Apache/FastCGI, l’en-tête `Authorization` doit être transmis au runtime PHP. Les `.htaccess` livrés propagent cet en-tête vers `HTTP_AUTHORIZATION`. Si un appel avec `Authorization: Bearer <token-invalide>` renvoie `Bearer token manquant.`, le serveur n’a pas transmis l’en-tête ; s’il renvoie `Bearer token invalide ou inactif.`, le CMS reçoit bien l’en-tête et valide ensuite le token.

La configuration CORS publique peut être définie par site avec `cors_allowed_origins`, en s’appuyant sur `site_settings` et les domaines actifs. Cette partie est vérifiée par `API_SPEC` et `SECURITY_BASELINE` et `API_SPEC` et `SECURITY_BASELINE`.

## Limitation de débit de l’API publique

Le garde `PublicApiRateLimitGuard` protège les routes `/api/v1/*` avant l’authentification Bearer. Il s’applique uniquement à l’API publique et ne limite pas les pages publiques ordinaires.

La limite générale est configurée avec `APP_PUBLIC_API_RATE_LIMIT_DEFAULT_MAX`. Des seuils spécialisés peuvent être définis pour certains groupes d’endpoints, notamment la recherche. Le serveur peut également activer ou désactiver le mécanisme et préciser s’il fait confiance aux en-têtes du proxy au moyen des variables déclarées dans `backend/config/app.php`.

Lorsqu’un seuil est dépassé, l’API renvoie :

- le statut HTTP `429` ;
- le code d’erreur `RATE_LIMIT_EXCEEDED` ;
- un en-tête `Retry-After` contenant un nombre positif de secondes ;
- les en-têtes de limite exposés par le garde, dont `X-RateLimit-Limit`.

Le contrôle statique et, lorsque `pdo_sqlite` est disponible, le scénario dynamique sont exécutés par `API_SPEC` et `SECURITY_BASELINE`. La commande recommandée reste :

```bash
python3 tools/cms.py validate --category security
```

Pour un diagnostic ciblé :

```bash
python3 tools/cms.py validate
```
