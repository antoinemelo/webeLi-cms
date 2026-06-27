---
title: Specification fonctionnelle Business CRM Lite
audience:
  - developer
  - administrator
status: draft
last_verified: 2026-06-26
source_of_truth: analysis
source_paths:
  - docs/development/architecture/business-module-plan.md
  - backend/src/Module/ModuleProvider.php
  - docs/development/extending/modules.md
owners:
  - business
  - core
document_type: specification
generated: false
---
# Specification fonctionnelle Business CRM Lite

Cette specification fixe le perimetre fonctionnel v1 du module `business` avant le schema SQL et l'API. Le module utilise une base metier unique `storage/database/business.sqlite` pour le CRM, les memos, les consentements, le mailing simple et la preparation messaging. Il ne transforme pas le CMS en ERP.

## Perimetre v1

### Entreprises

Une entreprise represente une organisation reelle ou une organisation systeme. La v1 doit creer ou garantir une entreprise systeme `Individus` pour rattacher les contacts sans entreprise reelle.

Champs fonctionnels attendus :

- nom public ou usuel ;
- statut CRM : `prospect`, `client`, `supplier`, `former_client`, `other` ;
- rattachement `site_id` pour compatibilite multisite ;
- coordonnees simples : email, telephone, site web, adresse libre ou structuree ;
- tags optionnels ;
- dates de creation, mise a jour, archivage.

La suppression dure n'est pas le flux standard. Une entreprise est retiree de l'usage courant par `archived_at`.

### Contacts

Un contact represente une personne. Chaque contact appartient a exactement une entreprise via `company_id NOT NULL`. Les personnes sans organisation reelle sont rattachees a l'entreprise systeme `Individus`.

Champs fonctionnels attendus :

- prenom, nom, nom affiche ;
- statut CRM : `prospect`, `client`, `supplier`, `former_client`, `other` ;
- email, telephone, mobile ;
- langue preferee ;
- fonction ou note courte ;
- lien optionnel vers un compte IAM par `iam_user_id` ;
- dates de creation, mise a jour, archivage.

Un meme `iam_user_id` ne doit pas etre lie a plusieurs contacts actifs. Si un contact est archive, son lien IAM peut rester historique, mais ne doit pas bloquer un nouveau contact actif que si la regle metier future le demande explicitement.

### Memos

Un memo est une note CRM simple. Il peut etre rattache a une entreprise, a un contact, ou aux deux. Un memo a toujours un auteur IAM.

Fonctions attendues :

- creation, lecture, modification, archivage ;
- partage interne a des utilisateurs IAM choisis ;
- partage public par lien secret, lecture seule, revocable ;
- journalisation des evenements de partage et de revocation.

Les liens publics de memos doivent etre non indexables, ne pas exposer l'interface admin, ne pas lister d'autres memos, et rester utilisables sans session uniquement pour le memo cible.

### Tags et segmentation simple

Les tags servent a filtrer entreprises et contacts. Ils ne remplacent pas un pipeline commercial. Un tag peut etre utilise pour composer une liste mailing simple, mais aucune automatisation marketing avancee n'est prevue en v1.

### Consentements

Le CRM doit stocker les consentements par contact et par canal :

- `email` ;
- `whatsapp` ;
- `telegram`.

Un consentement doit indiquer au minimum le canal, l'etat, la source, la date, et une trace de retrait si applicable. L'envoi d'un message ou d'une campagne doit refuser le canal si le consentement requis est absent ou retire.

### Mailing simple

La v1 couvre :

- listes de diffusion simples ;
- appartenance de contacts a une liste ;
- campagnes simples ;
- brouillon, pret a envoyer, envoye, annule ;
- historique d'envoi par destinataire ;
- lien de desabonnement.

La v1 ne couvre pas l'automatisation avancee, le scoring, l'A/B testing, les parcours marketing, ni le tracking comportemental obligatoire.

### Messaging

Le module prepare une couche messaging decouplee. Les canaux cibles sont email, WhatsApp et Telegram, mais aucun provider externe ne doit etre obligatoire pour que le CRM fonctionne.

La v1 doit privilegier des interfaces et providers configurables :

- provider nul ou simulateur local pour tests ;
- provider email configurable ;
- placeholders pour WhatsApp et Telegram sans secret en clair.

WhatsApp doit passer par l'API officielle WhatsApp Business Platform / Cloud API ou par un provider explicitement configure. Les messages inities par l'entreprise doivent respecter opt-in, templates, fenetres de conversation et politiques Meta.

Telegram doit passer par Bot API ou provider officiel/configure. Un bot ne peut pas contacter arbitrairement une personne par numero de telephone ; un identifiant de chat valide ou une interaction prealable est necessaire selon le flux retenu.

### Preparation commerce futur

Le CRM peut prevoir des references futures vers commandes, factures ou objets externes, mais ne doit pas implementer commerce, devis, facturation, paiement, stock, paie ou comptabilite.

Si une table de liens generiques est ajoutee plus tard, elle doit rester passive : type d'objet externe, identifiant externe, libelle, date. Elle ne doit pas creer un faux modele commerce incomplet.

## Hors perimetre v1

Sont exclus :

- pipeline commercial ;
- opportunites, phases de vente, probabilites ;
- taches, rappels, agenda ;
- devis, commandes, factures, paiements ;
- comptabilite, paie, notes de frais ;
- gestion de stock ;
- automatisation marketing avancee ;
- A/B testing ;
- tracking d'ouverture/clic obligatoire ;
- scraping WhatsApp ou automatisation non officielle ;
- contact Telegram arbitraire sans chat id ou interaction prealable ;
- stockage de secrets providers en clair dans le code, les seeds ou la documentation.

## Roles et permissions

Les permissions IAM sont la source d'autorisation. Les roles existants peuvent recevoir ces permissions selon l'installation, mais le module ne doit pas supposer qu'un libelle de role suffit.

Permissions v1 proposees :

| Permission | Autorise |
|---|---|
| `business.crm.read` | Lire entreprises, contacts, tags, consentements et memos accessibles. |
| `business.crm.manage` | Creer, modifier, archiver entreprises, contacts, tags et consentements. |
| `business.memo.share` | Partager ou revoquer un memo en interne ou par lien public. |
| `business.messaging.send` | Declencher un envoi unitaire via un provider configure, apres controle du consentement. |
| `business.mailing.manage` | Gerer listes, campagnes simples, envois, historique et desabonnements. |

Regles :

- toute route admin doit exiger une session valide ;
- toute route admin doit verifier une permission `business.*` cote backend ;
- les donnees multisite doivent etre filtrees par `site_id` lorsque le contexte de site existe ;
- les partages publics ne donnent aucune permission admin.

## Regles metier

1. Une entreprise systeme `Individus` doit exister par contexte pertinent afin de rattacher les contacts sans societe reelle.
2. Un contact actif a toujours une entreprise.
3. Un contact actif ne partage pas son `iam_user_id` avec un autre contact actif.
4. Les statuts CRM autorises sont strictement `prospect`, `client`, `supplier`, `former_client`, `other`.
5. L'archivage remplace la suppression dure dans les flux utilisateur standards.
6. Un memo doit avoir au moins une cible : entreprise, contact, ou les deux.
7. Un partage public de memo est lecture seule, secret, revocable et non indexable.
8. Un envoi messaging ou mailing doit verifier le consentement du canal avant execution.
9. L'usage d'un provider externe doit etre journalise sans secret ni contenu sensible inutile.
10. L'absence de provider WhatsApp, Telegram ou email externe ne doit pas casser la consultation CRM.

## Flux utilisateur

### Creer un contact sans entreprise

1. L'utilisateur ouvre le CRM.
2. Il cree un contact sans societe reelle.
3. Le systeme rattache le contact a `Individus`.
4. Le contact apparait dans les listes et recherches CRM.

### Creer une entreprise et ses contacts

1. L'utilisateur cree une entreprise avec statut `prospect`.
2. Il ajoute un ou plusieurs contacts rattaches a cette entreprise.
3. Il ajoute des tags et consentements si disponibles.
4. Les fiches entreprise et contact affichent les memos lies.

### Partager un memo en interne

1. L'utilisateur cree un memo sur une entreprise ou un contact.
2. Il selectionne des utilisateurs IAM destinataires.
3. Le systeme enregistre les partages internes.
4. Les destinataires autorises peuvent lire le memo dans le back-office.

### Partager un memo publiquement

1. Un utilisateur avec `business.memo.share` active un lien public.
2. Le systeme genere un secret robuste et stocke uniquement ce qui est necessaire a la verification.
3. Le lien affiche le memo en lecture seule, avec en-tetes non indexables.
4. La revocation rend le lien inutilisable.

### Envoyer une campagne simple

1. Un utilisateur cree une liste de contacts.
2. Il cree une campagne email simple.
3. Le systeme exclut les contacts sans consentement email actif.
4. L'envoi passe par un provider configure ou reste en simulation si aucun provider reel n'est active.
5. L'historique enregistre le resultat par destinataire.

### Desabonnement

1. Un destinataire ouvre un lien de desabonnement.
2. Le systeme identifie le contact et le canal sans exposer le CRM.
3. Le consentement du canal est retire.
4. Les futurs envois sur ce canal sont refuses.

## Modele logique

Entites v1 :

- `business_companies`
- `business_contacts`
- `business_tags`
- `business_company_tags`
- `business_contact_tags`
- `business_memos`
- `business_memo_internal_shares`
- `business_memo_public_shares`
- `business_contact_consents`
- `business_message_providers`
- `business_message_outbox`
- `business_message_events`
- `business_mailing_lists`
- `business_mailing_list_contacts`
- `business_mailing_campaigns`
- `business_mailing_deliveries`

Relations principales :

- entreprise 1-n contacts ;
- entreprise 1-n memos ;
- contact 1-n memos ;
- memo n-n utilisateurs IAM via partages internes ;
- memo 0-n liens publics revocables ;
- contact 1-n consentements ;
- contact n-n listes mailing ;
- campagne 1-n deliveries ;
- provider 1-n messages/outbox.

## RGPD et securite

Risques principaux :

- donnees personnelles dans contacts, memos et historique d'envoi ;
- fuite par lien public de memo ;
- envoi sans consentement ;
- journalisation excessive de contenus ou secrets ;
- confusion entre role UI et permission backend.

Mesures attendues :

- permissions backend obligatoires ;
- archivage et retrait de consentement explicites ;
- liens publics secrets, revocables et non indexables ;
- logs sans secrets providers ;
- pas de token externe dans seeds ou docs ;
- minimisation du contenu journalise ;
- tests de refus pour absence de permission et absence de consentement.

## Criteres d'acceptation fonctionnels

- La base metier unique est `storage/database/business.sqlite`.
- Le CRM gere entreprises et contacts sans introduire de pipeline commercial.
- Les contacts sans entreprise reelle sont rattaches a `Individus`.
- Chaque contact actif a une seule entreprise.
- Un compte IAM ne peut pas etre lie a plusieurs contacts actifs.
- Les statuts CRM sont limites a la liste validee.
- Les memos peuvent etre lies a une entreprise, un contact, ou les deux.
- Les partages internes sont limites aux utilisateurs IAM selectionnes.
- Les partages publics sont lecture seule, secrets, non indexables et revocables.
- Les consentements sont verifies avant tout envoi mailing ou messaging.
- WhatsApp et Telegram sont prepares via providers officiels/configurables, sans dependance obligatoire.
- Aucun secret provider n'est stocke en clair dans le code, les seeds ou la documentation.
- Les routes admin sont protegees par session et permissions `business.*`.
