---
title: Audit UX Business CRM
audience:
  - administrator
  - superadministrator
  - developer
status: draft
last_verified: 2026-07-06
source_of_truth: analysis
source_paths:
  - frontend/admin-vue/src/views/modules/BusinessCrmView.vue
  - backend/src/Application/Api/Admin/BusinessCrmApiController.php
  - backend/src/Application/Api/Admin/BusinessMailingApiController.php
  - backend/src/Application/Api/Admin/BusinessMessagingApiController.php
  - docs/development/architecture/business-crm-redesign-guardrails.md
owners:
  - business
document_type: audit
generated: false
---
# Audit UX Business CRM

Cet audit compare le CRM Business actuel avec les captures de reference fournies pour identifier les patterns utiles a reprendre dans DEC / webeLi, sans copier de marque, de charte graphique, de code ou de composants proprietaires.

## Synthese

Le CRM Business actuel est fonctionnel mais encore organise comme une administration de tables : onglets separes, formulaires pleine page, peu d'actions contextuelles et absence de fiche relation unifiee. La refonte doit transformer l'entree **Business** en interface de travail orientee relation.

Priorite de redesign :

1. une vue principale **Relations** qui regroupe entreprises et contacts ;
2. une liste dense avec recherche, filtres, tri et actions rapides ;
3. une fiche relation avec identite, informations cles, memos, messages, consentements et historique ;
4. des modals ou drawers pour creer, modifier, importer, exporter, ajouter un memo et envoyer un message ;
5. des surfaces preparees pour resume IA et suggestions, sans ajouter de dependance IA obligatoire.

## Constats par source

### DEC CRM actuel

Le composant `BusinessCrmView.vue` expose aujourd'hui les onglets **Entreprises**, **Contacts**, **Memos**, **Mailing**, **Messaging**, **Produits** et **Offres**. Les donnees CRM sont utilisables, mais l'utilisateur doit changer d'onglet pour reconstruire le contexte d'une relation.

Problemes principaux :

- entreprises et contacts sont separes alors que l'utilisateur cherche une relation ;
- le formulaire de creation ou modification prend beaucoup de place sous la liste ;
- les actions frequentes ne sont pas toujours presentes sur la ligne ou la fiche ;
- les memos sont une entree principale au lieu d'etre attaches au contexte relationnel ;
- l'interface laisse de grands espaces vides sur desktop ;
- l'experience mobile risque de devenir une longue pile de formulaires et tableaux.

### CRM concurrent/type Spot

Les captures montrent une grande densite utile : liste compacte, recherche visible, filtres rapides, tri, colonnes metier, selection en masse, actions principales proches de la liste et fiche avec trois zones lisibles.

Patterns a reprendre :

- barre de recherche proche des filtres ;
- colonnes qui aident vraiment a agir : nom, email, telephone, entreprise, statut, derniere activite ;
- actions rapides en ligne ou dans un menu contextuel ;
- fiche relation composee d'un bloc identite, d'une zone activite et d'un rail lateral pour objets lies ;
- timeline centrale pour notes, emails et activite.

Patterns a ne pas reprendre :

- pipeline, transactions, tickets, paiements ou taches lourdes ;
- personnalisation avancee de proprietes ;
- complexite commerciale qui depasse le CRM lite.

### Relaticle

Les captures montrent une interface plus simple : fiche relation lisible, edition evidente, recherche globale, notes/activite et surfaces IA.

Patterns a reprendre :

- fiche simple centree sur une personne ou une entreprise ;
- bouton d'edition primaire, menu secondaire et retour clair ;
- recherche globale accessible en permanence ;
- resume ou question IA comme surface optionnelle future ;
- notes et activite comme noyau relationnel.

Patterns a ne pas reprendre :

- navigation separee People/Companies si DEC choisit **Relations** ;
- taches/opportunites comme objets CRM v1 ;
- palette ou composants proprietaires.

## Tableau d'audit

| Ecran observe | Probleme / qualite | Decision pour DEC CRM | Priorite | Fichier/prompt impacte |
|---|---|---|---|---|
| Business actuel - onglets CRM | Entreprises, Contacts et Memos fragmentent le travail. | Creer un onglet principal **Relations** et retirer **Memos** de la navigation principale CRM. | P0 | `04_`, `05_`, `06_`, `BusinessCrmView.vue` |
| Business actuel - liste entreprises | Liste lisible mais trop pauvre : peu de contexte et actions limitees. | Fusionner la lecture entreprises/contacts dans une liste dense avec type, statut, coordonnees, entreprise, compteurs et actions. | P0 | `05_`, `13_` |
| Business actuel - formulaire sous liste | Le formulaire pleine page pousse le contenu et casse le contexte. | Remplacer les formulaires principaux par drawers ou modals de creation/edition. | P0 | `06_`, `12_`, `14_` |
| Business actuel - contacts | Les consentements sont attaches au contact, mais peu visibles dans la liste. | Afficher un indicateur de canaux/consentements et ouvrir le detail depuis la fiche relation. | P1 | `05_`, `07_`, `09_` |
| Business actuel - memos | Onglet autonome, relation faible avec la fiche contact/entreprise. | Ajouter les memos depuis la relation et afficher une timeline/section secondaire. | P0 | `06_`, `08_` |
| Business actuel - messaging | Providers et outbox sont utiles mais tres admin. | Garder la configuration admin, mais exposer l'action "message" depuis la relation. | P1 | `09_` |
| Business actuel - mailing | Mailing utile mais separe du contexte relation. | Conserver l'onglet Mailing, ajouter liens vers relations et eligibility plus lisible. | P2 | `09_`, `13_` |
| Liste dense de reference | Recherche, filtres, tri et colonnes utiles reduisent le temps de recherche. | Placer recherche, statut, type, tri et actions d'import/export dans l'en-tete de liste. | P1 | `10_`, `13_` |
| Fiche relation de reference | Identite + actions + activite rendent la fiche actionnable. | Creer une fiche relation unifiee avec actions memo/message/consentement/archive. | P0 | `07_`, `08_`, `09_` |
| Rail lateral de reference | Objets lies visibles mais certains modules sont hors perimetre. | Remplacer transactions/tickets par contacts lies, entreprise, memos, consentements, activite et futurs liens passifs. | P1 | `07_`, `08_`, `16_` |
| Modals de reference | Les creations rapides gardent le contexte. | Utiliser drawers/modals DEC pour relation, memo, message, import, export et consentement. | P0 | `06_`, `12_`, `14_` |
| Recherche globale de reference | Recherche accessible partout. | Ajouter une recherche CRM globale limitee au back-office. | P1 | `10_`, `15_` |
| Surfaces IA de reference | Resume et questions peuvent aider, mais ne doivent pas devenir obligatoires. | Preparer schema et payloads de resume activite sans provider requis. | P2 | `16_` |
| Mobile/tablette | Tables larges et formulaires longs risquent de deborder. | Prevoir liste a cartes compactes et fiche empilee avec actions sticky. | P2 | `18_` |
| Empty states | Les zones vides actuelles n'orientent pas toujours l'action suivante. | Ajouter empty states courts avec action primaire contextuelle. | P2 | `14_` |

## Backlog UX

### P0 : navigation Relations et modals essentiels

- Renommer l'entree CRM principale en **Relations** dans l'interface Business.
- Fusionner les listes entreprises et contacts dans une experience unique, sans fusion SQL brutale.
- Retirer l'onglet **Memos** comme entree principale CRM.
- Ajouter drawers/modals pour nouvelle relation, edition relation, nouveau memo et message direct.
- Conserver les onglets **Produits** et **Offres** du catalogue dans Business.

### P1 : densite liste, recherche, filtres, compteurs

- Ajouter recherche relation globale cote CRM.
- Ajouter filtres type, statut, entreprise, canal, consentement et archive.
- Ajouter compteurs visibles : memos, messages, contacts lies, consentements.
- Ajouter actions contextuelles par ligne : ouvrir, modifier, memo, message, archiver.
- Ajouter endpoint admin agrege `GET /admin/api/business/relations`.

### P2 : mobile, accessibilite, polissage visuel

- Adapter la liste Relations en cartes compactes sous breakpoint mobile.
- Stabiliser les dimensions des boutons, badges et actions pour eviter les sauts de layout.
- Ajouter focus management dans modals/drawers.
- Ajouter empty states utiles.
- Ajouter surfaces preparees pour resume IA et activite, sans rendre l'IA obligatoire.

## Composants Vue a creer ou extraire

- `BusinessRelationsList` : liste dense et responsive.
- `BusinessRelationDrawer` : creation/edition relation.
- `BusinessRelationDetail` : fiche relation unifiee.
- `BusinessActivityTimeline` : memos, messages et evenements.
- `BusinessQuickActions` : memo, message, consentement, archive.
- `BusinessRelationSearchBar` : recherche, filtres, tri et actions import/export.

Ces composants doivent rester dans le module Business et reutiliser les patterns visuels du back-office existant.

## Endpoints necessaires

Endpoints agreges a ajouter progressivement :

```text
GET    /admin/api/business/relations
GET    /admin/api/business/relations/{type}/{id}
POST   /admin/api/business/relations
PATCH  /admin/api/business/relations/{type}/{id}
POST   /admin/api/business/relations/{type}/{id}/memos
POST   /admin/api/business/relations/{type}/{id}/messages
GET    /admin/api/business/relations/{type}/{id}/memos
GET    /admin/api/business/relations/{type}/{id}/comments
GET    /admin/api/business/relations/{type}/{id}/messages
```

Les endpoints historiques `companies`, `contacts`, `memos`, `mailing` et `messaging` doivent rester compatibles tant que l'UI ou les tests les utilisent.

Ne pas ajouter d'endpoint headless public CRM.

## Ordre d'implementation conseille

1. Navigation Business simplifiee et onglet **Relations**.
2. Liste relations agregee.
3. Modals/drawers relation, memo, message et consentement.
4. Fiche relation unifiee.
5. Timeline memos/messages/activite.
6. Recherche globale CRM.
7. Dashboard leger.
8. Accessibilite, mobile et tests Playwright.

## Validation manuelle attendue

- Ouvrir **Modules > Business**.
- Trouver une relation en moins de 10 secondes.
- Creer un contact sans perdre la liste.
- Ajouter un memo depuis une relation.
- Envoyer ou preparer un message depuis une relation.
- Voir clairement si une relation est un individu ou une entreprise.
- Confirmer qu'aucune route publique CRM ne liste les relations.
