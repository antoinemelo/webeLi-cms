---
title: Exemples d’intégration headless v1
audience:
  - api-integrator
status: stable
last_verified: 2026-07-12
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

# Exemples d’intégration headless v1

Cette page regroupe les exemples publics disponibles pour consommer l’API headless v1. Ils s’appuient sur le contrat machine-readable [`openapi.v1.json`](./openapi.v1.json) et sur le mini SDK TypeScript `packages/amcms-client`.

L’objectif est de montrer une intégration simple et vendable à un développeur front-end tiers, sans imposer de thème complet ni de design complexe.

L’API publique headless v1 expose uniquement les contenus publiés. Les brouillons, prévisualisations et révisions de travail ne sont pas exposés par ces endpoints publics.

## Exemples disponibles

| Exemple | Dossier | Usage recommandé |
|---|---|---|
| Vanilla | `examples/headless-vanilla/` | Tester rapidement l’API dans une page HTML simple, sans framework ni build applicatif. |
| Astro | `examples/headless-astro/` | Démarrer une intégration front-end moderne, orientée contenu statique ou hybride, sans React/Vue imposé. |
| Next.js | `examples/headless-next/` | Montrer une intégration App Router, page dynamique et génération de métadonnées SEO côté serveur. |
| Nuxt 3 | `examples/headless-nuxt/` | Montrer une intégration Vue/Nuxt avec `useAsyncData`, page dynamique et `useHead` SEO. |

## Configuration commune

Les exemples Next.js et Nuxt utilisent des variables d’environnement explicites :

```env
AMCMS_BASE_URL=https://webe.li/cms/site_a
AMCMS_TOKEN=
AMCMS_SITE=site_a
AMCMS_LANG=fr
```

- `AMCMS_BASE_URL` : URL publique du site ciblé, sans ajouter `/api/v1`. Pour un sous-site publié sous `/cms/site_a`, utilisez par exemple `https://webe.li/cms/site_a`, même si la clé de site peut avoir n’importe quelle valeur.
- `AMCMS_TOKEN` : jeton optionnel. S’il est fourni, le SDK ou le fallback `fetch` ajoute `Authorization: Bearer <token>`. Aucun token réel ne doit être commité.
- `AMCMS_SITE` : identifiant textuel du site, utile en contexte multisite.
- `AMCMS_LANG` : langue souhaitée pour les contenus, menus, routes, médias et recherches.

Les exemples vanilla/Astro gardent une configuration équivalente dans leurs fichiers existants, afin de rester compatibles avec les premières démonstrations.

## Appels montrés dans les exemples modernes

Les exemples Next.js et Nuxt couvrent volontairement les mêmes appels essentiels :

```js
await client.getRoute('/');
await client.getMenu('primary');
await client.getContent('article', { limit: 5 });
await client.getContentBySlug('article', slug);
await client.search('test', { limit: 5 });
await client.getMedia({ limit: 5 });
```

Ces appels correspondent aux endpoints publics headless v1 documentés dans `openapi.v1.json` :

- `GET /api/v1/route` ;
- `GET /api/v1/menus/{key}` ;
- `GET /api/v1/content/{type}` ;
- `GET /api/v1/content/{type}/{slug}` ;
- `GET /api/v1/search` ;
- `GET /api/v1/media` ;
- `GET /api/v1/media/{id}`.

Les mutations publiques restent limitées aux formulaires, consentements et au panier/checkout invité Sale. Aucun exemple n’expose de brouillon ou de preview avancée.

## Checkout invité

Le parcours natif minimal est disponible sur `/checkout?channel=web-main&cart_token=<token>`. Il est rendu côté serveur puis utilise les contrats publics pour charger le panier et placer la commande. Le même flux peut être intégré avec le SDK :

```js
await client.updateSaleCheckout('web-main', cartToken, {
  step: 'review',
  identity: { email: 'guest@example.test', first_name: 'Anne', last_name: 'Exemple' },
  billing_address: { line1: 'Rue du Test 1', postal_code: '1000', city: 'Lausanne', country_code: 'CH' },
  shipping_same_as_billing: true,
  shipping_method: { code: 'standard' },
  payment: { code: 'bank_transfer' },
  terms_accepted: true,
  marketing_consent: false,
});
```

Le placement final exige `Idempotency-Key`. Les prix, remises, taxes, frais de livraison et totaux envoyés par le navigateur ne sont jamais utilisés comme source de vérité.

## SDK TypeScript et fallback fetch

Le SDK est recommandé pour normaliser les paramètres, les erreurs et l’authentification :

```js
const client = createAmCmsClient({
  baseUrl: process.env.AMCMS_BASE_URL,
  token: process.env.AMCMS_TOKEN,
  site: process.env.AMCMS_SITE,
  lang: process.env.AMCMS_LANG,
});
```

Dans `examples/headless-next/` et `examples/headless-nuxt/`, le client tente d’utiliser `@amcms/client`. Si le SDK local n’est pas encore compilé ou pas utilisable dans l’environnement front-end, les exemples basculent sur `fetch` natif sans changer les endpoints.

Le fallback `fetch` reste volontairement simple :

```js
const response = await fetch(`${baseUrl}/api/v1/search?q=test&site=site_a&lang=fr`, {
  headers: {
    Accept: 'application/json',
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
  },
});

const mediaResponse = await fetch(`${baseUrl}/api/v1/media?site=site_a&lang=fr&limit=5`, {
  headers: {
    Accept: 'application/json',
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
  },
});
```

## Gestion des erreurs

Les exemples affichent proprement les erreurs publiques normalisées :

```js
try {
  const route = await client.getRoute('/');
  console.log(route);
} catch (error) {
  console.error(error.status, error.code, error.message, error.requestId);
}
```

Les interfaces Next.js et Nuxt affichent : statut HTTP, code d’erreur, message public et request ID si disponible. Elles n’affichent pas de trace technique.

## Quand utiliser l’exemple Next.js ?

Utilisez `examples/headless-next/` pour :

- démontrer une intégration React/Next moderne avec App Router ;
- résoudre une page dynamique à partir d’un path public ;
- générer des métadonnées SEO avec `generateMetadata()` ;
- garder une base front-end simple sans thème complet.

## Quand utiliser l’exemple Nuxt 3 ?

Utilisez `examples/headless-nuxt/` pour :

- démontrer une intégration Vue/Nuxt moderne ;
- charger les contenus avec `useAsyncData` ;
- générer les métadonnées SEO avec `useHead` ;
- garder une base front-end simple sans thème complet.

## Quand utiliser l’exemple vanilla ?

Utilisez `examples/headless-vanilla/` pour :

- valider rapidement une URL d’API, un token, un site ou une langue ;
- démontrer l’usage du SDK sans framework ;
- créer une preuve de concept très légère ;
- vérifier les payloads retournés par route, menu, contenu, recherche et médias.

## Quand utiliser l’exemple Astro ?

Utilisez `examples/headless-astro/` pour :

- démarrer une intégration front-end éditoriale simple ;
- consommer l’API côté serveur Astro ;
- garder une base propre sans dépendances d’interface inutiles ;
- préparer un site statique ou hybride basé sur les contenus publiés.

## Ce que l’API ne fait pas encore

L’API headless v1 ne fournit pas GraphQL, n’expose pas les brouillons et ne fournit pas de preview avancée. Les mutations publiques sont uniquement celles explicitement contractées.


Cette page constitue la collection canonique d’exemples pour l’API publique.
