---
title: Bases SQLite et transactions
audience:
  - developer
status: stable
last_verified: 2026-06-14
source_of_truth: code
owners:
  - core
document_type: guide
source_paths:
  - database
  - backend/src
  - tools/python
  - docs/reference/generated/database-schema.md
generated: false
---
# Bases SQLite et transactions

Le CMS utilise plusieurs bases SQLite afin de séparer les responsabilités : contenu, identité et accès, recherche, médias, formulaires et assistant IA selon les modules activés. Les schémas canoniques se trouvent sous `database/schema/`, les modules sous `database/modules/` et les données initiales sous `database/seeds/`.

## Mettre à jour une base existante

Une base qui contient déjà des données doit être mise à jour par migrations incrémentales, jamais par reconstruction :

```bash
python3 tools/cms.py migrate --plan
python3 tools/cms.py migrate --apply --backup --yes
python3 tools/cms.py validate --category database
```

Les fichiers de migration numérotés vivent sous `database/migrations/<scope>/` ou dans les dossiers de modules déclarés. Chaque base conserve sa table `schema_migrations`; le migrateur applique uniquement les fichiers manquants et vérifie les checksums des migrations déjà appliquées.

## Repartir de zéro en développement

Utilisez la façade du projet plutôt que d’exécuter directement les scripts internes :

```bash
python3 tools/cms.py rebuild
python3 tools/cms.py validate
```

La reconstruction doit être lancée uniquement sur une instance de développement, de test ou après une décision de récupération contrôlée avec sauvegarde vérifiée. Elle recrée les bases à partir des schémas et seeds présents dans le dépôt ; elle ne sert pas à mettre à jour une installation existante avec contenu.

## Transactions entre bases

SQLite ne fournit pas une transaction distribuée entre plusieurs fichiers. Toute opération interbases doit donc :

1. définir l’ordre des écritures ;
2. journaliser l’opération ;
3. prévoir une reprise ou une compensation ;
4. rester idempotente lorsqu’elle peut être rejouée.

La table `cross_database_operations` sert au suivi de ces opérations. La référence des tables est générée dans [Schéma des bases](../../reference/generated/database-schema.md).
