---
title: Quickstart API headless v1
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

# Quickstart API headless v1

Cette API publique sert à consommer les contenus publiés du CMS depuis un front-end externe, par exemple un site statique, une application Vue/React/Svelte ou une intégration serveur. Elle est volontairement légère : les endpoints sont des lectures `GET` sous `/api/v1/*`, et le contrat machine-readable est disponible dans [`openapi.v1.json`](./openapi.v1.json), avec un miroir YAML déterministe dans [`openapi.v1.yaml`](./openapi.v1.yaml).

## Base d’utilisation

Remplacez `https://example.com/mod` par l’URL publique du site ciblé. En multisite, `AMCMS_BASE_URL` doit inclure le chemin du sous-site lorsque celui-ci est publié sous un sous-dossier, par exemple `https://webe.li/mod/site_a` pour le site `site_a`. N’ajoutez jamais `/api/v1` à cette variable : le client ou l’appel `fetch` l’ajoute ensuite.

```js
const API_BASE = 'https://example.com/mod/site_a';

async function getJson(path) {
  const response = await fetch(`${API_BASE}${path}`, {
    headers: {
      'Accept': 'application/json',
      'Authorization': 'Bearer <token>'
    }
  });

  const payload = await response.json();
  if (!response.ok) {
    throw new Error(payload?.error?.message || `HTTP ${response.status}`);
  }
  return payload;
}
```

L’en-tête `Authorization` est nécessaire lorsque l’authentification publique par token est activée. Lorsque cette protection est désactivée côté installation, les endpoints de lecture peuvent répondre sans token.

Les scopes de lecture reconnus sont `headless:read`, `content:read`, `media:read`, `search:read`, `menus:read` et `taxonomies:read`. L’alias `headless:read` couvre les cinq scopes spécialisés de lecture.

## Paramètres de contexte

Les endpoints exposent les paramètres suivants lorsqu’ils sont supportés par le contrat :

| Paramètre | Usage |
|---|---|
| `site` | Cible un site par sa clé publique, par exemple `main`. Optionnel. |
| `site_id` | Cible un site par son identifiant technique. Optionnel. Si présent, il est prioritaire sur `site`. |
| `lang` | Demande une langue, par exemple `fr`, `en` ou `de`. Si elle est absente ou inactive, le site peut appliquer sa langue par défaut. |

Exemple :

```js
const page = await getJson('/api/v1/route?path=/&site=site_a&lang=fr');
```

## Endpoints principaux

- `GET /api/v1/health` : vérifier que l’API répond.
- `GET /api/v1/route?path=/...` : résoudre une URL publique vers un payload headless complet.
- `GET /api/v1/routes` : lister les routes publiques actives.
- `GET /api/v1/content` et `GET /api/v1/content/{type}` : lister les contenus publiés.
- `GET /api/v1/content/{type}/{slug}` : lire un contenu publié.
- `GET /api/v1/content-by-path?path=/...` : alias de compatibilité pour lire un contenu publié par chemin.
- `GET /api/v1/languages` : lister les langues actives.
- `GET /api/v1/menus` et `GET /api/v1/menus/{key}` : lister ou lire les menus publics.
- `GET /api/v1/taxonomies` et `GET /api/v1/taxonomies/{taxonomy}` : lister les taxonomies ou leurs termes publics.
- `GET /api/v1/media` et `GET /api/v1/media/{id}` : lister ou lire les médias prêts et validés.
- `GET /api/v1/search` : rechercher dans les documents publiés.

## Exemple minimal de page

```js
async function loadHomePage() {
  const route = await getJson('/api/v1/route?path=/&lang=fr');
  return route.data;
}
```


## Contrat OpenAPI JSON et YAML

`openapi.v1.json` reste la sortie principale du générateur OpenAPI. `openapi.v1.yaml` est généré en complément depuis le même objet OpenAPI, sans second générateur divergent. Les deux fichiers décrivent exactement la même surface publique : uniquement les endpoints `GET /api/v1/*`, avec `BearerAuth` lorsque l’authentification publique est activée.

Commande de régénération locale :

```bash
python3 tools/python/c40_generate_openapi_headless_v1.py
python3 tools/cms.py validate --validator API_SPEC
```

## SDK TypeScript minimal

Un client TypeScript léger est disponible dans `packages/amcms-client/`. Il utilise `fetch`, n’impose aucun framework et reste aligné sur `openapi.v1.json` / `openapi.v1.yaml`.

```ts
import { createAmCmsClient } from '@amcms/client';

const cms = createAmCmsClient({
  baseUrl: 'https://webe.li/mod/site_a',
  token: '<token>',
  site: 'site_a',
  lang: 'fr',
});

const route = await cms.getRoute('/');
```

Le SDK ne remplace pas le contrat OpenAPI : il sert uniquement de confort d’intégration pour les développeurs front-end qui veulent éviter d’écrire les appels `fetch` à la main.

## Ce que l’API ne fait pas encore

Cette version publique headless v1 ne fournit pas encore :

- GraphQL ;
- mutation publique de contenus ou de médias ;
- accès aux brouillons ;
- preview avancée ;

Ces limites sont intentionnelles pour conserver une surface v1 claire, stable et lisible pour une intégration front-end tierce.
