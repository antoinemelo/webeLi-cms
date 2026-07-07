---
title: Revue finale refonte Opérations CRM
audience:
  - administrator
  - superadministrator
  - developer
  - evaluator
status: draft
last_verified: 2026-07-07
source_of_truth: analysis
source_paths:
  - frontend/admin-vue/src/views/modules/BusinessCrmView.vue
  - frontend/admin-vue/src/views/modules/BusinessRelationsView.vue
  - backend/src/Application/Api/Admin/BusinessCrmApiController.php
  - backend/src/Application/Api/Admin/BusinessMessagingApiController.php
  - backend/src/Application/Api/Admin/BusinessMailingApiController.php
  - backend/src/Modules/Business/BusinessModuleProvider.php
  - backend/src/Modules/Business/Repositories/BusinessRelationRepository.php
  - backend/src/Modules/Business/Repositories/BusinessCompanyRepository.php
  - backend/src/Modules/Business/Repositories/BusinessContactRepository.php
  - backend/src/Modules/Business/Repositories/BusinessMemoRepository.php
  - backend/routes/api.php
  - backend/routes/web.php
  - frontend/admin-vue/tests/e2e/business-crm-smoke.spec.ts
  - tools/php/tests/unit/business_crm_api_controller_test.php
  - tools/php/tests/unit/business_crm_blueprints_test.php
owners:
  - business
document_type: review
generated: false
---
# Revue finale refonte Opérations CRM

Cette revue controle la refonte CRM du module technique `business`, exposee a l'utilisateur sous le libelle **Opérations**. Elle ne valide pas un ERP complet : elle evalue un CRM leger centre sur les relations, les memos, les messages et les consentements.

## Synthese

La refonte a deplace l'usage principal vers **Opérations > Relations**, avec une liste unifiee personnes/organisations, une fiche relation dediee, des modals pour les actions courantes, des memos contextualises et des endpoints admin explicites. Le choix est coherent avec l'architecture DEC : pas de stack lourde, pas de remplacement de l'interface par des formulaires generes, pas de routes headless publiques CRM.

La base reste pragmatique, mais quelques points doivent rester surveilles avant une release officielle : exhaustivite E2E, qualite visuelle sur donnees longues, couverture des actions sensibles et performance des compteurs si le volume CRM augmente.

## Points controles

| Controle | Statut | Commentaire |
|---|---:|---|
| Libelle utilisateur **Opérations** | OK | La navigation et les titres principaux utilisent le libelle utilisateur. La cle technique `business` reste conservee. |
| Relations unifiees | OK | La vue **Relations** regroupe contacts et organisations. Les anciens onglets techniques sont normalises vers les vues actuelles. |
| Onglet principal `Mémos` retire | OK | Les memos sont accessibles depuis Relations, les actions contextuelles et la liste secondaire filtree. |
| Onglet principal `Messages` retire | OK | Les messages restent accessibles depuis Relations et le tableau de bord communication. |
| Produits et Offres dans Opérations | OK | Le catalogue est integre sous les onglets **Produits** et **Offres**. |
| Fiche relation lisible | OK avec reserve | Lecture et edition sont separees. Les informations audit et les relations liees sont plus lisibles, mais doivent etre verifiees sur donnees longues. |
| Modals d'action | OK avec reserve | Creation, edition, memos, message, consentement, import/export et suppression passent par des surfaces dediees. La hauteur et les contenus doivent rester surveilles sur mobile. |
| Switch Individu/Entreprise | OK | Le choix utilise des onglets standards proches de la configuration CMS. |
| Combobox IAM et unicite | OK | L'API expose les comptes IAM disponibles et le repository refuse la reutilisation active d'un compte deja lie. |
| `Individus` cache/protege | OK | L'organisation systeme est exclue de la liste Relations et protegee contre restauration/suppression comme entreprise normale. |
| Archivage, restauration, suppression | OK | Une relation archivee peut etre restauree ou supprimee. La suppression reste conditionnee a l'archivage. |
| Actions sur relation archivee | OK | Le comportement cible est limite a voir, retablir et effacer. |
| Partage public memo | OK | Route tokenisee, hash du token, revocation et `noindex,nofollow`. |
| Email/WhatsApp/Telegram decouples | OK | Les envois passent par providers et services messaging, pas par appels disperses dans Vue. |
| Aucun headless CRM public | OK | `publicHeadlessRoutes()` retourne `[]`. Les routes publiques restantes sont des pages tokenisees, pas `/api/v1/business/*`. |
| Blueprints admin CRM | OK | Les ressources CRM principales sont declarees admin-only avec champs, permissions et export sensible exclu. |
| Permissions CRM | OK | Les 9 permissions CRM/messaging/mailing de base sont documentees et rattachees aux endpoints. Les permissions catalogue sont separees. |
| Tests critiques | Partiel | Des tests unitaires, API et E2E smoke existent. Ils ne remplacent pas encore une matrice exhaustive de tous les parcours et erreurs. |

## Simplicite et comprehension

Le modele reste simple : une entree **Opérations**, une vue **Relations**, des actions contextuelles et des modals. C'est le bon niveau pour DEC CMS. Les termes techniques restent toutefois presents dans quelques zones de documentation ou de contrats, ce qui est acceptable pour les docs developpeur mais a eviter dans l'interface utilisateur.

La comprehension rapide depend surtout de trois surfaces :

- la liste Relations ;
- la fiche relation ;
- les actions du menu `...`.

Ces surfaces sont maintenant plus proches d'un CRM utilisable, mais la densite doit rester testee avec des noms longs, plusieurs emails, plusieurs telephones, beaucoup de memos et des relations archivees.

## API et securite

Les endpoints CRM sont declares sous `/admin/api/business/*`, avec permissions IAM et contexte site. Les routes publiques limitees sont :

- `GET /business/memos/share/{token}` ;
- `GET /business/unsubscribe/{token}` ;
- `POST /business/unsubscribe/{token}`.

Ces routes ne sont pas une API headless CRM. Elles doivent rester tokenisees, revocables, non indexables et sans exposition de donnees relationnelles au-dela de l'objet explicitement partage.

Les exports doivent rester traites comme sensibles. Les tokens publics bruts, secrets de providers et donnees de configuration ne doivent pas apparaitre dans les exports non securises ni dans les logs.

## Performance

Les compteurs `memo_count`, `shared_memo_count` et `linked_contacts_count` sont calcules cote backend dans les requetes de relations. Cela evite un N+1 applicatif, mais les sous-requetes peuvent devenir couteuses si le volume augmente fortement.

Point a surveiller : si le CRM depasse quelques milliers de relations/memos par site, ajouter des index, des vues materialisees ou des compteurs denormalises devient preferable a l'empilement de sous-requetes.

## Preparation IA, produits, commandes et factures

Les blueprints admin CRM preparent la decouverte de schema et une future assistance IA sans ouvrir l'API publique. C'est sain : l'IA doit consommer une description structuree, pas deviner le modele depuis l'interface.

Les produits et offres sont deja dans Opérations. En revanche, commandes, factures, paiements, relances et pipeline commercial ne sont pas actifs. Les docs doivent continuer a les presenter comme extensions futures, pas comme fonctions disponibles.

## Recommandations P0

Aucun P0 fonctionnel n'est identifie dans cette revue documentaire.

P0 conditionnel avant release publique :

- bloquer la release si `python3 tools/cms.py validate`, `python3 tools/cms.py docs check`, les tests PHP CRM et le smoke E2E CRM echouent ;
- bloquer la release si une route anonyme `/api/v1/business/*` ou `/api/v1/crm/*` repond `200` avec des donnees CRM ;
- bloquer la release si un lien public memo expose plus que le memo partage ou perd `noindex,nofollow`.

## Recommandations P1

- Completer les tests E2E sur les erreurs : consentement manquant, provider desactive, suppression non archivee, IAM deja lie.
- Ajouter un test de non-regression sur l'organisation systeme `Individus` : cachee de Relations, non editable comme entreprise, non supprimable.
- Tester explicitement la restauration d'une relation archivee et la limitation des actions disponibles.
- Ajouter une verification de performance minimale sur le listing Relations avec un volume de fixtures plus important.
- Clarifier dans l'interface les differences entre message direct, outbox et campagne mailing lorsque les providers sont incomplets.
- Verifier le rendu mobile des modals longues et des listes avec colonnes masquees.

## Recommandations P2

- Ajouter une recherche sauvegardee ou des presets de filtres si l'usage quotidien le justifie.
- Ajouter une surface de resume relationnel IA uniquement apres validation des permissions et de la politique de donnees.
- Prevoir une vue d'import CSV plus assistee, avec mapping de colonnes et preview des erreurs.
- Enrichir les tests visuels Playwright sur la liste Relations et la fiche relation avec captures de reference.
- Documenter une strategie d'indexation si le CRM est utilise avec un volume important.

## Checklist release CRM

Avant de considerer la refonte CRM comme prete a livrer :

- [ ] `python3 tools/cms.py rebuild` passe.
- [ ] `python3 tools/cms.py validate` passe.
- [ ] `python3 tools/cms.py docs generate` passe.
- [ ] `python3 tools/cms.py docs check` passe.
- [ ] `python3 tools/cms.py smoke` passe.
- [ ] `python3 tools/cms.py test --timeout 300 --target-duration 120` passe ou les echecs sont qualifies hors CRM.
- [ ] `python3 tools/cms.py e2e --use-built-assets` passe apres build frontend.
- [ ] `npm --prefix frontend/admin-vue run build` passe et les assets distribues sont coherents.
- [ ] Les routes `/api/v1/business/relations`, `/api/v1/business/contacts`, `/api/v1/business/companies`, `/api/v1/crm/relations`, `/api/v1/crm/contacts`, `/api/v1/crm/companies` ne renvoient jamais `200` anonyme.
- [ ] Un memo partage publiquement est accessible par token valide et refuse par token invalide/revoque.
- [ ] Les exports ne contiennent pas de token public brut ni de secret provider.
- [ ] Les permissions `business.crm.*`, `business.memo.*`, `business.mailing.*` et `business.messaging.*` bloquent bien les actions non autorisees.
- [ ] Les guides utilisateur, admin, messaging et IA sont publies dans le catalogue documentaire.

## Conclusion

La refonte Opérations CRM est coherente avec l'objectif : un CRM leger, unifie, utilisable dans le back-office DEC, sans exposition headless publique et sans remplacement de l'UX par un generateur. La release doit rester conditionnee aux validations automatisees et a une passe manuelle sur les parcours relation, memo, partage, consentement et messaging.
