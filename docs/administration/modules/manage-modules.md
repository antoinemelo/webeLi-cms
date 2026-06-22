---
title: Administrer les modules
audience:
  - administrator
  - superadministrator
status: stable
version: 1.1
last_verified: 2026-06-20
source_of_truth: code
owners:
  - operations
  - core
document_type: guide
source_paths:
  - backend/src/Modules
  - backend/src/Module
  - backend/config/modules.php
  - ops/modules.local.json.example
  - local/modules
  - tools/python/validation/operations/module_manifests.py
generated: false
---

# Administrer les modules

Les modules déclarent un provider, des routes, ressources, permissions, blueprints et éventuelles tables. Les modules système sont livrés avec le noyau. Les modules clients doivent rester propres à l'instance.

## Types de modules

| Type | Emplacement | Mise à jour avec le noyau | Exemple |
|---|---|---:|---|
| `system` | `backend/src/Modules/` | Oui | Forms, AI Assistant |
| `client` | `local/modules/` | Non | Réservation, catalogue, intégration métier spécifique |

Le fichier `module.json` décrit le module. Il ne signifie pas que le module est installé ni activé automatiquement.

## Modules système

Les modules Forms et AI Assistant sont présents dans cette version. Leur simple présence ne signifie pas qu'un fournisseur externe ou un transport est configuré.

Le noyau conserve la compatibilité avec `backend/config/modules.php`, tout en lisant les manifestes système suivants :

```text
backend/src/Modules/Forms/module.json
backend/src/Modules/AiAssistant/module.json
```

## Modules clients

Un module client doit être installé sous :

```text
local/modules/<module-key>/
```

Puis déclaré explicitement dans le fichier d'instance :

```text
ops/modules.local.json
```

Copiez `ops/modules.local.json.example` pour démarrer. Ce fichier réel n'est pas destiné à être livré dans une release standard du noyau.

## Mise à jour du noyau

L'outil de déploiement fichiers protège :

```text
local/modules/
ops/modules.local.json
```

Ainsi, une mise à jour du core peut remplacer `backend/src/Modules/` et le reste du noyau sans écraser les modules clients locaux.

## Procédure conseillée

1. Vérifiez le manifeste `module.json`.
2. Vérifiez que les fichiers clients restent sous `local/modules/`.
3. Déclarez le module local dans `ops/modules.local.json`.
4. Installez ou activez le module avec les outils existants de l'instance.
5. Exécutez `python3 tools/cms.py validate`.
6. Avant une mise à jour du noyau, vérifiez le plan de déploiement fichiers.

## Contrôles

La commande suivante doit rester verte :

```bash
python3 tools/cms.py validate --validator MODULE_MANIFESTS
```

Elle vérifie les manifestes système, les déclarations locales et la protection des chemins clients pendant les mises à jour fichiers.
