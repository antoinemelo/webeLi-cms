---
title: Ajouter un module ou un type de contenu
audience:
  - developer
status: stable
version: 1.1
last_verified: 2026-06-20
source_of_truth: manual
owners:
  - documentation
  - core
document_type: guide
source_paths:
  - backend/src/Module
  - backend/src/Modules
  - backend/config/modules.php
  - ops/modules.local.json.example
  - local/modules
generated: false
---

# Ajouter un module ou un type de contenu

webeLi distingue le noyau, les modules système livrés avec le noyau et les modules clients conservés localement. Le but est de permettre une mise à jour du core sans écraser un développement spécifique.

## Contrat minimal d'un module

Un module reste porté par un provider PHP qui implémente `App\Module\ModuleProvider`. Le fichier `module.json` ajoute une déclaration lisible par PHP et par les outils Python. Il ne déclenche pas d'installation automatique.

Champs minimaux attendus :

```json
{
  "schema_version": 1,
  "key": "forms",
  "name": "Formulaires",
  "version": "1.0.0",
  "type": "system",
  "provider_class": "App\\Modules\\Forms\\FormsModuleProvider",
  "provider_file": "backend/src/Modules/Forms/FormsModuleProvider.php",
  "enabled_by_default": true,
  "databases": [
    {
      "key": "forms",
      "path": "storage/database/forms.sqlite",
      "schema": "database/modules/forms.sql",
      "migrations": "database/migrations/forms"
    }
  ],
  "protected_on_core_update": false
}
```

`type` vaut `system` pour un module livré avec le noyau et `client` pour un module propre à une instance.

## Modules système

Les modules système résident dans `backend/src/Modules/<Module>/`. Leur manifeste est versionné avec le noyau, par exemple :

```text
backend/src/Modules/Forms/module.json
backend/src/Modules/AiAssistant/module.json
```

Leur provider peut encore être listé dans `backend/config/modules.php`. Cette compatibilité est volontaire : le manifeste formalise le contrat, mais ne remplace pas brutalement la configuration existante.

## Modules clients

Un module client doit rester hors noyau :

```text
local/modules/<module-key>/module.json
local/modules/<module-key>/src/...
local/modules/<module-key>/database/schema.sql
local/modules/<module-key>/database/migrations/*.sql
```

Il est déclaré explicitement dans `ops/modules.local.json`, sur le modèle de `ops/modules.local.json.example` :

```json
{
  "schema_version": 1,
  "modules": [
    {
      "key": "client-example",
      "manifest": "local/modules/client-example/module.json",
      "enabled": false
    }
  ]
}
```

La présence d'un manifeste local ne suffit pas à installer le module. L'activation reste contrôlée par l'instance et par les tables existantes `modules`, `module_databases`, `module_routes`, `module_blueprints` et `module_lifecycle_events`.

## Règles de prudence

- Ne modifiez pas le noyau pour un besoin client : créez un module sous `local/modules/`.
- Ne placez pas de provider client dans `backend/src/Modules`.
- Ne modifiez pas une migration déjà appliquée ; ajoutez une migration corrective.
- Ne liez pas le noyau à un module client.
- Avant livraison, exécutez `python3 tools/cms.py validate`.

Le validateur `MODULE_MANIFESTS` vérifie les manifestes système, la convention `local/modules/` et la protection des modules clients pendant les mises à jour fichiers.
