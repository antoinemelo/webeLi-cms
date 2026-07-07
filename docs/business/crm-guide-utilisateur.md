---
title: Guide utilisateur Opérations CRM
audience:
  - administrator
  - superadministrator
  - publisher
status: draft
last_verified: 2026-07-07
source_of_truth: manual
source_paths:
  - frontend/admin-vue/src/views/modules/BusinessCrmView.vue
  - frontend/admin-vue/src/views/modules/BusinessRelationsView.vue
  - backend/src/Application/Api/Admin/BusinessCrmApiController.php
  - backend/src/Application/Api/Admin/BusinessMessagingApiController.php
  - backend/src/Application/Api/Admin/BusinessMailingApiController.php
  - backend/src/Application/Frontend/BusinessMemoShareController.php
owners:
  - business
document_type: guide
generated: false
---
# Guide utilisateur Opérations CRM

Ce guide resume les gestes courants dans **Opérations > Relations**. Le CRM regroupe les personnes, organisations, memos, commentaires, consentements et messages dans une interface dediee. Il ne gere pas les opportunites, devis, factures ou relances commerciales.

## Ouvrir et chercher une relation

1. Ouvrez **Modules > Opérations**, puis l'onglet **Relations**.
2. Utilisez **Recherche globale** pour chercher un nom, une organisation, un email, un telephone ou un contenu de memo.
3. Ouvrez les filtres pour limiter la liste aux personnes, organisations, prospects, clients, fournisseurs, anciens ou relations archivees.
4. Cliquez sur une relation pour ouvrir sa fiche en lecture.

Les compteurs de ligne indiquent les memos, memos partages et contacts lies. Les actions secondaires sont regroupees dans le menu `...`.

## Creer une personne

1. Cliquez sur **Nouvelle relation**.
2. Choisissez **Personne**.
3. Renseignez au minimum les informations utiles : prenom, nom, email, telephone, mobile, langue ou statut.
4. Si la personne appartient a une organisation, selectionnez-la.
5. Si aucune organisation reelle n'est selectionnee, la personne est rattachee automatiquement a l'organisation systeme `Individus`.

Le rattachement a un compte IAM est optionnel. Un compte IAM ne peut etre lie qu'a un seul contact actif.

## Creer une organisation

1. Cliquez sur **Nouvelle relation**.
2. Choisissez **Organisation**.
3. Renseignez le nom, puis les coordonnees utiles : email, telephone, site web, adresse et notes privees.
4. Choisissez un statut CRM : `prospect`, `client`, `supplier`, `former_client` ou `other`.

L'organisation systeme `Individus` est reservee aux personnes sans organisation reelle. Elle doit rester protegee et ne sert pas a representer une entreprise cliente.

## Modifier, archiver, restaurer ou supprimer

- La fiche s'ouvre d'abord en lecture.
- Utilisez **Modifier** ou l'action **Editer** du menu de ligne pour passer en mode edition.
- Archivez une relation pour la retirer des listes actives sans la supprimer.
- Une relation archivee peut etre restauree.
- La suppression est reservee aux relations deja archivees et doit rester exceptionnelle.

Une relation archivee limite les actions disponibles a la consultation, la restauration et la suppression.

## Ajouter ou consulter des memos

1. Depuis une relation, utilisez l'action **Nouveau memo** pour creer une note deja rattachee a cette relation.
2. Utilisez **Memos** pour ouvrir la liste filtree des memos de la relation.
3. Cliquez sur le titre d'un memo pour l'editer.
4. Cliquez ailleurs sur la ligne pour le selectionner sans ouvrir l'edition.

Les commentaires restent associes au memo. Les auteurs sont affiches par nom lorsque l'information IAM est disponible.

## Partager un memo

Un memo peut etre partage en interne ou par lien public.

- Partage interne : visible par des utilisateurs IAM authentifies, selon leurs droits.
- Lien public : lecture seule, token long, stocke hashe, revocable et marque `noindex,nofollow`.
- Le lien public n'expose que le memo explicitement partage.

Ne partagez pas un lien public pour transmettre une vue complete de la relation ou des donnees CRM.

## Envoyer un message

Depuis une relation, vous pouvez preparer un message si le canal et le consentement sont compatibles.

Canaux prevus :

- email ;
- WhatsApp, uniquement si un provider officiel/configure est actif ;
- Telegram, uniquement si un provider officiel/configure est actif.

L'interface signale les erreurs de consentement. Un contact `opted_out` ou sans opt-in compatible ne doit pas recevoir de message marketing.

## Retrouver les messages

Les messages sont consultables depuis la relation et les vues d'activite associees. Les listes, campagnes et messages de synthese apparaissent dans le tableau de bord Opérations lorsque les permissions correspondantes existent.

## Limites utilisateur

Le CRM Opérations reste volontairement leger. Il ne remplace pas un outil de vente complet, un helpdesk ou une comptabilite. Les champs et blueprints documentent les ressources, mais ne signifient pas qu'une interface generique remplace l'UX dediee.
