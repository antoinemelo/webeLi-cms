# Exemple headless vanilla

Exemple volontairement minimal pour consommer l’API headless v1 dans une page HTML sans framework. Il utilise le mini SDK TypeScript compilé en JavaScript depuis `packages/amcms-client`.

## Ce que montre l’exemple

- configurer `baseUrl`, `token`, `site` et `lang` ;
- résoudre la route `/` avec `getRoute('/')` ;
- récupérer le menu `primary` avec `getMenu('primary')` ;
- lister des articles avec `getContent('article')` ;
- lancer une recherche avec `search('test')` ;
- gérer les erreurs `AmCmsApiError` proprement.

## Préparer le SDK local

Depuis la racine du projet :

```bash
cd packages/amcms-client
npm install
npm run build
```

Aucune dépendance runtime n’est ajoutée dans cet exemple. Le navigateur charge simplement le bundle ESM généré dans `packages/amcms-client/dist/`.

## Lancer l’exemple

Depuis la racine du projet :

```bash
python3 -m http.server 8080
```

Ouvrir ensuite :

```txt
http://localhost:8080/examples/headless-vanilla/
```

## Configuration

La configuration se trouve en haut de `app.js`. Dans une page statique sans étape de build, on ne lit pas automatiquement un fichier `.env`, mais les mêmes noms de variables sont documentés pour garder une configuration cohérente avec Next, Nuxt et Astro :

```txt
AMCMS_BASE_URL=https://example.com/mod/site_a
AMCMS_TOKEN=
AMCMS_SITE=site_a
AMCMS_LANG=fr
```

Exemple équivalent dans `app.js` :

```js
const client = createAmCmsClient({
  baseUrl: 'https://example.com/mod/site_a',
  token: '',
  site: 'site_a',
  lang: 'fr'
});
```

`AMCMS_TOKEN` peut être laissé vide pour une API publique sans token. Si un token est fourni, le SDK ajoute automatiquement l’en-tête `Authorization: Bearer <token>`.

## Limites

Cet exemple n’est pas un thème complet. Il sert uniquement de point de départ pour valider l’intégration headless dans une page HTML simple.
