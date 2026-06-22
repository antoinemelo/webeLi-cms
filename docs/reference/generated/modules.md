---
title: Modules runtime
audience:
  - developer
  - installer
  - evaluator
status: stable
source_of_truth: generated
generator: tools/python/generators/generate_documentation.py
owners:
  - core
document_type: reference
generated: true
---
# Modules runtime

> Fichier généré. Ne pas modifier directement.

| Module | Type | Version | Activé par défaut | Base(s) | Manifeste |
|---|---|---|---:|---|---|
| `ai-assistant` | `system` | `0.1.0` | oui | `ai` | `backend/src/Modules/AiAssistant/module.json` |
| `forms` | `system` | `1.0.0` | oui | `forms` | `backend/src/Modules/Forms/module.json` |
| `client-notes` | `client` | `0.1.0` | non | `client_notes` | `examples/modules/client-notes/module.json` |

Les exemples sous `examples/modules/` documentent le contrat mais ne sont pas chargés automatiquement. Les modules clients actifs doivent être copiés ou développés sous `local/modules/` puis déclarés dans `ops/modules.local.json`.
