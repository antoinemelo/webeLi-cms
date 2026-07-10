---
title: Principes UX Opérations CRM
audience:
  - administrator
  - superadministrator
  - developer
status: draft
last_verified: 2026-07-06
source_of_truth: analysis
source_paths:
  - docs/business/crm-ux-audit.md
  - docs/development/architecture/business-crm-redesign-guardrails.md
  - frontend/admin-vue/src/views/modules/BusinessCrmView.vue
owners:
  - business
document_type: guide
generated: false
---
# Principes UX Opérations CRM

Ces principes guident la refonte des composants CRM du module Opérations. Ils doivent aider l'utilisateur a comprendre une relation rapidement, agir sans changer de contexte et conserver une interface legere compatible avec l'architecture DEC / webeLi.

## Questions prioritaires

Chaque ecran CRM doit aider a repondre vite a cinq questions :

1. Qui est cette personne ou organisation ?
2. Quel est son statut ?
3. Que s'est-il passe recemment ?
4. Que puis-je faire maintenant ?
5. Quels objets metier sont lies ?

Si une information ou une action ne sert aucune de ces questions, elle doit etre secondaire, masquee dans un detail, ou reportee.

## Vocabulaire utilisateur

Utiliser des libelles simples et stables :

| Terme | Usage |
|---|---|
| Relations | Entree principale CRM regroupant personnes et organisations. |
| Personnes | Contacts individuels, rattaches a une organisation ou a `Individus`. |
| Organisations | Entreprises, associations, fournisseurs ou organisation systeme `Individus`. |
| Memos | Notes internes ou partageables, rattachees a une relation. |
| Messages | Emails, WhatsApp, Telegram ou messages en file via providers. |
| Activite | Timeline de memos, messages, commentaires, changements et evenements utiles. |

Eviter les termes techniques comme `company_id`, `contact_id`, `crm_memos`, `outbox` ou `token_hash` dans l'interface.

## Navigation

- Garder **Opérations** comme entree principale du menu.
- Dans Opérations, privilegier **Relations** comme premier onglet CRM.
- Conserver **Mailing**, **Messaging**, **Produits** et **Offres** quand les permissions correspondantes existent.
- Retirer progressivement **Memos** comme onglet CRM principal ; les memos doivent vivre dans la fiche relation, la timeline ou une fenetre secondaire.
- Ne pas ajouter de navigation separee pour Personnes et Organisations si la liste Relations peut filtrer par type.

## Densite des listes

Une liste CRM doit etre dense mais lisible :

- lignes compactes avec hauteur stable ;
- nom de relation comme information principale ;
- type visible : personne ou organisation ;
- statut visible sous forme de badge ;
- coordonnees utiles : email, telephone ou mobile ;
- organisation liee pour une personne ;
- compteurs utiles : memos, messages, contacts lies ou consentements ;
- derniere activite si disponible ;
- actions rapides accessibles sans ouvrir un formulaire plein ecran.

Colonnes a eviter par defaut :

- identifiants techniques ;
- champs vides rarement utiles ;
- informations longues qui cassent la hauteur de ligne ;
- details internes de base de donnees.

## Recherche, filtres et tri

La recherche doit etre visible au-dessus de la liste Relations et accepter au minimum nom, email, telephone, mobile et organisation.

Filtres a privilegier :

- type : personne, organisation ;
- statut CRM ;
- canal disponible : email, WhatsApp, Telegram ;
- consentement ;
- archive ;
- organisation.

Tri utile :

- nom ;
- derniere activite ;
- date de creation ;
- statut.

## Fiche relation

Une fiche relation doit contenir :

- en-tete : nom, type, statut, organisation liee, coordonnees principales ;
- actions primaires : modifier, nouveau memo, nouveau message ;
- actions secondaires : consentement, archiver, effacer si autorise ;
- informations cles : email, telephone, mobile, langue, IAM lie, created_by, modified_by ;
- activite recente : memos, messages, commentaires et evenements ;
- objets lies : contacts d'une organisation, organisation d'une personne, futurs liens passifs vers commandes/factures/POS.

La fiche ne doit pas devenir un ERP. Les objets futurs doivent rester des references ou compteurs tant que les modules correspondants ne sont pas implementes.

## Actions contextuelles

Les actions frequentes doivent etre disponibles depuis la liste et la fiche :

- ouvrir ;
- modifier ;
- ajouter un memo ;
- envoyer ou preparer un message ;
- gerer consentements ;
- archiver.

Les actions destructrices ou sensibles doivent rester secondaires et confirmees.

Email, WhatsApp et Telegram doivent passer par les providers Opérations. Les composants Vue ne doivent pas appeler directement un service externe.

## Modals et drawers

Utiliser des modals ou drawers pour les operations courtes :

- nouvelle relation ;
- edition relation ;
- memo ;
- message ;
- consentement ;
- import/export ;
- commentaire.

Un modal doit avoir un titre clair, un bouton primaire unique, un bouton d'annulation, un focus initial et une fermeture clavier fiable.

## Empty states

Chaque etat vide doit indiquer l'action suivante, sans texte long :

- aucune relation : proposer de creer une relation ;
- aucun resultat de recherche : proposer de retirer les filtres ;
- aucun memo : proposer d'ajouter un memo ;
- aucun canal consentant : proposer de verifier les consentements ;
- provider messaging indisponible : indiquer que l'envoi externe n'est pas configure.

## Responsive

Desktop :

- liste dense ;
- fiche en deux ou trois zones si la largeur le permet ;
- actions visibles dans l'en-tete ou la ligne.

Mobile/tablette :

- liste en cartes compactes ;
- actions dans un menu ou barre sticky ;
- fiche empilee : identite, actions, activite, details ;
- eviter les tableaux horizontaux non scrollables ;
- garantir que les boutons restent lisibles et touchables.

## Accessibilite

- Tous les boutons iconiques doivent avoir un libelle accessible.
- Les modals doivent gerer focus, fermeture par Escape et retour du focus.
- Les badges de statut ne doivent pas dependre uniquement de la couleur.
- Les formulaires doivent avoir labels visibles.
- Les erreurs API doivent etre exposees pres du formulaire concerne.
- Les actions de ligne doivent etre accessibles au clavier.

## Donnees sensibles

Le CRM manipule des donnees personnelles. L'interface doit respecter ces regles :

- aucune route headless publique CRM generale ;
- aucune liste publique de personnes, organisations, consentements, messages ou memos ;
- liens publics limites aux memos tokenises, revocables, optionnellement expirables et `noindex,nofollow` ;
- pas de secret provider visible ou stocke en clair ;
- ne pas afficher plus de donnees que necessaire dans les listes ;
- masquer les erreurs techniques brutes.

## Libelles FR recommandes

| Situation | Libelle |
|---|---|
| Creation relation | Nouvelle relation |
| Contact individuel | Personne |
| Entreprise | Organisation |
| Liste principale | Relations |
| Note CRM | Memo |
| Historique | Activite |
| Message direct | Nouveau message |
| Permission refusee | Action non autorisee |
| Provider absent | Provider non configure |
| Aucun resultat | Aucun resultat |
| Archive | Archive |

## Definition d'une bonne iteration

Une iteration CRM est acceptable si :

- l'utilisateur trouve une relation en moins de 10 secondes ;
- l'action principale est visible sans chercher dans plusieurs onglets ;
- le contexte de la relation reste visible pendant creation/edition ;
- les endpoints admin protegent les donnees cote backend ;
- le mobile reste utilisable ;
- les tests ou smokes existants restent verts.
