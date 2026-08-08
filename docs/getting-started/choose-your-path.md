---
title: Choisir son parcours documentaire
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
last_verified: 2026-08-04
source_of_truth: manual
source_paths:
  - docs
  - backend/src/Application/Api/Admin/DocsApiController.php
  - frontend/admin-vue/src/views/assets/DocsView.vue
owners:
  - core
document_type: guide
generated: false
---
# Choisir son parcours documentaire

Choisissez d’abord selon votre tâche. Votre intitulé de poste peut couvrir plusieurs parcours : un administrateur peut aussi publier, et une personne chargée du catalogue peut avoir besoin des guides de contenu.

| Aujourd’hui, je veux… | Parcours conseillé |
|---|---|
| Me connecter, créer une page, ajouter un média ou publier | [Guide utilisateur](../user-guide/README.md) |
| Gérer un produit, un contact ou une commande | [Catalogue, clients et ventes](../business/README.md) |
| Régler un site, une langue, un rôle ou un module | [Administration](../administration/README.md) |
| Installer, sauvegarder, mettre à jour ou dépanner | [Exploitation](../operations/README.md) |
| Utiliser une API, modifier le code ou créer une extension | [API](../api/README.md) et [Développement](../development/README.md) |
| Vérifier une capacité, une limite ou une preuve | [Évaluation](../evaluation/README.md) |

Dans **Actifs > Docs**, la recherche examine tous les parcours. Le filtre de profil ne retire aucune autorisation : il réduit seulement la liste affichée.

## Parcours conseillés selon vos responsabilités

## Éditeur

Objectif : créer, modifier et prévisualiser des contenus.

À lire :

1. [Connexion, profil et contexte](../user-guide/getting-started/sign-in-profile-context.md)
2. [Créer, modifier et prévisualiser](../user-guide/content/create-edit.md)
3. [Champs, fieldsets et blocs](../user-guide/content/fields-and-blocks.md)
4. [Bibliothèque de médias](../user-guide/media/library.md)
5. [Dépannage utilisateur](../user-guide/troubleshooting.md)

Passez au profil **Publicateur** lorsque le contenu doit être validé, publié ou restauré.

## Publicateur

Objectif : contrôler, publier, dépublier, archiver ou restaurer.

À lire :

1. [Réviser et publier](../user-guide/publication/review-publish.md)
2. [Dépublier, archiver, supprimer ou restaurer](../user-guide/publication/unpublish-archive-delete-restore.md)
3. [Statuts, révisions et recherche éditoriale](../user-guide/content/statuses-revisions-search.md)
4. [Workflow SEO](../user-guide/seo/seo-workflow.md)
5. [Recherche](../user-guide/search/use-search.md)

Passez au profil **SEO** si la décision dépend d’URLs, canonical, robots ou données structurées.

## Responsable SEO

Objectif : vérifier l’indexabilité, les métadonnées, les routes publiques et les alertes SEO.

À lire :

1. [Workflow SEO](../user-guide/seo/seo-workflow.md)
2. [URL, canonical, hreflang, robots et données structurées](../user-guide/seo/urls-canonical-robots-structured-data.md)
3. [Audit, redirections et dépannage SEO](../user-guide/seo/audit-redirects-troubleshooting.md)
4. [Menus](../user-guide/navigation-taxonomies/menus.md) et [taxonomies](../user-guide/navigation-taxonomies/taxonomies.md)
5. [API publique](../public-api/quickstart.md)

Passez au profil **Administrateur** pour changer les sites, langues, rôles ou réglages applicatifs.

## Administrateur

Objectif : administrer les contenus, sites, langues, modules et paramètres fonctionnels.

À lire :

1. [Administration](../administration/README.md)
2. [Sites et langues](../administration/sites-and-languages/manage-sites.md)
3. [Utilisateurs, rôles et permissions](../administration/users-roles-permissions/overview.md)
4. [Modules](../administration/modules/manage-modules.md)
5. [Sécurité applicative](../administration/security/tokens-cors-webhooks.md)

Passez au profil **Superadministrateur** pour les sujets IAM sensibles, sessions, 2FA ou gouvernance globale.

## Superadministrateur

Objectif : sécuriser l’instance, gérer les accès sensibles et préparer l’exploitation.

À lire :

1. [Sessions et modes de connexion](../administration/users-roles-permissions/sessions-and-2fa.md)
2. [Sécurité applicative](../administration/security/tokens-cors-webhooks.md)
3. [Checklist production](../operations/production-checklist.md)
4. [Sauvegarde et restauration](../operations/backup-restore.md)
5. [Dépannage exploitation](../operations/troubleshooting.md)

Passez au profil **Installateur** pour installer ou mettre à jour une instance.

## Installateur

Objectif : préparer l’environnement, installer une release et appliquer les mises à jour.

À lire :

1. [Prérequis](../installation/requirements.md)
2. [Installer une release](../installation/install-release.md)
3. [Configuration](../installation/configuration-reference.md)
4. [Mettre à jour une base existante](../operations/existing-database-update.md)
5. [Contrôles de santé](../operations/health-checks.md)

Passez au profil **Développeur** si vous modifiez le code, les modules ou les tests.

## Intégrateur API

Objectif : lire le contenu publié et intégrer les contrats exposés.

À lire :

1. [API](../api/README.md)
2. [Quickstart API publique](../public-api/quickstart.md)
3. [Authentification API publique](../public-api/authentication.md)
4. [Erreurs API publiques](../public-api/errors.md)
5. [Exemples API publiques](../public-api/examples.md)

Passez au profil **Développeur** pour étendre les endpoints ou modifier les contrats.

## Développeur

Objectif : comprendre l’architecture, développer, tester et étendre le CMS.

À lire :

1. [Développement](../development/README.md)
2. [Architecture](../development/architecture/README.md)
3. [Étendre le CMS](../development/extending/README.md)
4. [Tests et validation](../development/testing-validation/README.md)
5. [Référence technique](../reference/README.md)

Passez au profil **Évaluateur** pour vérifier les capacités, limites et preuves disponibles.

## Évaluateur

Objectif : contrôler factuellement l’état vérifié du CMS et ses limites connues.

À lire :

1. [Évaluation](../evaluation/README.md)
2. [Périmètre produit](../evaluation/product-scope.md)
3. [Matrice de fonctionnalités](../evaluation/feature-matrix.md)
4. [Limites connues](../evaluation/limitations.md)
5. [Index des preuves](../evaluation/evidence-index.md)

Passez au profil **Installateur** uniquement si vous devez exécuter une installation ou une mise à jour réelle.

## Lorsque plusieurs profils interviennent

Un contenu peut passer successivement entre un éditeur, une personne chargée de la validation et un responsable SEO. Utilisez les statuts et révisions pour transmettre le travail, et notez les décisions qui ne sont pas visibles dans le contenu lui-même. Un administrateur règle les accès et la structure, mais ne remplace pas la relecture fonctionnelle de chaque métier.

Pour une opération sensible, la personne qui exécute et celle qui vérifie devraient être distinctes lorsque l’organisation le permet. C’est particulièrement utile pour les publications importantes, les changements de droits, les mises à jour et les restaurations.

## Pour maintenir ou auditer la documentation

Les procédures utilisateur se trouvent dans les guides. Les routes, commandes, permissions, validateurs et schémas sont générés dans [Référence](../reference/README.md). Les endpoints publics sont décrits dans l’[OpenAPI publique](../public-api/openapi.v1.json), et les écarts connus dans [Limites connues](../evaluation/limitations.md). Cette séparation garde les explications lisibles tout en conservant une preuve technique complète.
