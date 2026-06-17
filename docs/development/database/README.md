---
title: Bases SQLite et transactions
audience:
  - developer
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: code
owners:
  - core
document_type: guide
permissions:
source_paths:
  - database
  - backend/src
  - tools/python
  - docs/reference/generated/database-schema.md
generated: false
---
# Bases SQLite et transactions

Le CMS utilise plusieurs bases SQLite afin de séparer les responsabilités : contenu, identité et accès, recherche, médias, formulaires et assistant IA selon les modules activés. Les schémas canoniques se trouvent sous `database/schema/`, les modules sous `database/modules/` et les données initiales sous `database/seeds/`.

## Repartir de zéro

Utilisez la façade du projet plutôt que d’exécuter directement les scripts internes :

```bash
python3 tools/cms.py rebuild
python3 tools/cms.py validate
```

La reconstruction doit être lancée sur une instance de développement ou après sauvegarde. Elle recrée les bases à partir des schémas et seeds présents dans le dépôt ; aucune migration n’est nécessaire pour une installation neuve.

## Transactions entre bases

SQLite ne fournit pas une transaction distribuée entre plusieurs fichiers. Toute opération interbases doit donc :

1. définir l’ordre des écritures ;
2. journaliser l’opération ;
3. prévoir une reprise ou une compensation ;
4. rester idempotente lorsqu’elle peut être rejouée.

La table `cross_database_operations` sert au suivi de ces opérations. La référence des tables est générée dans [Schéma des bases](../../reference/generated/database-schema.md).
