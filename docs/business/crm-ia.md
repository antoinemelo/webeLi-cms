---
title: IA et découverte de schéma Opérations CRM
audience:
  - administrator
  - superadministrator
  - developer
  - api-integrator
status: draft
last_verified: 2026-07-07
source_of_truth: code
source_paths:
  - backend/src/Modules/Business/BusinessModuleProvider.php
  - backend/src/Application/Api/Admin/BusinessCrmApiController.php
  - backend/src/Application/Api/Admin/Contract/AdminApiEndpointRegistry.php
  - docs/api/admin-internal/business-crm.md
  - docs/development/architecture/module-blueprints.md
  - docs/development/architecture/business-crm-functional-spec.md
owners:
  - business
document_type: guide
generated: false
---
# IA et découverte de schéma Opérations CRM

Le CRM Opérations est prepare pour la decouverte de schema, les exports controles et une future assistance IA. Cette preparation ne signifie pas qu'une IA lit automatiquement les donnees CRM.

## Ce qui est declare

Le module `business` declare des blueprints admin pour les ressources CRM principales :

- `business.relation` ;
- `business.company` ;
- `business.contact` ;
- `business.memo` ;
- `business.memo_comment` ;
- `business.message` ;
- `business.consent` ;
- `business.mailing_list`.

Ces blueprints decrivent les champs, types, enumerations, permissions et limites d'exposition.

## Pourquoi ces blueprints existent

Ils servent a :

- documenter les ressources metier ;
- stabiliser les contrats admin ;
- guider les imports et exports ;
- aider les validateurs et tests ;
- preparer une future couche IA ou schema discovery ;
- eviter que l'interface dediee soit le seul endroit ou le modele est decrit.

Ils ne generent pas automatiquement l'UX CRM. La vue Relations, les fiches, modals, memos, messages et consentements restent des composants Vue specifiques.

## Donnees admin uniquement

Les ressources CRM contiennent des donnees personnelles : noms, emails, telephones, memos, commentaires, messages et consentements.

Regle de base :

- les blueprints CRM sont admin-only ;
- aucune route headless publique CRM n'est creee par defaut ;
- les routes publiques `/api/v1/*` ne doivent pas exposer les relations, contacts, memos ou messages ;
- le partage public d'un memo n'est pas une API headless CRM, mais une page tokenisee et revocable.

## Usage IA futur

Une future assistance IA peut s'appuyer sur les blueprints pour comprendre :

- les champs disponibles ;
- les types et enumerations ;
- les permissions requises ;
- les relations entre objets ;
- les champs sensibles a exclure d'un export ou d'une reponse.

Toute fonctionnalite IA devra verifier les permissions IAM, le contexte site, la finalite de traitement et les limites documentees avant de lire ou resumer une donnee CRM.

## Exemples de surfaces possibles

Surfaces compatibles avec le modele actuel :

- resume local d'une fiche relation ;
- suggestion de recherche ou filtre ;
- aide a l'import CSV ;
- detection de doublons probable ;
- preparation d'un brouillon de memo ou message, sans envoi automatique.

Surfaces hors perimetre actuel :

- scoring commercial automatique ;
- envoi automatique de messages ;
- extraction publique de contacts ;
- exposition d'un endpoint headless CRM ;
- apprentissage ou export non controle vers un service tiers.

## Limites et precautions

Les blueprints ne remplacent pas les permissions, les contrats API ni les tests. Ils fournissent une description exploitable, mais chaque action reelle doit continuer a passer par les endpoints admin proteges et les services metier.
