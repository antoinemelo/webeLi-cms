---
title: Audit et preuve — Relation 360 et CRM compréhensible (38b)
audience:
  - evaluator
  - administrator
  - developer
status: current
last_verified: 2026-07-15
source_of_truth: code
source_paths:
  - backend/src/Modules/Business/Services/BusinessRelation360Service.php
  - backend/src/Modules/Business/Services/FormSubmissionRelationProjectionService.php
  - frontend/admin-vue/src/views/modules/business/Relation360Sections.vue
  - tools/php/tests/unit/business_relation_360_test.php
owners:
  - business
  - sale
  - core
document_type: evaluation
generated: false
---
# Audit et preuve — Relation 360 et CRM compréhensible (38b)

## Périmètre audité

L’audit a examiné la liste et la fiche Relations, les dépôts contacts/sociétés, mémos, messages et consentements, la projection des événements Sale, les soumissions Forms, les services de comptes clients et rapprochement d’identité, les contrats admin, les permissions et les schémas SQLite.

La règle d’architecture retenue est stricte : Business/CRM est propriétaire de la relation, des rôles et des tâches ; Sale reste propriétaire des commandes, paiements et livraisons ; Forms reste propriétaire des formulaires, réponses et contenus soumis. La fiche ne lit pas durablement les bases Sale ou Forms : elle assemble des read models reconstruisibles dans `business.sqlite`.

## Matrice d’audit initiale

| Sujet observé avant 38b | État | Fait observé | Décision |
|---|---|---|---|
| Liste personnes et organisations | présent | `BusinessRelationRepository` unifiait déjà les deux types, avec recherche, pagination, tableau et cartes mobiles. | conserver et enrichir |
| Fiche relation | partiel | Identité, coordonnées, audit, entités liées et activité existaient ; commandes et factures étaient des placeholders. | remplacer les placeholders par le read model 360 |
| Mémos, messages et consentements | présent | Actions et historiques contextualisés existaient avec permissions distinctes. | réutiliser dans la chronologie |
| Activités Sale projetées | présent | `SaleCrmActivityProjectionService` projetait les outbox Sale sans rendre Sale dépendant du CRM. | réutiliser, sans lecture Sale directe |
| Liens vers dossiers propriétaires | partiel | Les activités portaient référence et métadonnées, mais la fiche n’exposait pas de sections transactionnelles. | ajouter liens avec contexte de retour |
| Soumissions Forms dans CRM | absent | Les réponses restaient uniquement dans la base Forms et aucun port d’activité n’existait. | ajouter un port et une projection minimisée |
| Résolution Forms → Relation | absent | Aucun identifiant relation signé ni file d’ambiguïté. | ajouter adressage explicite, preuve fiable et revue manuelle |
| Relation multi-rôle | à remplacer | Le statut CRM unique confondait cycle et rôle. | ajouter des rôles cumulables, conserver le statut en compatibilité |
| Prochaine action | absent | Aucun objet de suivi dédié dans la relation. | ajouter une tâche CRM et la vue À suivre |
| Rapprochement Sale/CRM/IAM | présent | Score expliqué, provenance, divergences, décision et audit existaient dans Ventes. | réutiliser depuis Relation, supprimer l’entrée Ventes |
| Isolation site | présent | Les dépôts et projections filtraient par `site_id`. | conserver et tester |
| Rétention | partiel | Les projections Sale et consentement portaient une rétention ; Forms n’avait pas de projection CRM. | propager uniquement la date, sans payload |

## Résultat implémenté

- `GET /admin/api/business/relations/{type}/{id}/360` assemble la relation, la prochaine action, les alertes, la chronologie, les commandes, le financier, la logistique, les formulaires et la disponibilité des consentements.
- Les rôles cumulables et tâches sont canoniques dans Business. Les routes d’écriture exigent `business.crm.manage`.
- La liste expose Tous, Prospects, Clients, Fournisseurs, À suivre et, avec droits avancés, Doublons à revoir. Les candidats doublons reposent uniquement sur un canal CRM vérifié partagé ; aucune fusion automatique n’en découle.
- Les préférences d’affichage sont stockées côté navigateur par site. La recherche saisie n’est pas persistée.
- `FormSubmissionActivitySink` découple Forms de Business. Le read model ne contient aucun champ de payload et ne conserve que référence, statut, provenance, résolution, résumé sûr et rétention.
- Un token HMAC site/form/relation permet l’adressage explicite. Une correspondance automatique n’est acceptée que si un contexte de confiance fournit un e-mail déjà vérifié et qu’un seul canal CRM vérifié correspond.
- Les cas sans preuve ou ambigus restent dans `Rattachements à vérifier`. Toute décision manuelle impose un motif et ajoute un audit immuable.
- L’ancienne route Identités Ventes redirige vers `/business/relations/advanced/profiles`. Le composant et les API de décision Sale existants sont réutilisés ; leur permission avancée reste exigée.
- Les liens projetés ouvrent la commande dans Ventes avec un retour encodé et validé vers la relation d’origine ; une destination de retour externe est refusée.
- Le schéma canonique permet une reconstruction neuve directe et la migration `0013_relation_360.sql` couvre séparément la mise à jour idempotente d’une base existante.

## Conclusions, limites et confiance

| Conclusion | Statut | Preuve | Limites / contradiction | Confiance |
|---|---|---|---|---:|
| Une relation retrouve ses données CRM et les projections Sale/Forms sans copie d’agrégat canonique. | démontré | Service 360, schéma minimisé et test `ownership`/absence de `payload_json`. | Les projections sont des read models et peuvent être momentanément en retard. | élevée |
| Une relation peut être à la fois cliente et fournisseur. | démontré | Deux rôles lus, puis remplacement audité par le test unitaire. | Le champ `status` historique demeure pour compatibilité et peut différer des rôles jusqu’à reprise des données. | élevée |
| Un formulaire adressé explicitement est rattaché et un cas ambigu n’est pas fusionné. | démontré | Token HMAC, projection `explicit`, file avancée `Rattachements à vérifier`, décision motivée et trigger d’immutabilité testés. | La file reste un outil avancé ; aucune suggestion fondée sur un signal faible n’est proposée. | élevée |
| Un e-mail saisi dans un formulaire public provoque un rattachement automatique. | contredit | Le handler public ne marque jamais l’e-mail soumis comme vérifié ; il reste donc `pending`. | Un flux authentifié futur pourra fournir `verified_email` via le port. | élevée |
| La chronologie couvre tous les états formulaire ouvert/répondu et toutes les factures. | partiellement démontré | Les types projetés existants sont regroupés et filtrables ; soumission reçue, commandes, paiements, remboursements et logistique sont pris en charge. | Forms ne produit pas encore d’événement ouvert/répondu ; la couverture facture dépend des événements Sale disponibles. | moyenne |
| Une panne CRM bloque ou altère une vente ou une soumission Forms. | contredit | Les sinks sont enveloppés par les domaines canoniques ; la fiche fournit un mode dégradé. | Le rattrapage Forms automatisé n’est pas encore un job d’exploitation. | élevée |
| Les consentements sensibles sont visibles par tout lecteur CRM. | contredit | Le contrôleur n’inclut les événements de consentement qu’avec `business.consent.read`. | Les résumés d’alerte ne doivent pas être enrichis de preuve sensible. | élevée |
| Le module RDV est implémenté. | absent | Aucun agrégat RDV n’est ajouté ; la chronologie accepte de futurs types. | Hors périmètre explicite. | élevée |

## Risques résiduels

- Les sous-requêtes de dernière activité et prochaine action doivent être mesurées sur une volumétrie réelle ; le lot ne démontre pas une performance à plusieurs millions d’activités.
- Le secret `APP_FORM_RELATION_SIGNING_KEY` doit être distinct, long et géré par l’environnement en production. Le fallback de développement n’est pas une configuration de production acceptable.
- Le retour contextualisé est démontré pour le détail Commande. Chaque futur écran propriétaire devra adopter le même contrat validé avant d’être revendiqué.
- La traduction anglaise exhaustive de l’ancien CRM reste une dette préexistante ; le lot ne doit pas être présenté comme entièrement bilingue avant un E2E FR/EN vert.

## Commandes de preuve

| Commande | Résultat observé |
|---|---|
| `sqlite3 :memory: < database/modules/business.sql` | succès, reconstruction du schéma canonique sans migration incrémentale |
| `php tools/php/tests/unit/business_relation_360_test.php` | succès, 41 assertions : reconstruction neuve, migration idempotente, rôles, tâches, projections Sale/Forms, consentement, isolation et mode dégradé |
| `php tools/php/tests/unit/business_crm_api_controller_test.php` | succès, 127 assertions, non-régression CRM |
| `php tools/php/tests/unit/sale_module_contracts_test.php` | succès, 950 assertions, contrats Ventes inchangés |
| `npm run build` dans `frontend/admin-vue` | succès, TypeScript et bundle admin, 249 modules transformés |
| `python3 tools/cms.py validate` | succès, 25 familles de validateurs dont schéma, permissions, API, i18n, sécurité, documentation et frontières de dépendances |
| `python3 tools/python/qualification/usability_commerce_gate.py` | succès après remplacement de la preuve littérale française par la clé i18n stable |
| `php tools/php/tests/run.php` | succès, toutes les suites fonctionnelles PHP ; quatre intégrations HTTP ignorées car le sandbox de cette commande interdit l’ouverture TCP |
| `python3 tools/cms.py docs evaluation-generate` puis `python3 tools/cms.py docs evaluation-check` | succès, 21 fichiers d’évaluation générés et 1 418 contrôles documentaires |
| Playwright ciblé `admin-i18n.spec.ts business-relation-360.spec.ts` sur instance isolée | succès, 5 scénarios en 3,2 minutes : FR/EN, sections, rapprochement, persistance/mobile, filtre clavier et pagination |
| `python3 tools/cms.py qualify --profile complete --no-cache` | succès au second passage : tests, validateurs, sauvegarde/restauration, dépendances, build, gates M5–M7, documentation et export statique à blanc |

## Incidents de validation corrigés

- Un passage E2E complet a exécuté 47 scénarios : 45 ont réussi et deux assertions de test ont échoué. La première dépendait à tort d’une relation créée par un scénario précédent ; la seconde trouvait le même libellé dans la vue et dans le JSON technique replié. Les tests créent désormais leur propre relation et utilisent une correspondance exacte. Les cinq scénarios concernés ont ensuite réussi sur une instance neuve isolée.
- Le premier passage de qualification complète a échoué sur deux gates statiques qui cherchaient encore `Revue des identités` et trois libellés français désormais internationalisés. Les preuves pointent maintenant vers `Rapprochement de profils` et les clés i18n stables ; les huit tests des deux gates puis la qualification complète sont verts.
- Deux tentatives avec « evaluation » comme commande racine ont été refusées par le CLI. Les commandes canoniques `python3 tools/cms.py docs evaluation-generate` et `python3 tools/cms.py docs evaluation-check` ont ensuite réussi.

La release, son inspection et l’installation depuis archive ne sont pas revendiquées par le lot 38b tant qu’elles ne sont pas exécutées explicitement.
