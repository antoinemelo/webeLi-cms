---
title: Architecture du CMS
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
# Architecture du CMS

## Composants

- runtime public PHP avec rendu Twig ;
- back-office Vue/TypeScript ;
- bases SQLite séparées par domaine ;
- blueprints et champs natifs ;
- séparation entre brouillons, révisions et projections publiées ;
- API publique versionnée et API administrative interne ;
- outillage Python pour reconstruction, validation, documentation, sauvegarde et release.

## Cycle d’une requête

Une requête entre par les routes PHP, reçoit un contexte de site et de langue, passe par l’authentification et l’autorisation lorsque nécessaire, appelle les services/repositories, puis produit une réponse HTML ou JSON. Les pages publiques doivent lire les projections publiées plutôt que les brouillons.

## Cycle de publication

1. l’éditeur enregistre un brouillon et une révision ;
2. les règles de workflow et permissions autorisent ou refusent la publication ;
3. la publication reconstruit les projections concernées ;
4. les routes publiques, le SEO, la recherche et l’export statique lisent l’état publié ;
5. dépublication et archivage retirent l’élément des surfaces publiques.

## Frontières à préserver

- aucune autorisation ne dépend uniquement du front-end ;
- aucune donnée d’un site ne doit fuiter vers un autre ;
- les langues et fallbacks sont explicites ;
- les contrats API publics sont générés et vérifiés ;
- les bases doivent pouvoir être reconstruites depuis les schémas et seeds ;
- les opérations longues ou destructrices passent par `tools/cms.py`.

## Documents spécialisés

- [Métadonnées d’affichage des articles](article-display-metadata.md)
- [Formulaires comme module système](forms-as-system-module.md)
- [Gouvernance des modules](module-admin-governance.md)
- [Blueprints de modules](module-blueprints.md)
- [Application API publique](public-api-application.md)
- [Architecture du back-office](../backoffice/architecture.md)
- [Éditeur visuel](../backoffice/visual-editor.md)
- [Bases et transactions](../database/README.md)
