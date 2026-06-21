---
title: Consulter la documentation dans le back-office
audience:
  - editor
  - publisher
  - seo
  - administrator
  - superadministrator
status: stable
version: 1.1
last_verified: 2026-06-21
source_of_truth: manual
source_paths:
  - docs
  - backend/src/Application/Api/Admin/DocsApiController.php
  - frontend/admin-vue/src/views/assets/DocsView.vue
owners:
  - core
document_type: procedure
generated: false
---
# Consulter la documentation dans le back-office

## Résultat attendu

Chaque profil accède depuis le menu principal aux documents utiles pour son rôle, sans liens dispersés dans les panneaux individuels.

## Accès

1. Ouvrez le back-office.
2. Dans le menu principal, choisissez **Actifs**.
3. Ouvrez **Docs**.
4. Utilisez la ligne de recherche pour filtrer par texte et par espace documentaire.

## Règle d’affichage

La liste est filtrée côté serveur selon les permissions du profil connecté. Un document non autorisé est caché, pas affiché en grisé.

| Espace documentaire | Profil principalement concerné |
|---|---|
| Découvrir et installer localement | Admin, Superadmin |
| Créer, réviser et publier | Éditeur, Publication, SEO, Admin, Superadmin |
| Administrer sites, langues, rôles et modules | Admin, Superadmin |
| Installer une release | Superadmin |
| Exploiter, sauvegarder, déployer et diagnostiquer | Admin, Superadmin |
| Intégrer l’API publique ou consulter les contrats internes | Admin, Superadmin |
| Développer et étendre le CMS | Superadmin |
| Consulter les inventaires techniques | Superadmin |
| Évaluer les capacités et limites | Admin, Superadmin |

## Exception de sécurité

L’index `docs/user-guide/README.md` est réservé aux superadmins, car il agrège des liens vers des procédures dont toutes les cibles ne sont pas visibles pour les profils éditoriaux. Les sous-pages utiles du guide utilisateur restent accessibles directement dans **Actifs > Docs** selon les permissions du profil.

## Lecture Markdown

Les fichiers Markdown sont rendus dans un viewer dédié. Les liens internes vers d’autres fichiers Markdown restent navigables lorsque le document cible est aussi autorisé pour le profil connecté.

## Limites

Les documents non Markdown, comme certains contrats JSON ou fichiers OpenAPI, restent des sources techniques du dépôt. Le viewer du back-office se concentre sur les pages Markdown lisibles par les utilisateurs.
