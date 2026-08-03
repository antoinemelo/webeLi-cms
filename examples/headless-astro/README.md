# Exemple headless Astro

Exemple minimal pour consommer l’API headless v1 dans une page Astro. Il reste volontairement petit : une configuration client, une page, quatre appels API.

## Ce que montre l’exemple

- configurer `baseUrl`, `token`, `site` et `lang` ;
- résoudre la route `/` avec `getRoute('/')` ;
- récupérer le menu `primary` avec `getMenu('primary')` ;
- lister des articles avec `getContent('article')` ;
- lancer une recherche avec `search('test')` ;
- gérer les erreurs `AmCmsApiError` côté serveur Astro.

## Installation

Depuis `examples/headless-astro/` :

```bash
npm install
npm run dev
```

Le package `@amcms/client` est référencé localement avec `file:../../packages/amcms-client`. Aucune intégration React, Vue, Nuxt ou Next n’est ajoutée.

## Configuration

Copier `.env.example` vers `.env` si vous souhaitez utiliser des variables locales :

```bash
cp .env.example .env
```

Variables disponibles :

```txt
AMCMS_BASE_URL=https://example.com/cms/site_a
AMCMS_TOKEN=
AMCMS_SITE=site_a
AMCMS_LANG=fr
```

`AMCMS_TOKEN` peut rester vide pour une API publique sans token. Si un token est fourni, le SDK ajoute automatiquement l’en-tête `Authorization: Bearer <token>`.

## Fichiers importants

- `src/lib/amcms.ts` : création du client SDK ;
- `src/pages/index.astro` : appels `getRoute('/')`, `getMenu('primary')`, `getContent('article')`, `search('test')` et rendu minimal.

## Limites

Cet exemple n’est pas un starter complet et ne fournit pas de thème. Il sert à montrer l’intégration headless dans Astro avec le SDK existant.
