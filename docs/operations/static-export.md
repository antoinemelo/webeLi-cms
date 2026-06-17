---
title: Export statique éditorial
audience:
  - operator
  - administrator
status: experimental
version: 1.0
last_verified: 2026-06-14
source_of_truth: procedure
source_paths:
  - tools/python/operations
  - tools/python/qualification/run_all.py

owners:
  - operations
  - documentation
document_type: guide
generated: false
---

# Export statique éditorial

L’export statique se prépare depuis **Production éditoriale > Imports / Exports**. Il peut être lancé comme export manuel lorsque les routes, contenus publiés, médias et dépendances du site sont cohérents.

Le processus doit couvrir le cycle éditorial, les routes publiques, les médias, le SEO, les webhooks éventuels, le rollback et les contrôles de sécurité. Le répertoire `storage/exports/` ne doit jamais être publié directement ; seul l’artefact explicitement préparé et vérifié peut être déployé.

Les commandes disponibles et leurs options sont décrites dans la référence CLI générée. Une fonctionnalité ou option non présente dans la commande réelle ne doit pas être annoncée ici.
