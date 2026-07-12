---
title: Scénarios vérifiables
audience:
  - editor
  - publisher
  - seo
  - catalog-manager
  - sales-operator
  - administrator
  - superadministrator
  - installer
  - developer
  - evaluator
status: stable
last_verified: 2026-07-11
source_of_truth: procedure
source_paths:
  - frontend/admin-vue/tests/e2e
  - tools/python/qualification/run_all.py
  - tools/python/validation
owners:
  - core
  - documentation
document_type: guide
generated: false
---
# Scénarios vérifiables

Les scénarios ci-dessous indiquent l’état démontré par le dépôt. Lorsqu’un flux n’a pas encore de scénario navigateur complet, il est décrit comme vérifiable par commande ou par API, pas comme stable de bout en bout.

| Identifiant | Scénario | Statut | Preuve ou commande |
|---|---|---|---|
| `content-page-publish` | Créer, prévisualiser, publier et lire une page. | Démontré par E2E | `python3 tools/cms.py e2e --use-built-assets` |
| `content-article-publish` | Créer, prévisualiser, publier et lire un article. | Démontré par E2E | `python3 tools/cms.py e2e --use-built-assets` |
| `catalog-product-create` | Créer un produit catalogue/PIM. | Vérifiable par tests module et contrats API, UX complète à renforcer selon le module actif. | `python3 tools/cms.py validate --full --category api --category documentation` |
| `cms-product-link` | Lier un produit à une page CMS. | Partiel : les contrats catalogue et contenus existent, la qualification E2E croisée doit rester explicite. | Voir [Limites](../evaluation/limitations.md) |
| `internal-sale` | Effectuer une vente interne. | Démontré par smoke Vente/CRM selon les fixtures isolées. | `python3 tools/cms.py e2e --use-built-assets` |
| `sale-return` | Traiter un retour. | À qualifier selon le modèle de retour activé ; ne pas présenter comme complet sans test dédié. | Voir [Vente POS](../business/vente-pos.md) |
| `public-cart` | Tester un panier public. | Contrats publics présents ; E2E invité complet à valider dans les lots M3/M4. | [Contrats Headless](../reference/contracts/headless-v1/README.md) |
| `backup-restore` | Sauvegarder et restaurer une instance. | Procédure documentée et validateur dédié. | [Sauvegarde et restauration](../operations/backup-restore.md) |

## Commandes de contrôle recommandées

```bash
python3 tools/cms.py docs generate
python3 tools/cms.py docs check
python3 tools/cms.py validate --full --category content --category documentation --category api --category operations --category shared
python3 tools/cms.py e2e --use-built-assets
```

`e2e` démarre une instance isolée et un navigateur Playwright. Si l’environnement local bloque le navigateur, relancez dans un contexte qui autorise les serveurs locaux et Chromium.
