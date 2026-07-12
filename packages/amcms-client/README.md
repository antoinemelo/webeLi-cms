# @amcms/client

Mini SDK TypeScript pour l’API publique headless v1 d’DEC CMS. Le client est volontairement léger : aucune dépendance runtime, aucun framework imposé, uniquement `fetch`.

Il fonctionne dans les navigateurs modernes et dans les runtimes Node modernes qui exposent `fetch`. Un `fetch` custom peut être injecté pour les tests, les environnements plus anciens ou les proxys.

## Installation locale

Le package est prévu pour vivre dans le dépôt :

```bash
cd packages/amcms-client
npm run typecheck
npm run build
```

Aucune dépendance runtime n’est déclarée dans `package.json`.

## Types générés depuis OpenAPI

Les types de réponse les plus précis sont générés depuis `docs/public-api/openapi.v1.json`, uniquement à partir de `components.schemas`, sans dépendance runtime et sans générateur npm lourd.

```bash
python3 tools/python/generators/c40_generate_openapi_headless_v1.py
python3 tools/python/generators/c44_generate_sdk_types_from_openapi.py
python3 tools/cms.py docs check
```

Le fichier généré est `packages/amcms-client/src/generated/openapi-types.ts`. Il ne doit pas être modifié manuellement. Les types publics historiques restent exportés depuis `src/types.ts` afin de ne pas casser l’API du SDK ; lorsque le contrat OpenAPI contient un schéma réutilisable, ces types publics s’appuient sur le type généré correspondant.

Exemple :

```ts
import type { PublicRouteResponse, OpenApiPublicRouteResponse } from '@amcms/client';

const route: PublicRouteResponse = await cms.getRoute('/fr/articles/bonjour');
const sameContract: OpenApiPublicRouteResponse = route;
```

## Création du client

```ts
import { createAmCmsClient } from '@amcms/client';

const cms = createAmCmsClient({
  baseUrl: 'https://example.com',
  token: 'PUBLIC_API_TOKEN',
  site: 'default',
  lang: 'fr',
});
```

Le client ajoute automatiquement `Authorization: Bearer <token>` quand `token` est fourni. Les paramètres communs `site`, `site_id` et `lang` peuvent être définis au niveau du client ou sur chaque appel.

```ts
const cms = createAmCmsClient({
  baseUrl: 'https://example.com',
  site_id: 1,
  fetch: globalThis.fetch,
});
```

## Gestion des erreurs

Les réponses non-2xx lèvent une `AmCmsApiError` avec `status`, `code`, `message`, `details` et `requestId` quand ces champs sont disponibles dans la réponse publique normalisée.

```ts
import { AmCmsApiError } from '@amcms/client';

try {
  await cms.getRoute('/fr/page-inconnue');
} catch (error) {
  if (error instanceof AmCmsApiError) {
    console.error(error.status, error.code, error.message, error.requestId);
  }
}
```

## Méthodes

### getHealth()

Vérifie la disponibilité de l’API headless v1.

```ts
const health = await cms.getHealth();
```

### getRoute(path, options?)

Résout une route publique par chemin.

```ts
const route = await cms.getRoute('/fr/articles/bonjour', { lang: 'fr' });
```

### getRoutes(options?)

Liste les routes publiques indexables. Le filtre `type` peut être transmis si l’API le supporte pour le site courant.

```ts
const routes = await cms.getRoutes({ type: 'article', lang: 'fr' });
```

### getContent(type?, options?)

Liste les contenus publiés. Sans `type`, le client appelle `/api/v1/content`. Avec `type`, il appelle `/api/v1/content/{type}`.

```ts
const allContent = await cms.getContent(undefined, { limit: 10, lang: 'fr' });
const articles = await cms.getContent('article', { page: 1, limit: 12 });
```

### getContentBySlug(type, slug, options?)

Lit un contenu publié par type et slug.

```ts
const article = await cms.getContentBySlug('article', 'bonjour', { lang: 'fr' });
```

### getLanguages(options?)

Liste les langues actives du site.

```ts
const languages = await cms.getLanguages({ site: 'default' });
```

### getMenus(options?)

Liste les menus publics actifs.

```ts
const menus = await cms.getMenus({ lang: 'fr' });
```

### getMenu(key, options?)

Lit un menu public actif par clé.

```ts
const mainMenu = await cms.getMenu('main', { lang: 'fr' });
```

### getTaxonomies(options?)

Liste les taxonomies publiques.

```ts
const taxonomies = await cms.getTaxonomies({ lang: 'fr' });
```

### getTaxonomy(key, options?)

Liste les termes publics d’une taxonomie.

```ts
const categories = await cms.getTaxonomy('categories', { lang: 'fr' });
```

### getMediaList(options?)

Liste les médias publics prêts et validés.

```ts
const images = await cms.getMediaList({ media_type: 'image', limit: 20 });
```

### getMedia(id, options?)

Lit un média public par identifiant.

```ts
const media = await cms.getMedia(42, { lang: 'fr' });
```

### search(query, options?)

Recherche dans les contenus publiés.

```ts
const results = await cms.search('parapente', { type: 'article', limit: 10, lang: 'fr' });
```

### Sale public e-commerce

Les méthodes Sale appellent uniquement les endpoints e-commerce publics déjà
contractés. Le canal doit être actif/public côté CMS et les opérations panier
utilisent le token opaque retourné à la création.

```ts
const bootstrap = await cms.getSaleChannel('web-main', { lang: 'fr' });
const cart = await cms.createSaleCart('web-main');
const token = cart.data.cart.token;

await cms.addSaleCartLine(
  'web-main',
  token,
  { business_variant_id: 3, quantity: 1 },
  { idempotencyKey: 'cart-line-1' },
);

await cms.updateSaleCartLine('web-main', token, 20, { quantity: 2 });
await cms.deleteSaleCartLine('web-main', token, 20);

const order = await cms.checkoutSaleCart(
  'web-main',
  { cart_token: token },
  { idempotencyKey: 'checkout-1' },
);
```

Méthodes disponibles :

- `getSaleChannel(code, options?)`
- `createSaleCart(code, options?)`
- `getSaleCart(code, token, options?)`
- `addSaleCartLine(code, token, payload, options?)`
- `updateSaleCartLine(code, token, lineId, payload, options?)`
- `deleteSaleCartLine(code, token, lineId, options?)`
- `checkoutSaleCart(code, payload, options?)`

## Ce que ce SDK ne fait pas

Ce client reflète uniquement l’API publique headless v1 existante. Il ne fournit pas de GraphQL, pas de gestion des brouillons, pas de preview avancée et pas d’intégration spécifique à React, Vue, Nuxt, Next ou Astro. Les mutations disponibles sont limitées aux endpoints publics explicitement contractés, comme cookies, formulaires et Sale e-commerce.

Le contrat machine-readable de référence reste `docs/public-api/openapi.v1.json`. Les types précis du SDK sont régénérés depuis ce fichier par `python3 tools/python/generators/c44_generate_sdk_types_from_openapi.py`.
