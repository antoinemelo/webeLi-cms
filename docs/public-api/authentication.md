---
title: Authentification API headless v1
audience:
  - api-integrator
status: stable
last_verified: 2026-06-14
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

Exemple `fetch` :

```js
const response = await fetch('https://example.com/mod/site_a/api/v1/content?site=site_a&lang=fr', {
  headers: {
    'Accept': 'application/json',
    'Authorization': 'Bearer <token>'
  }
});
```

## Scopes reconnus

Les scopes attendus sont ciblés par famille d’usage :

| Scope | Usage |
|---|---|
| `content:read` | Routes, langues et contenus publiés. |
| `media:read` | Médias publics prêts et validés. |
| `search:read` | Recherche publique. |
| `menus:read` | Menus publics actifs. |
| `taxonomies:read` | Taxonomies et termes publics actifs. |
| `headless:read` | Alias de compatibilité lecture headless. |

Un token doit être traité comme un secret applicatif. Il ne doit pas être exposé dans un dépôt Git public ni injecté dans un bundle front-end public lorsque la sécurité attend une confidentialité stricte. Pour un site statique public, utilisez seulement un token prévu pour cet usage ou un proxy serveur.

## Paramètres `site`, `site_id`, `lang`

L’authentification ne remplace pas le contexte multisite/multilingue. En sous-site, l’URL publique peut déjà contenir le chemin du sous-site, mais le paramètre `site` reste utile pour rendre l’appel explicite :

- `site` cible un site par clé publique ;
- `site_id` cible un site par identifiant technique et prend le dessus sur `site` ;
- `lang` demande la langue de réponse.

```js
const response = await fetch('https://example.com/mod/site_a/api/v1/menus/main?site=site_a&lang=fr', {
  headers: { 'Authorization': 'Bearer <token>' }
});
```

## Réponses d’erreur liées à l’accès

Les erreurs sont normalisées. Selon la configuration, un token absent, invalide ou insuffisant peut produire une réponse JSON avec un objet `error`. Voir aussi [`errors.md`](./errors.md).
