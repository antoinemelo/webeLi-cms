---
title: Modules système et modules clients
audience:
  - developer
  - installer
  - evaluator
status: stable
version: 1.0
last_verified: 2026-06-20
source_of_truth: procedure
source_paths:
  - backend/src/Module
  - backend/src/Modules/Forms/module.json
  - backend/src/Modules/AiAssistant/module.json
  - ops/modules.local.json.example
  - examples/modules/client-notes/module.json
owners:
  - core
  - documentation
document_type: guide
permissions: []
generated: false
---
# Modules système et modules clients

webeLi distingue trois niveaux afin de pouvoir mettre à jour le noyau sans écraser un développement propre à une instance.

| Élément | Rôle | Emplacement |
|---|---|---|
| Noyau CMS | Routage, édition, publication, SEO, sécurité, outils locaux | `backend/`, `frontend/`, `tools/`, `database/` |
| Module système | Fonction officielle livrée avec le noyau | `backend/src/Modules/<Module>/` |
| Module client local | Fonction propre à une instance ou à un client | `local/modules/<module-key>/` |

## Déclaration minimale

Un module est décrit par un `module.json` lisible par PHP et par les outils Python. Le manifeste ne déclenche pas une installation automatique.

Champs obligatoires :

```json
{
  "schema_version": 1,
  "key": "client-example",
  "name": "Module client exemple",
  "version": "0.1.0",
  "type": "client",
  "provider_class": "ClientModules\\Example\\ExampleModuleProvider",
  "provider_file": "local/modules/client-example/src/ExampleModuleProvider.php",
  "enabled_by_default": false,
  "databases": [
    {
      "key": "client_example",
      "path": "storage/database/client_example.sqlite",
      "schema": "local/modules/client-example/database/schema.sql",
      "migrations": "local/modules/client-example/database/migrations"
    }
  ],
  "protected_on_core_update": true
}
```

## Activation locale

Un module client est activé par l’instance dans `ops/modules.local.json`. Ce fichier n’est pas livré comme configuration globale et doit rester protégé pendant les mises à jour du noyau.

```json
{
  "schema_version": 1,
  "modules": [
    {
      "manifest": "local/modules/client-example/module.json",
      "enabled": true
    }
  ]
}
```

## Sauvegarde et migrations

Les outils Python lisent les manifestes JSON, sans exécuter de code PHP de module. Une base déclarée par un module client activé est intégrée à l’inventaire local si elle reste sous `storage/database/`.

```bash
python3 tools/cms.py backup --output storage/backups/client-check.zip
python3 tools/cms.py migrate --module client-example --plan
python3 tools/cms.py migrate --module client-example --apply --backup --yes
```

Le mode `--plan` doit rester non mutatif. Le mode `--apply` exige `--backup` ou l’option longue de risque explicite.

## Mise à jour du noyau

Le flux local de mise à jour protège `local/modules/` et `ops/modules.local.json` :

```bash
python3 tools/cms.py instance update --source /chemin/release.zip --target /chemin/site-client --plan
python3 tools/cms.py instance update --source /chemin/release.zip --target /chemin/site-client --apply --backup --yes
```

Les modules système dans `backend/src/Modules/` appartiennent à la release du noyau. Les modules clients doivent rester dans `local/modules/`.

## Matrice de décision

| Besoin | Où le traiter ? |
|---|---|
| Fonction commune à tous les sites | noyau ou module système |
| Fonction présente chez quelques clients | module produit optionnel |
| Fonction spécifique à un client | `local/modules/<client-module>` |
| Données éditoriales | `core.sqlite` |
| Données métier module | base dédiée du module |
| Mise à jour core | `tools/cms.py instance update` |
| Retour arrière | restauration backup + rollback fichiers |

## Exemple non activé

`examples/modules/client-notes/` montre la structure attendue d’un module client minimal. Il ne doit pas être activé tel quel en production ; copiez-le sous `local/modules/` et adaptez les clés, chemins, permissions et migrations.
