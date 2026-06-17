---
title: Documentation développeur
audience:
  - developer
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: manual
owners:
  - core
document_type: guide
generated: false
---
# Documentation développeur

## Commencer

- [Environnement de développement](getting-started.md)
- [Architecture](architecture/README.md)
- [CLI et outils Python](python-tooling/cli.md)
- [Tests et validations](testing-validation/README.md)
- [Base de données](database/README.md)

## Étendre

Le [guide d’extension](extending/README.md) définit la procédure commune. Les fiches spécialisées couvrent :

- [route ou endpoint](extending/route-endpoint.md) ;
- [module, type de contenu ou blueprint](extending/module-content-type.md) ;
- [champ ou bloc](extending/field-block.md) ;
- [table, repository ou service](extending/database-repository.md) ;
- [commande, validateur ou test](extending/command-validator.md) ;
- écran du back-office : suivre [l’architecture du back-office](backoffice/architecture.md) et le guide commun.

## Références dérivées du code

Ne recopiez pas manuellement les listes de routes, permissions, modules, commandes ou validateurs. Utilisez [la référence générée](../reference/generated/README.md).

## Documentation

Les règles de contribution sont dans [Contribuer à la documentation](contributing-documentation.md). Toute nouvelle page doit avoir une source canonique claire et ne pas dupliquer une procédure existante.
