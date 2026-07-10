---
title: Résultats E2E
audience:
  - developer
  - evaluator
status: stable
last_verified: 2026-06-22
source_of_truth: code
source_paths:
  - tools/python/operations/testing/run_playwright_e2e.py
  - frontend/admin-vue/tests/e2e/webhook-ping-persistence.spec.ts
owners:
  - core
document_type: reference
generated: false
---
# Tests fonctionnels et E2E

## Exécution autonome

```bash
cd frontend/admin-vue
npm ci
cd ../..
python3 tools/cms.py e2e --install-browser  # une fois par machine, après npm ci
python3 tools/cms.py e2e
```

Après une mise à jour de `@playwright/test`, le navigateur en cache peut ne plus correspondre au chemin attendu par Playwright. Dans ce cas, l'erreur indique un chemin sous `~/.cache/ms-playwright` et la commande à relancer est :

```bash
python3 tools/cms.py e2e --install-browser
```

La seconde commande ne demande ni serveur préexistant, ni identifiant stocké, ni URL de webhook externe. Elle :

- copie le projet dans un répertoire temporaire ;
- compile le back-office depuis les sources courantes ;
- reconstruit toutes les bases depuis les schémas et seeds natifs ;
- crée un administrateur éphémère avec un mot de passe aléatoire ;
- démarre le CMS PHP et un récepteur webhook sur des ports libres ;
- exécute Chromium puis détruit intégralement l’instance.

Les bases, comptes et fichiers de l’instance de travail ne sont jamais modifiés. `--keep-instance` conserve la copie temporaire en cas de diagnostic et `--headed` affiche Chromium.

## Parcours couverts

- connexion administrateur en deux étapes ;
- création, ping, historique persistant et suppression d’un webhook ;
- livraison réelle vers le récepteur HTTP local ;
- absence de secret en clair dans l’interface ;
- refus d’un appel direct à l’API webhook sans authentification.

Le dernier run local complet a exécuté les deux scénarios avec succès. En cas d’échec, captures, vidéo et trace sont écrites dans `frontend/admin-vue/test-results/artifacts/`.

## Cible externe facultative

Une instance déjà démarrée peut encore être testée explicitement :

```bash
E2E_BASE_URL=http://127.0.0.1:8080 \
E2E_ADMIN_EMAIL=e2e-admin@example.test \
E2E_ADMIN_PASSWORD='...' \
E2E_WEBHOOK_URL=http://127.0.0.1:9876/webhook \
python3 tools/cms.py e2e
```

Les trois premières variables doivent être fournies ensemble. Cette voie ne crée ni compte ni données et doit donc viser une instance jetable.
