---
title: Dépôt source et release packagée
audience:
  - installer
  - administrator
  - superadministrator
  - developer
  - evaluator
status: stable
last_verified: 2026-07-11
source_of_truth: procedure
source_paths:
  - docs/operations/client-instance-update.md
  - tools/python/qualification/run_all.py
  - docs/reference/generated/release-contents.md
owners:
  - core
document_type: guide
generated: false
---
# Dépôt source et release packagée

Le dépôt source sert au développement, à la génération et à la validation. Une release packagée sert à installer ou mettre à jour une instance client avec un contenu contrôlé.

| Sujet | Dépôt source | Release packagée |
|---|---|---|
| Dépendances | Peut contenir outils de développement, tests et sources front-end. | Ne doit contenir que le runtime requis et les assets construits. |
| Documentation | Inclut sources manuelles, références générées et contrats. | Inclut la documentation utile à l’exploitation et à l’intégration. |
| Bases SQLite | Peuvent être reconstruites from scratch en développement. | Données client protégées ; appliquer sauvegarde puis migrations. |
| Assets admin | Construits par `npm run build`. | Déjà construits dans `admin-app/`. |
| Validation | `docs generate`, `docs check`, `validate`, `test`, `e2e`. | Smoke, migration plan, backup/restore et contrôles de santé. |

## Avant diffusion

```bash
python3 tools/cms.py docs generate
python3 tools/cms.py docs check
python3 tools/cms.py validate --full --with-slow
python3 tools/cms.py release --ci
```

La page générée [Contenu de release](../reference/generated/release-contents.md) décrit les règles de contenu vérifiées par le dépôt. Elle ne remplace pas une revue humaine des secrets, fichiers temporaires et données client.
