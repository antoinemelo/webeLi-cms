---
title: Guide administrateur Opérations CRM
audience:
  - administrator
  - superadministrator
status: draft
last_verified: 2026-07-07
source_of_truth: code
source_paths:
  - backend/src/Modules/Business/BusinessModuleProvider.php
  - backend/src/Modules/Business/Services/BusinessCrmService.php
  - backend/src/Modules/Business/Services/BusinessMessagingProviderManager.php
  - backend/src/Modules/Business/Services/BusinessMemoSharingService.php
  - backend/src/Modules/Business/Repositories/BusinessCompanyRepository.php
  - backend/src/Modules/Business/Repositories/BusinessContactRepository.php
  - backend/src/Application/Api/Admin/BusinessCrmApiController.php
  - backend/src/Application/Api/Admin/BusinessMessagingApiController.php
  - database/modules/business.sql
owners:
  - business
document_type: guide
generated: false
---
# Guide administrateur Opérations CRM

Ce guide aide a administrer le CRM leger du module `business`. Les donnees metier sont stockees dans `storage/database/business.sqlite` et les endpoints prives restent sous `/admin/api/business/*`.

## Base et ressources

Le CRM utilise une base metier unique, separee des bases noyau :

- entreprises et contacts ;
- memos, commentaires et partages ;
- consentements ;
- listes de diffusion et campagnes simples ;
- outbox messages ;
- providers de messaging ;
- blueprints admin CRM.

Les blueprints documentent les ressources et leurs champs, mais ne remplacent pas l'interface Vue dediee.

## Organisation systeme `Individus`

`Individus` est l'organisation de rattachement pour les personnes sans entreprise reelle.

Regles d'administration :

- elle doit etre cachee des listes relationnelles courantes ;
- elle ne doit pas etre modifiee comme une entreprise cliente ;
- elle ne doit pas etre supprimee ;
- elle sert uniquement a conserver la contrainte locale `company_id` des contacts.

## Permissions

| Permission | Usage |
|---|---|
| `business.crm.read` | Lire relations, contacts, organisations, tags et consentements. |
| `business.crm.manage` | Creer, modifier, archiver, restaurer, supprimer, importer ou exporter. |
| `business.memo.read` | Lire les memos accessibles. |
| `business.memo.manage` | Creer, modifier, commenter et archiver les memos. |
| `business.memo.share` | Creer ou revoquer les partages internes et publics. |
| `business.mailing.read` | Lire listes, campagnes et historiques. |
| `business.mailing.manage` | Gerer listes, membres, campagnes et mise en file. |
| `business.messaging.send` | Envoyer ou preparer un message direct apres controle du consentement. |
| `business.messaging.admin` | Administrer providers, outbox et messages de test. |

Les permissions sont appliquees cote serveur. Une action masquee dans l'interface ne constitue pas une protection suffisante.

## Providers de messages

Les providers doivent etre configures centralement. Les composants Vue ne doivent pas appeler directement WhatsApp, Telegram ou un service email externe.

Principes :

- `log_only` sert au developpement et aux validations sans envoi externe ;
- l'email peut utiliser le mailer PHP si aucun provider email explicite n'est configure ;
- WhatsApp et Telegram restent desactives tant que leur configuration officielle est incomplete ;
- les secrets doivent provenir de l'environnement ou d'une reference de secret, jamais du code ni des seeds.

## Logs et activite

Les actions importantes doivent rester tracables :

- creation, modification, archivage, restauration et suppression de relation ;
- creation et partage de memo ;
- revocation de lien public ;
- envoi ou echec de message ;
- changement de consentement ;
- import/export.

Les logs ne doivent jamais contenir de token de partage brut, secret provider ou destinataire sensible en clair lorsque le hash suffit.

## Import, export et donnees sensibles

Les exports CRM peuvent contenir des donnees personnelles. Ils doivent rester reserves aux permissions d'administration adequates.

Regles minimales :

- ne jamais exporter un token public brut ;
- neutraliser les cellules CSV pouvant etre interpretees comme formules ;
- filtrer par site lorsque le contexte multisite est actif ;
- documenter tout export utilise hors back-office.

## Limites connues

Le CRM ne fournit pas de pipeline commercial, taches, rappels, facturation, synchronisation SaaS native ou worker autonome garanti. Ces extensions doivent rester separees du socle CRM leger.
