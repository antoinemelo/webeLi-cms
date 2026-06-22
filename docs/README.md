---
title: Documentation du DEC CMS
audience:
  - editor
  - publisher
  - seo
  - administrator
  - superadministrator
  - installer
  - api-integrator
  - developer
  - evaluator
status: stable
last_verified: 2026-06-22
source_of_truth: manual
source_paths:
  - README.md
  - docs

owners:
  - core
document_type: guide
generated: false
---
# Documentation du DEC CMS

La documentation est organisée par tâche et par public. Les concepts et procédures sont rédigés manuellement. Les inventaires qui dérivent du code sont générés dans [`reference/generated/`](reference/generated/README.md) et ne doivent pas être recopiés ailleurs.

## Accès depuis le back-office

Dans le back-office, chaque utilisateur authentifié consulte toute la documentation depuis le menu principal **Actifs > Docs**. Aucun rôle, aucune permission et aucune affectation de site ne filtre le catalogue. Le viewer expose les pages Markdown ainsi que les références JSON, YAML, HTML source et texte présentes sous `docs/`. Les liens documentaires internes sont résolus vers leur cible indexée, y compris lorsqu’un lien relatif est converti par le viewer en identifiant technique.

## Espaces documentaires

| Besoin | Espace canonique |
|---|---|
| Découvrir et installer localement | [Prise en main](getting-started/README.md) |
| Créer, réviser et publier | [Guide utilisateur](user-guide/README.md) |
| Administrer sites, langues, rôles et modules | [Administration](administration/README.md) |
| Installer une release | [Installation](installation/README.md) |
| Exploiter, sauvegarder, déployer et diagnostiquer | [Exploitation](operations/README.md) |
| Intégrer l’API publique ou consulter les contrats internes | [API](api/README.md) |
| Développer et étendre le CMS | [Développement](development/README.md) |
| Consulter les inventaires techniques | [Référence](reference/README.md) |
| Évaluer les capacités et limites | [Évaluation](evaluation/README.md) |

## Règles de source canonique

- Une procédure n’est décrite qu’une fois ; les autres pages la référencent.
- Les commandes, routes, permissions, schémas, validateurs et contenus de release sont générés depuis le dépôt.
- L’OpenAPI public sous [`public-api/`](public-api/) est la référence des endpoints publics.
- Les contrats JSON sous [`reference/contracts/`](reference/contracts/) sont la référence des réponses de l’API administrative interne.
- Les limites connues sont regroupées dans [`evaluation/limitations.md`](evaluation/limitations.md).

## Vérification

```bash
python3 tools/cms.py docs generate
python3 tools/cms.py docs check
```

`docs check` vérifie la fraîcheur des pages générées, les liens locaux, le front matter, la navigation principale, les références vers des commandes CLI existantes, l’absence de filtrage IAM documentaire et les garde-fous du viewer du back-office. Il ne vérifie aucune formulation marketing ni phrase exacte.
