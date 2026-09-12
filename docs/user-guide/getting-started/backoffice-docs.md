---
title: Consulter la documentation dans le back-office
audience:
  - editor
  - publisher
  - seo
  - administrator
  - superadministrator
status: stable
last_verified: 2026-08-04
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

Vous trouvez une procédure à partir de votre objectif, puis vous la lisez sans être encombré par les inventaires techniques. Toute la documentation reste accessible à chaque utilisateur authentifié.

## Ouvrir et parcourir les guides

1. Ouvrez le back-office.
2. Choisissez **Actifs > Docs** dans le menu principal.
3. Sélectionnez l’un des six parcours selon ce que vous voulez accomplir : utiliser, gérer l’activité, configurer, exploiter, développer ou vérifier.
4. Dépliez une rubrique dans la colonne de gauche, puis choisissez une page. Les résumés permettent de distinguer les guides proches.
5. Dans une page longue, utilisez **Sur cette page** pour atteindre une section. Les boutons en bas poursuivent vers la page précédente ou suivante du parcours.

## Rechercher efficacement

La recherche porte sur tous les parcours, même si un seul est sélectionné. Utilisez des mots qui décrivent l’action ou le problème : « publier », « image produit », « restaurer », « session » ou « webhook ».

Le filtre de profil réduit la liste aux pages les plus pertinentes pour la rédaction, la publication, l’administration, l’installation, l’API ou le développement. Il s’agit d’une aide à la lecture, pas d’un droit d’accès. Revenez à **Tous** si un résultat attendu n’apparaît pas.

## Comprendre une page

Le titre et le résumé expliquent le but de la page. Les guides fonctionnels présentent normalement le résultat attendu, les prérequis, les étapes, les vérifications et les pistes de dépannage.

Le bloc **Informations sur cette page** est replié par défaut. Il contient le statut documentaire, la date de vérification et le fichier source ; ces informations servent surtout aux personnes qui maintiennent ou auditent la documentation.

## Règle d’accès

La documentation est globale à l’installation. Une session valide suffit : le rôle, les permissions et les sites affectés ne retirent aucune page du catalogue. En revanche, les fonctions décrites dans les guides restent soumises aux droits normaux du back-office.

## Références techniques

Les parcours Développer et Vérifier exposent aussi des références Markdown, JSON, YAML, HTML source et texte brut. Les sources non Markdown sont affichées comme du code inerte : elles ne sont jamais exécutées dans le lecteur. Les fichiers de configuration serveur tels que `.htaccess` ne sont pas indexés comme documents.

Pour une première visite, ouvrez [Choisir son parcours](../../getting-started/choose-your-path.md). Les détails d’indexation et de résolution des liens sont réservés aux références de développement afin de ne pas alourdir l’utilisation quotidienne.
