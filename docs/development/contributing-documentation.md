---
title: Contribuer à la documentation
audience:
  - developer
  - documentation-maintainer
status: stable
last_verified: 2026-06-22
source_of_truth: manual
owners:
  - documentation
document_type: guide
source_paths:
  - docs
generated: false
---

# Contribuer à la documentation

Les guides sont orientés tâches et les références dérivables sont générées. Ajoutez le front matter obligatoire, une source de vérité, des liens relatifs et une date de vérification. Ne rattachez jamais une page à une version documentaire ou à un identifiant de release précis : la documentation décrit l’état maintenu du produit. N’ajoutez pas non plus de métadonnée de permission : toute source documentaire sous `docs/` est destinée à tous les utilisateurs authentifiés du back-office. Exécutez `python3 tools/cms.py docs generate`, `python3 tools/cms.py docs check` et le validateur c26.
