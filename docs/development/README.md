---
title: Documentation développeur
audience:
  - developer
status: stable
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
- [Internationalisation de l’administration](admin-i18n.md) / [Admin internationalization](admin-i18n.en.md)

## Étendre

Le [guide d’extension](extending/README.md) définit la procédure commune. Les fiches spécialisées couvrent :

- [route ou endpoint](extending/route-endpoint.md) ;
- [module, type de contenu ou blueprint](extending/module-content-type.md) ;
- [champ ou bloc](extending/field-block.md) ;
- [table, repository ou service](extending/database-repository.md) ;
- [commande, validateur ou test](extending/command-validator.md) ;
- écran du back-office : suivre [l’architecture du back-office](backoffice/architecture.md), le guide commun et le contrat de l’[éditeur visuel](backoffice/visual-editor.md) lorsque l’écran interagit avec la prévisualisation.

## Back-office et rendu éditable

Le back-office combine un éditeur structuré et un éditeur visuel. Les développeurs qui modifient les templates Twig doivent préserver les attributs `data-amcms-*` nécessaires à l’édition in-context et vérifier que la sélection, l’enregistrement et la prévisualisation restent cohérents.

## Références dérivées du code

Ne recopiez pas manuellement les listes de routes, permissions, modules, commandes ou validateurs. Utilisez [la référence générée](../reference/generated/README.md).

## Documentation

Les règles de contribution sont dans [Contribuer à la documentation](contributing-documentation.md). Toute nouvelle page doit avoir une source canonique claire et ne pas dupliquer une procédure existante.
