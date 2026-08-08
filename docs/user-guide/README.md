---
title: Guide utilisateur
audience:
  - editor
  - publisher
  - seo
  - administrator
  - superadministrator
status: stable
last_verified: 2026-08-04
source_of_truth: manual
owners:
  - core
document_type: guide
generated: false
---
# Guide utilisateur

Ce parcours suit la vie réelle d’un contenu : choisir le bon contexte, préparer les informations, construire la page, la relire, la publier puis la maintenir. Vous pouvez le lire dans l’ordre lors d’une première prise en main ou ouvrir directement l’étape qui correspond à votre besoin.

> Les menus et boutons visibles dépendent du site actif, des modules installés et de vos droits. Avant de conclure qu’une fonction manque, vérifiez le contexte affiché en haut du back-office.

## 1. Prendre ses repères

Commencez par [Connexion, profil et contexte](getting-started/sign-in-profile-context.md). Vous y apprendrez à reconnaître le site et la langue actifs, à changer de contexte sans modifier le mauvais contenu et à comprendre pourquoi certaines actions peuvent être absentes.

Pour renforcer votre compte, consultez [Sécurité du compte et authentification à deux facteurs](getting-started/account-security-2fa.md). Pour retrouver un autre guide sans quitter le back-office, ouvrez [Consulter la documentation](getting-started/backoffice-docs.md).

## 2. Préparer un contenu

Avant de saisir une page, réunissez son titre, son objectif, les médias autorisés, la langue cible et la date de publication. Un contenu bien préparé réduit les corrections de structure et les changements d’URL tardifs.

- [Créer, modifier et prévisualiser](content/create-edit.md) explique le parcours principal.
- [Champs, fieldsets et blocs](content/fields-and-blocks.md) explique ce que les différents contrôles attendent.
- [Statuts, révisions et recherche éditoriale](content/statuses-revisions-search.md) aide à retrouver un brouillon et à comprendre quelle version est visible.

## 3. Construire la page et ses relations

Utilisez [l’éditeur visuel](content/visual-editor.md) lorsque vous avez besoin de vérifier le résultat dans son contexte réel. Le modèle de contenu reste la structure de référence ; l’aperçu montre comment cette structure sera rendue.

Complétez ensuite les éléments reliés :

- [Bibliothèque de médias](media/library.md) pour choisir, téléverser et renseigner une image ou un document ;
- [Variantes, usages et suppression sûre](media/variants-usage-safe-deletion.md) avant de remplacer ou supprimer un média déjà utilisé ;
- [Menus](navigation-taxonomies/menus.md) pour rendre la page accessible dans la navigation ;
- [Taxonomies](navigation-taxonomies/taxonomies.md) pour classer et relier les contenus ;
- [Recherche](search/use-search.md) pour vérifier qu’un contenu est retrouvable dans le back-office.

## 4. Relire et publier

La publication ne consiste pas seulement à appuyer sur un bouton. Contrôlez la bonne langue, l’URL, les liens, les médias, l’affichage mobile et la date souhaitée.

1. Suivez [Réviser et publier](publication/review-publish.md).
2. Vérifiez la page publique dans une session où vous n’êtes pas connecté.
3. Si la page ne doit plus être visible, choisissez l’action adaptée dans [Dépublier, archiver, supprimer ou restaurer](publication/unpublish-archive-delete-restore.md). Ces actions n’ont pas les mêmes conséquences.

## 5. Améliorer le référencement et les services

Le [workflow SEO](seo/seo-workflow.md) fournit une relecture progressive. Le guide [URLs, canonical, hreflang, robots et données structurées](seo/urls-canonical-robots-structured-data.md) détaille les réglages qui influencent les moteurs de recherche. Utilisez [Audit, redirections et dépannage SEO](seo/audit-redirects-troubleshooting.md) lorsqu’une URL change ou qu’une alerte apparaît.

Pour les fonctions complémentaires :

- [Formulaires, recherche, imports, exports et IA](forms-cookies/forms-search-import-export-ai.md) ;
- [Consentement cookies](forms-cookies/cookie-consent.md) ;
- [Utiliser l’assistant IA](ai-assistant/use.md).

## 6. Vérifier le résultat

Une tâche éditoriale est terminée lorsque vous pouvez répondre oui à ces questions :

- Le bon site et la bonne langue ont-ils été modifiés ?
- Le contenu enregistré apparaît-il avec le statut prévu ?
- La prévisualisation correspond-elle au rendu public ?
- Les liens, médias, menus et métadonnées sont-ils cohérents ?
- Une autre personne peut-elle relire ou reprendre le travail grâce au libellé et à l’historique ?

Si un contrôle échoue, ouvrez [Dépannage utilisateur](troubleshooting.md). Notez l’action effectuée, le contexte actif et le message complet avant de demander de l’aide.

## Aller plus loin

Les produits, contacts, offres et commandes sont regroupés dans [Catalogue, clients et ventes](../business/README.md). Les réglages qui affectent toute l’installation se trouvent dans [Configurer et administrer](../administration/README.md).
