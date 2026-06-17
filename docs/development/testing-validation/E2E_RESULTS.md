---
title: Résultats E2E
audience:
  - developer
  - evaluator
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: manual
owners:
  - core
document_type: reference
generated: false
---
# Résultats des tests fonctionnels et E2E

## Commandes exécutées

```bash
php tools/php/tests/run.php
/usr/bin/python3 -m unittest discover -s tools/python/tests -p 'test*.py'
cd frontend/admin-vue && npm run build
cd frontend/admin-vue && npx playwright test --list
```

## Résultats dans l’environnement de préparation

- Suite unitaire PHP : réussie, 6 assertions.
- Suites d’intégration PHP SQLite : enregistrées mais ignorées localement car l’extension `pdo_sqlite` du PHP CLI n’est pas installée dans l’environnement de préparation. Elles s’exécutent automatiquement lorsque cette extension est disponible.
- Tests Python : 27 réussis, 1 test existant ignoré.
- Build Vue/TypeScript : réussi.
- Playwright : 2 scénarios découverts et compilés.
- Exécution navigateur complète : non lancée, faute de serveur CMS de test et d’identifiants `E2E_ADMIN_EMAIL` / `E2E_ADMIN_PASSWORD`.

## Exécution recommandée

```bash
/usr/bin/python3 tools/cms.py test
E2E_BASE_URL=http://127.0.0.1:8080 \
E2E_ADMIN_EMAIL=e2e-admin@example.test \
E2E_ADMIN_PASSWORD='...' \
E2E_WEBHOOK_URL=http://127.0.0.1:9876/webhook \
/usr/bin/python3 tools/cms.py test --e2e
```

## Limites connues

Le test navigateur webhook suppose que l’interface expose des attributs sémantiques stables `data-delivery-id` et `data-delivery-status`. S’ils ne sont pas encore rendus, le test échouera utilement et l’interface devra les ajouter plutôt que de revenir à des sélecteurs CSS fragiles.
