---
title: Authentification API headless v1
audience:
  - api-integrator
status: stable
last_verified: 2026-06-26
source_of_truth: contract
owners:
  - api
document_type: guide
source_paths:
  - backend/routes
  - backend/src
  - reference/contracts/public-api
generated: false
---

# Authentification API headless v1

L’API headless v1 peut être protégée par token Bearer selon la configuration de l’installation. Le contrat machine-readable correspondant est publié dans [`openapi.v1.json`](./openapi.v1.json), avec le security scheme `BearerAuth`.

## En-tête HTTP

```http
Authorization: Bearer <token>
Accept: application/json
```

Sur Apache/FastCGI, le serveur doit transmettre cet en-tête à PHP. La release fournit des règles `.htaccess` qui propagent `Authorization` vers `HTTP_AUTHORIZATION`. Si un hébergeur surcharge cette configuration, un appel avec un Bearer invalide doit répondre `Bearer token invalide ou inactif.` ; s’il répond encore `Bearer token manquant.`, l’en-tête est perdu avant le runtime CMS.

Exemple `fetch` :

```js
const response = await fetch('https://example.com/cms/site_a/api/v1/content?site=site_a&lang=fr', {
  headers: {
    'Accept': 'application/json',
    'Authorization': 'Bearer <token>'
  }
});
```

## Endpoints publics sans Bearer

Même lorsque `APP_PUBLIC_API_AUTH_ENABLED=1` et `APP_PUBLIC_API_AUTH_PROTECT_ALL=1`, certains endpoints restent volontairement anonymes :

| Endpoint | Rôle |
|---|---|
| `GET /api/v1/health` | Vérification technique minimale. |
| `GET /api/v1/openapi.json` / `GET /api/v1/openapi.yaml` | Contrat public de l’API. |
| `GET /api/v1/forms/{key}` | Lecture d’un formulaire publié affiché sur une page publique. |
| `POST /api/v1/forms/{key}/submit` | Soumission d’un formulaire public. |

Les formulaires publics ne doivent pas demander de Bearer token au navigateur : un token exposé dans le JavaScript public ne serait plus secret. Ils restent contrôlés par le contexte site/langue, les règles de publication, le CORS, le rate-limit, le honeypot, le délai anti-spam et la validation serveur.

## Scopes reconnus

Les scopes attendus sont ciblés par famille d’usage :

| Scope | Usage |
|---|---|
| `headless:read` | Alias de compatibilité qui donne accès aux lectures `routes:read`, `content:read`, `media:read`, `search:read`, `menus:read`, `taxonomies:read` et `catalog:read`. |
| `routes:read` | Routes publiques, langues et résolution de route. |
| `content:read` | Contenus publiés et, par compatibilité, routes/langues nécessaires à la lecture headless. |
| `media:read` | Médias publics prêts et validés. |
| `search:read` | Recherche publique. |
| `menus:read` | Menus publics actifs. |
| `taxonomies:read` | Taxonomies et termes publics actifs. |
| `catalog:read` | Catalogue public e-commerce, sans prix d'achat ni marge. |
| `pos.catalog.read` | Catalogue POS protégé, limité aux produits actifs POS. Non inclus dans `headless:read`. |

Les endpoints protégés attendent ces scopes :

| Endpoint | Scope requis |
|---|---|
| `GET /api/v1/route`, `/routes`, `/languages` | `routes:read` ou `content:read` |
| `GET /api/v1/content`, `/content/{type}`, `/content/{type}/{slug}`, `/content-by-path` | `content:read` |
| `GET /api/v1/media`, `/media/{id}` | `media:read` |
| `GET /api/v1/search` | `search:read` |
| `GET /api/v1/menus`, `/menus/{key}` | `menus:read` |
| `GET /api/v1/taxonomies`, `/taxonomies/{taxonomy}` | `taxonomies:read` |
| `GET /api/v1/catalog/*` | `catalog:read` ou `headless:read` |
| `GET /api/v1/pos/catalog/*` | `pos.catalog.read` |

Un token doit être traité comme un secret applicatif. Il ne doit pas être exposé dans un dépôt Git public ni injecté dans un bundle front-end public lorsque la sécurité attend une confidentialité stricte. Pour un site statique public, utilisez seulement un token prévu pour cet usage ou un proxy serveur.

## Paramètres `site`, `site_id`, `lang`

L’authentification ne remplace pas le contexte multisite/multilingue. En sous-site, l’URL publique peut déjà contenir le chemin du sous-site, mais le paramètre `site` reste utile pour rendre l’appel explicite :

- `site` cible un site par clé publique ;
- `site_id` cible un site par identifiant technique et prend le dessus sur `site` ;
- `lang` demande la langue de réponse.

```js
const response = await fetch('https://example.com/cms/site_a/api/v1/menus/main?site=site_a&lang=fr', {
  headers: { 'Authorization': 'Bearer <token>' }
});
```

## Réponses d’erreur liées à l’accès

Les erreurs sont normalisées. Selon la configuration, un token absent, invalide ou insuffisant peut produire une réponse JSON avec un objet `error`. Voir aussi [`errors.md`](./errors.md).
