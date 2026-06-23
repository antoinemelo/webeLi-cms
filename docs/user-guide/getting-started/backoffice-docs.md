---
title: Consulter la documentation dans le back-office
audience:
  - editor
  - publisher
  - seo
  - administrator
  - superadministrator
status: stable
last_verified: 2026-06-23
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

Chaque utilisateur authentifié accède depuis le menu principal à l’intégralité de la documentation, sans filtre lié au rôle, aux permissions ou aux sites affectés.

## Accès

1. Ouvrez le back-office.
2. Dans le menu principal, choisissez **Actifs**.
3. Ouvrez **Docs**.
4. Utilisez les onglets de profils pour afficher les pages utiles à un type d’usage : rédaction, publication, SEO, administration, installation, API, développement ou évaluation.
5. Utilisez la ligne de recherche et le filtre d’espace documentaire pour affiner la liste affichée.

## Règle d’accès

La documentation est globale à l’installation. Une session valide du back-office suffit. Le backend indexe toutes les sources documentaires prises en charge sous `docs/` et renvoie le même catalogue à chaque utilisateur.

Formats exposés : Markdown, JSON, YAML, HTML affiché comme code source et texte brut. Les fichiers de configuration serveur tels que `.htaccess` ne sont pas des documents et ne sont pas indexés.

Les onglets de profils sont uniquement des filtres d’affichage. Ils ne représentent pas des droits d’accès et ne changent pas le catalogue renvoyé par le backend. L’onglet **Tous** reste disponible pour consulter l’intégralité de la documentation. Une page qui déclare plusieurs audiences apparaît dans chaque onglet concerné.

## Lecture Markdown

Les fichiers Markdown sont rendus dans un viewer dédié. Les liens internes vers toute source documentaire indexée sont résolus côté serveur et côté interface vers un identifiant canonique. Un lien relatif, par exemple `content/create-edit.md`, ouvre donc directement sa cible. Le serveur accepte aussi les variantes générées par le viewer (`#docs/<id>`, chemin `docs/...`, identifiant avec `~`) lorsqu’elles correspondent à un document indexé.

Les sources non Markdown sont échappées et rendues dans un bloc de code : aucun HTML documentaire n’est exécuté dans le back-office.
