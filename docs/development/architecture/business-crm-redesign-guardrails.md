---
title: Garde-fous de refonte Business CRM
audience:
  - developer
  - administrator
status: draft
last_verified: 2026-07-07
source_of_truth: manual
source_paths:
  - backend/src/Modules/Business/BusinessModuleProvider.php
  - backend/src/Application/Api/Admin/BusinessCrmApiController.php
  - backend/src/Application/Api/Admin/BusinessMailingApiController.php
  - backend/src/Application/Api/Admin/BusinessMessagingApiController.php
  - backend/src/Application/Frontend/BusinessMemoShareController.php
  - backend/src/Application/Frontend/BusinessUnsubscribeController.php
  - frontend/admin-vue/src/views/modules/BusinessCrmView.vue
  - database/modules/business.sql
  - backend/routes/api.php
  - backend/routes/web.php
owners:
  - business
document_type: architecture
generated: false
---
# Garde-fous de refonte Business CRM

Cette note fixe les contraintes de refonte CRM avant les changements UX et API. Elle complete l'architecture du module Business sans remplacer le CMS, sans introduire de stack lourde et sans exposer le CRM comme API publique.

## Etat observe

Le CRM fait partie du module systeme `business` :

- base metier unique : `storage/database/business.sqlite` ;
- schema natif : `database/modules/business.sql` ;
- tables CRM conservees : `business_companies`, `business_contacts`, `crm_memos`, `crm_memo_shares`, `crm_memo_comments`, `crm_contact_channels`, `crm_consents`, `crm_mailing_*`, `crm_message_*` ;
- permissions CRM principales : `business.crm.read`, `business.crm.manage`, `business.memo.read`, `business.memo.manage`, `business.memo.share`, `business.mailing.read`, `business.mailing.manage`, `business.messaging.send`, `business.messaging.admin` ;
- blueprints admin CRM déclarés pour `business.relation`, `business.company`, `business.contact`, `business.memo`, `business.memo_comment`, `business.message`, `business.consent`, `business.mailing_list` et `business.mailing_list_member` ;
- endpoints admin privés déclarés par `BusinessModuleProvider::adminRoutes()` sous `/admin/api/business/...` ;
- aucune route headless CRM generale.

Le module Business contient aussi le catalogue. Les prompts de refonte CRM ne doivent pas casser les onglets Produits et Offres ni les permissions catalogue.

## Decisions produit

- Le CRM reste dans `business`.
- Les entreprises et contacts peuvent rester separes en SQL.
- L'interface doit progressivement regrouper entreprises et contacts sous une notion unifiee : **Relations**.
- Une personne appartient a une seule entreprise ; les particuliers sont rattaches a l'entreprise systeme `Individus`.
- Un contact peut etre lie ou non a un compte IAM.
- Les memos peuvent etre lies a une entreprise, a un contact, ou aux deux.
- Le CRM ne doit pas devenir un pipeline commercial, un ERP, un module de taches ou un module de rappels.
- Les futures commandes, factures, POS, e-commerce et IA peuvent etre preparees par des references ou aggregations, pas par une implementation incomplete dans ce chantier.

## Garde-fous UX

- Reduire le nombre d'ecrans visibles.
- Favoriser une vue principale **Relations** plutot que deux experiences separees entreprises/contacts.
- Retirer progressivement l'onglet autonome **Memos** de la navigation principale CRM ; les memos doivent etre accessibles depuis une relation, une liste secondaire ou une fenetre dediee avec retour.
- Garder des listes denses mais lisibles.
- Rendre les actions frequentes disponibles en un ou deux clics : modifier, memo, message, consentement, archiver, effacer.
- Ne pas afficher de details techniques de base de donnees dans l'interface.
- Adapter desktop et mobile sans multiplier les parcours.

Les captures fournies dans le dossier CRM servent uniquement d'inspiration ergonomique : densite, fiches, modals, actions contextuelles, recherche et filtres. Elles ne doivent pas conduire a copier une marque, une charte graphique, un logo, du code ou des composants proprietaires.

## Garde-fous API

Les routes admin actuelles `/admin/api/business/companies`, `/contacts`, `/memos`, `/mailing` et `/messaging` peuvent rester compatibles.

Les prompts suivants peuvent ajouter des endpoints agreges dedies aux relations, par exemple :

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

Ces routes doivent rester des routes admin authentifiees, protegees par permissions `business.*` cote backend.

Les endpoints CRM privés sont déclarés dans le provider du module Business afin que le back-office Vue, les contrats admin, les tests et la documentation machine-readable disposent d'une source vérifiable. La formulation attendue est :

```text
Business CRM
1 base métier business.sqlite
permissions CRM
blueprints admin CRM
endpoints admin privés
0 route headless publique CRM par défaut
```

Les endpoints déclarés couvrent relations, entreprises, contacts, mémos, partages, commentaires, messages, consentements globaux et par contact, recherche, import/export CSV et mailing simple. Les permissions restent celles du module (`business.crm.*`, `business.memo.*`, `business.messaging.*`, `business.mailing.*`) et ne sont pas remplacées par un second modèle de droits.

Les aliases privés suivants existent pour stabiliser les contrats admin sans exposer le CRM publiquement :

- `GET|POST|PATCH /admin/api/business/consents...` pour les consentements ;
- `POST /admin/api/business/import/relations|contacts|companies` pour les imports CSV admin ;
- `GET /admin/api/business/export/relations|contacts|companies|memos` pour les exports CSV admin sans token de partage brut.

## Blueprints admin CRM

Les blueprints CRM du module Business sont déclaratifs. Ils servent à documenter les ressources métier, leurs champs, les validations, les permissions, les relations, les colonnes exportables et les futures surfaces de schema discovery ou d'assistance IA.

Ils ne remplacent pas l'interface Vue dédiée : la vue Relations, les fiches, les modales, les mémos, les messages et les consentements restent gérés par les composants Business existants. Les formulaires générés ne sont pas activés pour le CRM.

Les ressources déclarées sont :

- `business.relation` : agrégat de lecture pour la vue Relations, basé sur entreprises, contacts, mémos et partages ;
- `business.company` : entreprises CRM ;
- `business.contact` : individus et contacts liés à une entreprise ;
- `business.memo` et `business.memo_comment` : mémos et commentaires authentifiés ;
- `business.message` : outbox des messages CRM ;
- `business.consent` : consentements de communication ;
- `business.mailing_list` et `business.mailing_list_member` : listes simples et membres.

Chaque blueprint CRM garde `headless.enabled=false` et `public=false`. Aucun endpoint public `/api/v1/...` ne liste ou n'expose relations, entreprises, contacts, consentements, messages, mémos ou listes de diffusion.

## Donnees publiques interdites

Ne pas ajouter de route headless publique CRM en v1. Le CRM contient des donnees personnelles.

Les seuls acces sans session acceptables restent :

- `GET /business/memos/share/{token}` pour un memo precis, avec token long, hash en base, revocation, expiration optionnelle et `noindex,nofollow` ;
- `GET /business/unsubscribe/{token}` et `POST /business/unsubscribe/{token}` pour le desabonnement.

Aucun endpoint public ne doit lister relations, entreprises, contacts, consentements, messages ou memos.

## Messaging

Les actions email, WhatsApp et Telegram doivent passer par les services/providers Business. Les composants Vue ne doivent pas appeler directement une API externe.

Les secrets providers ne doivent pas etre stockes en clair dans le code, les seeds ou la documentation. Les providers reels doivent verifier consentement, canal et configuration avant envoi.

## Validation minimale par prompt

Chaque prompt de refonte CRM doit fournir :

- analyse rapide de l'existant touche ;
- fichiers modifies ou ajoutes ;
- migrations si necessaires ;
- tests PHP, Python, docs ou build frontend selon le changement ;
- note courte de validation manuelle.

Commandes de base a privilegier selon le type de changement :

```bash
python3 tools/cms.py validate
python3 tools/cms.py docs check
python3 tools/cms.py business smoke
npm --prefix frontend/admin-vue run build
```

Ne pas traiter un ticket suivant pour masquer une regression du ticket courant.
