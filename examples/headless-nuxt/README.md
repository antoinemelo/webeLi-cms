# Exemple headless Nuxt 3

Exemple volontairement minimal pour consommer l’API publique headless v1 d’DEC CMS depuis Nuxt 3. Il ne s’agit pas d’un thème complet : le but est de montrer une intégration claire, vendable à un développeur front-end tiers.

L’API headless v1 expose uniquement les contenus publiés. Les brouillons, prévisualisations et révisions de travail ne sont pas exposés par ces endpoints publics.

## Configuration

Copier `.env.example` vers `.env` :

```bash
cp .env.example .env
```

Variables utilisées :

```env
AMCMS_BASE_URL=https://example.com/mod/site_a
AMCMS_TOKEN=
AMCMS_SITE=site_a
AMCMS_LANG=fr
```

- `AMCMS_BASE_URL` : URL publique du site ciblé, sans `/api/v1` final. En multisite, incluez le chemin du sous-site, par exemple `/mod/site_a`.
- `AMCMS_TOKEN` : token public API optionnel. Ne jamais commiter de token réel.
- `AMCMS_SITE` : clé du site à cibler.
- `AMCMS_LANG` : langue à demander.

## Installation

```bash
npm install
npm run dev
```

Le SDK local est référencé par `file:../../packages/amcms-client`. S’il n’est pas encore compilé ou utilisable, `composables/useAmCmsClient.ts` bascule automatiquement sur `fetch` natif pour les mêmes endpoints.

## Endpoints réellement utilisés

Ces appels sont issus de l’API headless v1 et de `docs/public-api/openapi.v1.json` :

| Besoin | Méthode SDK | Endpoint |
|---|---|---|
| Route publiée | `getRoute('/')` | `GET /api/v1/route?path=/` |
| Menu | `getMenu('primary')` | `GET /api/v1/menus/{key}` |
| Liste de contenus | `getContent('article')` | `GET /api/v1/content/{type}` |
| Contenu par type + slug | `getContentBySlug('article', slug)` | `GET /api/v1/content/{type}/{slug}` |
| Recherche | `search('test')` | `GET /api/v1/search?q=test` |

Aucun endpoint n’est inventé. Les paramètres communs `site` et `lang` sont ajoutés automatiquement, ainsi que `Authorization: Bearer ...` si `AMCMS_TOKEN` est défini.

## Fichiers importants

- `pages/index.vue` : page d’accueil exemple avec `useAsyncData`, route, menu, articles, contenu par slug et recherche.
- `pages/[...path].vue` : page dynamique basée sur le path public résolu par `GET /api/v1/route`.
- `pages/index.vue` et `pages/[...path].vue` : exemples de `useHead()` pour les métadonnées SEO.
- `composables/useAmCmsClient.ts` : usage du SDK TypeScript si disponible, fallback `fetch` natif sinon, gestion propre des erreurs API.
- `utils/amcms-payload.ts` : petites fonctions de lecture tolérantes des payloads normalisés.

## Erreurs API

Les erreurs publiques sont affichées avec :

- statut HTTP ;
- code d’erreur si présent ;
- message normalisé ;
- request ID si présent.

Cela évite d’exposer une trace technique tout en donnant assez d’information au développeur front-end.
