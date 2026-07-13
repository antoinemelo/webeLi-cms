---
title: Revue finale module Vente
audience:
  - evaluator
  - administrator
  - developer
status: draft
last_verified: 2026-07-10
source_of_truth: analysis
source_paths:
  - backend/src/Modules/Sale
  - backend/src/Application/Api/Admin/SaleAdminApiController.php
  - backend/src/Application/PublicApi/PublicSaleApiHandler.php
  - backend/src/Core/App.php
  - database/modules/sale.sql
  - database/migrations/sale/
  - frontend/admin-vue/src/views/modules/SaleView.vue
  - frontend/admin-vue/src/views/modules/SalePosView.vue
  - tools/php/tests/unit/sale_module_contracts_test.php
  - tools/php/tests/unit/sale_domain_workflows_test.php
  - tools/php/tests/unit/sale_admin_api_controller_test.php
  - tools/php/tests/unit/sale_public_api_handler_test.php
  - tools/php/tests/integration/sale_uses_business_sellable_snapshot_test.php
  - docs/business/vente.md
  - docs/development/architecture/sale-module.md
owners:
  - sale
  - business
document_type: evaluation
generated: false
---
# Revue finale module Vente

## Resume executif

Conclusion : pret pour v1 interne avec reserves.

Le module Vente couvre maintenant le perimetre transactionnel attendu : canaux,
paniers, commandes, paiements, POS, recus, retours/remboursements, stock
transactionnel, evenements/outbox, imports/exports et rapports. La separation
avec Opérations est globalement saine : Vente consomme le catalogue et les
clients via des ports/adaptateurs, conserve ses propres tables dans
`sale.sqlite` et copie les snapshots necessaires dans les paniers et commandes.

Aucun bloqueur du gate final n'a ete identifie lors de cette revue. Les tests
prouvent l'idempotence des workflows critiques, l'immuabilite des commandes par
snapshot, le masquage des prix d'achat publics, la fermeture de l'e-commerce
par defaut et l'ecriture de mouvements pour le stock transactionnel.

La reserve principale concerne l'industrialisation : la v1 reste un socle
interne, sans paiement online reel, sans shipping avance, sans POS offline et
sans validation terrain longue sur l'ergonomie caisse/commandes.

## Gate final

| Question | Resultat |
|---|---|
| Vente est separe d'Opérations | Conforme : ports `SellableCatalogPort` et `CustomerSnapshotPort`, adaptateurs Business optionnels et adaptateurs indisponibles/null. |
| `sale.sqlite` est reconstruit from scratch | Conforme : `database/modules/sale.sql`, smoke Sale et tests schema passent. |
| Dependances Business propres | Conforme : pas de FK cross-database ; l'ajout catalogue echoue proprement si l'integration est indisponible. |
| Commandes avec snapshots necessaires | Conforme : `sale_order_lines` copie SKU, noms, prix, taxe, devise et `snapshot_json`. |
| Montants en unites mineures | Conforme : schema et services utilisent `*_minor`, tests pricing et smoke couvrent l'absence de `REAL`. |
| Paiements separes des commandes | Conforme : intentions, transactions, allocations et refunds separes de `sale_orders`. |
| Idempotence actions critiques | Conforme : checkout, paiement, remboursement, checkout POS et ajout ligne sont couverts. |
| POS sans client CRM | Conforme : le checkout POS fonctionne sans contact CRM. |
| Prix achat proteges | Conforme : payloads publics masquent `unit_purchase_price_minor`, export achat exige permission et flag explicite. |
| API publique contrôlée | Conforme : `web-main` est actif/public pour les tests, les routes module sont désactivées par défaut en production et tout canal non actif/public est refusé. |
| Tests des invariants | Conforme : suite PHP Sale, integration Business/Sale et suite Python globale passent. |
| Documentation honnete | Conforme : limites v1 documentees dans guides utilisateur, architecture et API interne. |

Decision gate : ne pas bloquer le jalon 24. Ne pas annoncer une disponibilite
production large sans la checklist v1 ci-dessous.

## Points solides

- Domaine transactionnel separe : `sale.sqlite` porte paniers, commandes,
  paiements, POS, stock transactionnel, events et outbox.
- Integration Opérations par ports : les services Vente ne lisent pas les
  tables Business directement pour les workflows critiques.
- Snapshots commandes robustes : une commande placee ne depend plus du
  catalogue courant pour ses libelles, prix, taxes et remises.
- Pricing deterministe : montants mineurs entiers, taxes en basis points,
  remises plafonnees et total non negatif.
- Paiements et remboursements auditables : transactions et refunds conservent
  les traces sans supprimer le paiement source.
- Idempotence explicite : les scopes critiques sont stockes dans
  `sale_idempotency_keys`.
- Stock transactionnel auditable : reservations, releases, ventes et retours
  ecrivent des mouvements.
- POS operationnel en back-office : session caisse, panier POS, paiement, recu
  et envoi courriel sont couverts par tests.
- API publique e-commerce defensive : routes optionnelles, canal public requis,
  token panier hashe et pas de listing public de commandes.
- Documentation utilisateur/developpeur complete pour une v1 interne.

## Fragilites

- Le POS est utilisable mais reste une experience back-office. Le POS cible
  final est annonce comme webapp separee ; il faudra revalider les flux quand
  cette surface existera.
- Les providers paiement v1 sont locaux ou simulés. Aucun flux carte online
  complet, webhook provider, SCA ou rapprochement externe n'est implemente.
- Les rapports v1 sont utiles pour le pilotage, mais ne remplacent pas un export
  comptable ou une BI certifiee.
- Les routes module Vente sont chargees dynamiquement par `moduleRoutes()` ;
  la coherence entre provider, contracts, registry genere et routes centrales
  doit rester surveillee lors des prochains ajouts.
- L'e-commerce public existe cote API, mais sans parcours CMS complet de compte,
  panier UI, paiement online ou shipping.
- Les contextes IA sont correctement locaux et `external_ai_allowed=false`, mais
  aucun worker IA/CRM/CMS n'est branche.

## Bugs bloquants

Aucun bug bloquant restant identifie.

Les conditions de blocage explicites du jalon sont couvertes :

- checkout idempotent : couvert par `SaleCheckoutService` et tests admin/public ;
- commande non recalculee depuis catalogue : couvert par
  `sale_domain_workflows_test.php` ;
- prix achat non public : couvert par snapshot, public API et integration ;
- stock modifie avec mouvement : couvert par `sale_inventory_service_test.php`
  et `sale_module_contracts_test.php` ;
- rebuild/validate/docs : validations executees avec succes ;
- API publique contrôlée : garde-fou de configuration en production et test du refus d'un canal brouillon/non public.

## Risques de donnees

- Les imports stock CSV appliquent des ajustements directs. Ils sont controles,
  mais une erreur operateur peut produire un stock faux. Garder la preview et
  les mouvements comme garde-fous.
- Les remboursements sont separes du retour physique. C'est sain, mais les
  utilisateurs doivent comprendre qu'annulation, retour et remboursement ne sont
  pas interchangeables.
- Les snapshots protegent l'historique, mais une migration future de schema de
  snapshot doit rester compatible avec les anciennes commandes.
- Le stock Vente et le stock resume Opérations peuvent diverger si un futur
  synchroniseur event/outbox est partiellement deploye.
- Le champ prix achat existe dans certaines tables snapshots admin. Les exports
  et payloads publics le masquent, mais les permissions doivent rester auditees
  a chaque nouvelle route.

## Risques UX

- Le POS back-office peut etre suffisant pour tester, mais une caisse reelle
  demande des raccourcis, une gestion d'erreurs tres claire et une impression
  fiable sur materiel cible.
- Les termes paiement, verse, avance, solde et rendu/trop-percu doivent rester
  homogènes dans toute l'interface.
- Les commandes contiennent beaucoup d'etats. Une mauvaise presentation peut
  masquer la difference entre statut commande, statut paiement et fulfillment.
- Les imports/exports sont puissants mais peuvent etre confondus avec les
  exports catalogue Opérations. La navigation doit continuer a separer Vente et
  Opérations.

## Dette technique

- Ajouter des tests HTTP reels pour les routes Vente module si l'environnement
  de test permet un bind TCP stable.
- Ajouter une verification automatique entre `SaleModuleProvider::adminRoutes`,
  `apiContracts` et le registry admin genere, comme cela existe deja
  partiellement dans les tests de contrats.
- Formaliser un worker outbox generique avec retries, dead-letter et
  observabilite.
- Etendre la couverture frontend avec tests de parcours POS et commandes quand
  la webapp POS cible sera construite.
- Clarifier la strategie de synchronisation stock Vente vers resume catalogue.
- Ajouter une documentation operationnelle de restauration/audit pour
  `sale.sqlite`.

## Checklist avant v1

1. Rejouer `python3 tools/cms.py test --timeout 300 --target-duration 120`
   apres le dernier merge.
2. Faire un rebuild from scratch et verifier `sale.sqlite` sur une base propre.
3. Tester manuellement : ajout panier admin, checkout, paiement partiel,
   paiement complet, remboursement partiel, annulation.
4. Tester manuellement POS : ouverture session, vente cash, recu, envoi email,
   cloture avec ecart nul puis ecart non nul.
5. Tester un import stock avec preview, apply et export des mouvements.
6. Tester l'API publique en basculant temporairement `web-main` en brouillon/non
   public, puis en le réactivant sur une instance de preproduction.
7. Verifier les roles reels : utilisateur lecture, vendeur POS, gestionnaire
   stock, gestionnaire paiements, admin Vente.
8. Verifier qu'aucun export public ou payload public ne contient de prix achat.
9. Definir la politique de conservation des recus, refunds et outbox.
10. Documenter explicitement que le paiement online, le shipping avance et le
    POS offline restent hors v1.

## Recommandations

- Garder Vente comme module transactionnel autonome. Ne pas ajouter de FK ou
  lecture directe obligatoire vers `business.sqlite`.
- Brancher CRM/CMS uniquement via outbox/adaptateurs lors d'un jalon separe.
- Avant paiement online, introduire des tests provider avec webhooks signes,
  idempotence provider et reconciliation.
- Avant POS webapp, figer un contrat HTTP POS minimal et le tester avec des
  fixtures de caisse.
- Avant activation e-commerce, ajouter panier UI/CMS, compte/profil, shipping
  v1 et politique de paiement claire.
- Continuer a traiter les exports comme une surface sensible : permissions,
  filtres site/canal et masquage par defaut.

## Tests executes

Commandes executees pendant la revue finale :

```bash
python3 tools/cms.py validate
python3 tools/cms.py docs generate
python3 tools/cms.py docs check
python3 tools/cms.py smoke
python3 tools/cms.py test --timeout 300 --target-duration 120
```

Resultats :

- `validate` : OK.
- `docs generate` : OK.
- `docs check` : OK.
- `smoke` : OK.
- `test --timeout 300 --target-duration 120` : OK, 86 tests, 1 skip attendu.

## Decision

Decision recommandee : pret pour v1 interne avec reserves.

Le module ne doit pas encore etre presente comme une solution e-commerce/POS
complete. Il peut en revanche servir de base transactionnelle Vente pour les
commandes, paiements locaux, POS back-office, stock transactionnel, rapports et
integrations futures par outbox.
