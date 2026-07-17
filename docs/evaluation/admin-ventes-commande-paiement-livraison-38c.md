---
title: Audit et preuve — Ventes, commande, paiement et livraison (38c)
audience:
  - evaluator
  - administrator
  - developer
status: current
last_verified: 2026-07-15
source_of_truth: code
source_paths:
  - backend/src/Modules/Sale/Services/SaleStateMachineService.php
  - backend/src/Modules/Sale/Services/SaleOrderDossierService.php
  - backend/src/Modules/Sale/Services/SaleDeferredPaymentService.php
  - backend/src/Modules/Sale/Services/SaleOrderDocumentService.php
  - frontend/admin-vue/src/views/modules/SaleView.vue
  - frontend/admin-vue/src/views/modules/SalePosView.vue
  - tools/php/tests/unit/sale_order_dossier_workflow_test.php
owners:
  - sale
document_type: evaluation
generated: false
---
# Audit et preuve — Ventes, commande, paiement et livraison (38c)

## Périmètre et règle d’architecture

L’audit a examiné les listes et détails de commandes, intentions et transactions de paiement, remboursements, reçus, retours, réservations, backorders, préparations, retraits, expéditions, POS, snapshots, événements CRM, permissions, routes et libellés internes. Les fichiers prioritaires sont le schéma Sale, `SaleStateMachineService`, les services checkout/paiement/POS/fulfillment/reçu/timeline, le contrôleur admin et les vues Ventes/POS.

La décision structurante est de conserver les machines existantes comme seules autorités transactionnelles. `SaleOrderDossierService` est un read model : il traduit le triplet commande/paiement/fulfillment, mais n’écrit aucun statut. `SaleDeferredPaymentService` orchestre une intention existante au signal de disponibilité. Il ne crée pas une machine de commande concurrente.

## État réel observé avant 38c

| Sujet | Fait observé | Statut initial | Décision |
|---|---|---|---|
| Commande | Snapshots client, adresse, livraison, lignes, prix et taxe déjà présents et protégés après placement. | démontré | conserver |
| États | Machines distinctes commande, intention, fulfillment, retour et remboursement, avec historique corrélé. | démontré | étendre la traduction, pas les dupliquer |
| Paiement e-commerce | Intention provider, webhook idempotent, refus/reprise, autorisation/capture et `payment_proof=false` au retour navigateur. | démontré | réutiliser |
| Produit sur commande | Backorders et capture différée existaient, mais aucune politique visible ne liait disponibilité, demande de paiement, expiration et prix. | partiellement démontré | ajouter le plan d’orchestration |
| POS | Vente immédiate, paiement et ticket existaient ; zéro/partiel était techniquement possible sans choix métier visible. | partiellement démontré | rendre les modes explicites et refuser remise immédiate non payée |
| Documents | Reçus/confirmations existaient, mais pas de facture, note de crédit et bon de livraison canoniques distincts. | contredit pour « facturation complète » | ajouter des snapshots documentaires immuables |
| Livraison | File avancée complète, mais séparée du dossier Commande. | démontré techniquement, fragile fonctionnellement | exposer actions et résumé dans le dossier |
| Navigation | Commandes, Paiements et factures, POS et Réglages existaient ; diagnostics provider restaient mêlés aux écrans principaux. | partiel | déplacer les diagnostics sous Outils avancés |
| Tableau de bord | Chiffre du jour, volumes et éléments récents, mais pas uniquement du travail actionnable. | contredit | remplacer par À traiter |

## Machine réelle et traduction opérateur

```text
Panier validé
  ├─ prépayé ─> commande pending_payment ─> intention ─> webhook/capture prouvée ─> placed/paid
  ├─ sur commande ─> commande pending_payment + plan waiting_availability
  │                    └─ disponibilité ─> intention/lien idempotent ─> paiement prouvé ─> placed/paid
  └─ POS immédiat ─> commande placed ─> paiement prouvé ─> ticket POS

placed/paid ─> fulfillment pending ─> allocated ─> preparing
  ├─ pickup ─> ready_for_pickup ─> handed_over
  └─ shipping ─> shipped ─> delivered

retour ─> remboursement ─> note de crédit
```

| État interne | État utilisateur | Prochaine action | Limite ou blocage |
|---|---|---|---|
| intention `failed/expired` | Paiement à reprendre | envoyer une demande/lien | aucun paiement démontré |
| backorder + plan `waiting_availability` | En attente de disponibilité | surveiller puis marquer disponible | facture finale et livraison bloquées |
| paiement `unpaid/pending` | Paiement attendu | demander le paiement | livraison bloquée par politique |
| paiement `authorized` | Paiement autorisé | capturer quand prêt | capacité/validité provider requise |
| paiement `partially_paid` | Solde à encaisser | demander le solde | acompte ≠ paiement complet |
| paiement `paid`, fulfillment `unfulfilled` | À préparer | créer/allouer une préparation | emplacement de stock requis |
| fulfillment partiel | Préparation en cours | poursuivre | quantités restantes |
| fulfillment `fulfilled` | À clôturer | terminer la commande | paiement déjà exigé |
| commande terminale | Terminée/Annulée | aucune | nouvelle transition refusée |

## Résultat implémenté

- `GET /admin/api/sale/orders/{id}/dossier` regroupe en un contrat la commande, la relation, le canal, l’état utilisateur, la prochaine action, les blocages, paiements, plan différé, remboursements, préparations, backorders, documents et timeline.
- Le tableau de bord expose uniquement les commandes nécessitant une action, ordonnées par priorité dérivée. Les métriques de vanité ne pilotent plus le parcours quotidien.
- Le dossier rend les actions dépendantes de l’état et de la permission. Une action impossible reste visible ou expliquée par son blocage ; les outils provider ne sont pas mélangés au dossier.
- La préparation, l’expédition et la remise sont accessibles depuis la commande avec `sale.fulfillment.manage`. Le service refuse l’entrée en préparation sans paiement autorisé ou acquis, puis exige un paiement acquis avant expédition, remise ou livraison ; cette règle ne dépend donc pas du seul masquage d’un bouton. La file logistique globale et les diagnostics restent avancés.
- Le flux sur commande exige une disponibilité attendue et l’acceptation explicite des conditions. Aucun intent n’est créé avant disponibilité. Le prix est figé par défaut ; la politique de recalcul reste déclarée et doit être assumée explicitement.
- Le signal de disponibilité crée ou rejoue une demande de paiement idempotente via le provider existant. Une autorisation expirée peut donc être remplacée par un nouveau lien sans être présentée comme payée.
- Le POS impose un choix explicite entre emport immédiat, retrait, livraison ou produit sur commande, et entre paiement immédiat, ultérieur ou acompte. Une remise immédiate sans paiement complet est refusée.
- `sale_order_documents` distingue confirmation, facture, note de crédit, bon de livraison et ticket POS. La facture exige un paiement prouvé ; la note de crédit un remboursement ; le bon une exécution logistique ; le ticket final une vente POS payée. Les snapshots émis sont immuables et versionnés.
- Les réglages expliquent paiement avant livraison, différé à disponibilité, prix, acompte, délai/rappels, documents, POS différé et permissions d’approbation.

## Conclusions, preuves, limites et confiance

| Conclusion | Statut | Preuve principale | Limites / contradiction | Confiance |
|---|---|---|---|---:|
| Le dossier Commande est le centre opérationnel commun aux trois canaux. | démontré | contrat dossier, vue admin, test unitaire de convergence et E2E desktop/mobile/clavier/FR/EN | l’E2E du dossier utilise des réponses API contrôlées ; la transaction réelle est prouvée séparément au niveau service | élevée |
| Une redirection de paiement prouve un encaissement. | contredit | `browserReturn()` retourne `payment_proof=false`; seul webhook/confirmation alloue le paiement | dépend de la bonne configuration webhook en production | élevée |
| Un produit sur commande est facturé et payé immédiatement par défaut. | contredit | plan `waiting_availability`, zéro intention avant disponibilité, facture refusée | l’acompte public utilise encore le parcours de solde opérateur ; le provider partiel public n’est pas généralisé | élevée |
| Le paiement à disponibilité crée une demande unique et rejouable. | démontré | contrainte unique du plan, intention provider idempotente et 20 assertions ciblées | rappels planifiés et annulation automatique sont modélisés mais le scheduler dédié reste à brancher | élevée |
| Tous les documents relèvent d’une facturation comptable complète. | contredit | documents commerciaux immuables présents ; aucune comptabilité générale, numérotation fiscale multi-pays ou export comptable complet | ne pas présenter comme ERP comptable | élevée |
| Paiement, facture, ticket et bon de livraison sont interchangeables. | contredit | types, règles d’émission et snapshots séparés | l’ancien `sale_receipts` reste conservé en compatibilité et apparaît comme document historique | élevée |
| La livraison ordinaire exige les outils avancés. | contredit | endpoints de dossier autorisés par `sale.fulfillment.manage`; seules files globales/diagnostics exigent `sale.advanced_tools.manage` | la création exige un emplacement issu de la réservation ou de la commande | élevée |
| Une commande impayée peut être préparée ou remise en appelant directement l’API. | contredit | gardes serveur dans `SaleFulfillmentService` et deux tests négatifs ciblant création de préparation et remise | une autorisation permet la préparation, mais jamais l’expédition/remise avant capture complète | élevée |
| Le POS couvre immédiat et différé dans le même dossier. | démontré | validation backend, choix UI, commande/plan communs, E2E immédiat et E2E 38c des choix différés | le paiement provider partiel public demeure une limite distincte | élevée |
| Les contrats Relation aller-retour restent cohérents. | démontré | lien typé contact/société, `return_to` validé et E2E Relation 360 de la même passe | la cohérence dépend du maintien du contrat de route partagé | élevée |

## Contradictions et éléments non vérifiés

- « Paiements et factures » ne signifie pas facturation comptable complète : la portée démontrée est l’émission de documents commerciaux figés.
- La présence historique de `sale_receipts`, de routes fulfillment ou de classes provider ne prouvait pas leur intégration dans un parcours opérateur ; le dossier et ses tests constituent la preuve nouvelle.
- L’envoi automatique des rappels, l’annulation à échéance et le recalcul effectif d’un prix à disponibilité ne sont pas démontrés. Le schéma et les politiques les rendent explicites, mais un job d’exploitation et ses tests restent nécessaires.
- La file À traiter dérive au plus 200 commandes par appel et assemble actuellement chaque dossier séparément. Son exactitude fonctionnelle est démontrée, pas sa performance à forte volumétrie ; un read model matérialisé ou une requête agrégée sera nécessaire avant de revendiquer une tenue à plusieurs centaines de milliers de commandes.
- Le paiement public d’un acompte par session provider partielle n’est pas démontré ; l’acompte POS/manuel et le solde explicite sont couverts par les transactions partielles existantes.
- Aucun achat réel, webhook externe, e-mail réel ou comptabilité tierce n’est exécuté par les tests locaux.

## Commandes de preuve exécutées

| Commande | Résultat observé |
|---|---|
| `php -l` sur les trois nouveaux services, contrôleurs, factory, App et provider | succès, aucune erreur de syntaxe |
| `sqlite3 :memory: < database/modules/sale.sql` | succès, reconstruction neuve sans migration incrémentale |
| `sqlite3 :memory:` avec schéma puis migration `0011` exécutée deux fois et `PRAGMA foreign_key_check` | succès, migration de mise à jour idempotente et aucune violation de clé étrangère |
| `php tools/php/tests/unit/sale_order_dossier_workflow_test.php` | succès, 20 assertions : absence de paiement initial, facture refusée, disponibilité idempotente, webhook, facture, dossier et À traiter |
| `php tools/php/tests/unit/sale_logistics_workflows_test.php` | succès, 22 assertions, dont refus serveur d’une préparation impayée et d’une remise sans paiement acquis |
| `php tools/php/tests/unit/sale_module_contracts_test.php` | succès, 972 assertions de routes, contrats, permissions, schéma et frontières |
| `php tools/php/tests/unit/sale_admin_api_controller_test.php` | succès, 238 assertions |
| `php tools/php/tests/unit/sale_public_api_handler_test.php` | succès, 84 assertions e-commerce publiques |
| `npm run build` dans `frontend/admin-vue` | succès, TypeScript et bundle, 249 modules transformés |
| `php tools/php/tests/run.php` | succès de toutes les suites fonctionnelles ; quatre intégrations HTTP ignorées car ce sandbox interdit l’ouverture TCP dans cette commande |
| `python3 tools/cms.py validate` | succès, 25 familles dont état Sale, schémas, migrations, permissions, API, sécurité, i18n, documentation et frontières |
| `python3 tools/cms.py docs evaluation-generate` puis `python3 tools/cms.py docs evaluation-check` | succès, 21 fichiers JSON générés ; contrôle final après ajout de la preuve 38c : 1 430 contrôles documentaires |
| `python3 tools/cms.py qualify --profile complete --no-cache` | succès : tests, validateurs, runtime, sauvegarde/restauration, dépendances, build, gates M5–M7, docs et export statique à blanc |
| `python3 tools/cms.py e2e --use-built-assets` | succès, 49/49 scénarios en 12,4 minutes sur instance neuve isolée ; le dossier différé desktop/mobile/clavier/FR/EN et les choix POS utilisent des réponses API contrôlées, tandis que le parcours POS immédiat et le checkout e-commerce exécutent les API réelles |

La release et l’installation depuis archive ne sont pas revendiquées ici : elles n’ont pas été exécutées dans le lot 38c.
