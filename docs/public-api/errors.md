---
title: Erreurs API headless v1
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

# Erreurs API headless v1

Les erreurs publiques headless v1 sont normalisées et documentées dans le schéma `PublicApiError` du contrat [`openapi.v1.json`](./openapi.v1.json). Elles ne doivent pas exposer de trace technique.

## Forme générale

```json
{
  "error": {
    "code": "ROUTE_NOT_FOUND",
    "message": "Route not found.",
    "details": {}
  }
}
```

Selon l’erreur, `details` peut contenir des informations de validation par champ.

## Cas fréquents

| Statut | Exemple de code | Sens |
|---|---|---|
| `404` | `ROUTE_NOT_FOUND` | Aucun chemin public actif ne correspond. |
| `404` | `PUBLIC_CONTENT_NOT_FOUND` | Aucun contenu publié ne correspond au type, slug ou chemin demandé. |
| `404` | `MEDIA_NOT_FOUND` | Le média n’existe pas, n’est pas prêt, n’est pas valide ou n’est pas disponible pour le site. |
| `404` | `MENU_NOT_FOUND` | Le menu demandé est absent ou inactif. |
| `404` | `TAXONOMY_NOT_FOUND` | La taxonomie demandée est absente ou inactive. |
| `410` | `PUBLIC_CONTENT_NOT_FOUND` | La route existe, mais le contenu publié associé a été retiré. |
| `422` | `VALIDATION_FAILED` | Un paramètre `site`, `site_id`, `lang`, `path`, `type`, `slug`, `limit`, `offset` ou équivalent est invalide. |

## Gestion côté front-end

```js
async function requestJson(url, token) {
  const response = await fetch(url, {
    headers: {
      'Accept': 'application/json',
      'Authorization': `Bearer ${token}`
    }
  });

  const payload = await response.json();

  if (response.status === 404) {
    return { notFound: true, payload };
  }

  if (!response.ok) {
    return { error: payload.error || { code: 'UNKNOWN_ERROR' } };
  }

  return { data: payload.data, meta: payload.meta };
}
```

## Bonnes pratiques

- Traiter `404` comme un état fonctionnel possible dans un front headless.
- Afficher une page 404 côté front si `GET /api/v1/route` retourne `ROUTE_NOT_FOUND`.
- Ne pas déduire l’existence de brouillons depuis une erreur publique : l’API ne retourne que des ressources publiées.
