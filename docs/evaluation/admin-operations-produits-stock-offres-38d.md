---
title: Audit et preuve — Opérations, produits, stock et offres (38d)
audience:
  - evaluator
  - administrator
  - developer
status: current
last_verified: 2026-07-15
source_of_truth: code
source_paths:
  - backend/src/Modules/Business/Services/BusinessOperationsDashboardService.php
  - backend/src/Application/Api/Admin/BusinessCatalogApiController.php
  - backend/src/Modules/Sale/Services/SaleInventoryService.php
  - frontend/admin-vue/src/views/modules/BusinessCrmView.vue
  - frontend/admin-vue/src/views/modules/BusinessCatalogView.vue
  - tools/php/tests/unit/business_operations_products_stock_offers_test.php
owners:
  - business
  - sale
document_type: evaluation
generated: false
---

# Audit et preuve — Opérations, produits, stock et offres (38d)

## Périmètre observé

L’audit a lu le provider Business, les routes et contrôleurs admin, le catalogue/PIM, les complétudes, prix, médias, bundles, réductions, segmentation, mailing, le ledger et les réservations Sale, les projections Business/Storefront, les permissions, les schémas et les tests existants. Le code n’a été retenu comme preuve de fonctionnement que lorsqu’un test ou une exécution contrôlée confirmait le comportement.

## Matrice des tâches

| Domaine | Tâche ordinaire | Exception métier | Diagnostic avancé |
|---|---|---|---|
| Relations | ouvrir une Relation, traiter un suivi | rattachement ambigu de formulaire | profils et projections CRM |
| Produit | compléter contenu, média, variante, prix et canaux | produit non publiable ou variante incohérente | projection Storefront et recalcul de complétude |
| Stock | compter, réceptionner, constater perte/casse ou retour | disponible insuffisant, commande en attente | ledger, réservations, transfert, réconciliation |
| Offre | définir objectif, portée, avantage, période et activation | conflit de portée/période/canal | import de masse et données de priorité |
| Audience | lire la règle, le volume et la date de calcul | résultat vide ou règle obsolète | recalcul complet et projection d’activités |
| Communication | préparer et prévisualiser une campagne | consentement ou provider manquant | outbox/provider |

## Constat initial

| Fait observé | Statut initial | Preuve | Limite |
|---|---|---|---|
| Produits, variantes, prix, médias, complétude et bundles existaient. | démontré | services et tests catalogue/PIM/bundles | parcours stock séparé et message renvoyant vers Vente |
| Business refusait volontairement `createMovement()` car Sale était devenu canonique. | démontré | `CatalogStockRepository::createMovement()` levait `business.catalog.stock_transactional_source_sale` | l’interface conservait une fonction inutilisée : contradiction UX |
| La projection Sale alimentait Business et Storefront. | démontré | `SaleInventoryService::projectBusinessAvailability()` et tests de snapshots | la liste Produit additionnait encore les colonnes historiques des variantes |
| Réductions et bundles étaient administrables. | démontré | tests pricing/offres/bundles | aucun aperçu unifié de conflit avant activation |
| Les segments étaient explicables et séparés du consentement. | démontré | service de segmentation et 31 assertions | terminologie et entrée utilisateur encore « segment » dans certains libellés |
| Promotions panier et coupons étaient pleinement administrables dans Opérations. | non vérifié | tables Sale présentes, aucune route/vue admin correspondante observée | ne pas confondre schéma et fonctionnalité opérationnelle |

## Résultat implémenté

- La navigation ordinaire est limitée à **À traiter**, **Relations**, **Produits et stock**, **Offres et marketing** et **Réglages** selon permission. Audiences reste dans Offres et marketing ; aucun onglet Segments principal n’est ajouté.
- `BusinessOperationsDashboardService` assemble des files actionnables à partir des modèles Business et Sale, sans écrire de statut. Les métriques et listes récentes ne sont plus rendues sur le tableau principal.
- Les tâches diagnostics du ledger ne sont incluses que pour `business.advanced_tools.manage`.
- La liste Produit privilégie `business_inventory_availability_projections`; les schémas historiques sans projection conservent un repli d’amorçage explicite.
- La fiche variante affiche **En stock**, **Engagé**, **Disponible à la vente**, **Entrant**, le détail par emplacement et le ledger récent.
- Compte, réception, perte/casse, retour et correction suivent un aperçu avant/après avec motif obligatoire. La confirmation appelle `SaleInventoryService::adjust()` et projette le résultat vers Business.
- Les triggers `trg_sale_stock_movements_no_update/no_delete` et leurs équivalents Business interdisent la réécriture ou suppression d’un mouvement, y compris par un rôle avancé. Une correction crée un mouvement compensatoire.
- Le parcours réduction suit les sept étapes demandées. Son endpoint d’aperçu compte les produits, masque le volume d’Audience sans permission, signale les conflits et exige leur reconnaissance avant activation.
- Les libellés utilisateur présentent **Audiences** en français et en anglais. L’explication indique explicitement qu’une Audience n’est pas un consentement.
- Les Réglages sont organisés par intention et affichent portée, défaut, conséquence et objets concernés ; les providers restent permissionnés séparément.

## Conclusions, limites et confiance

| Conclusion | Statut | Preuve principale | Limites / contradiction | Confiance |
|---|---|---|---|---:|
| Sale est l’unique source transactionnelle du stock après amorçage. | démontré | mouvement via Sale, projection Business égale, test 38d | la colonne variante Business demeure pour l’initialisation et les anciens sous-schémas | élevée |
| Une correction ordinaire peut réécrire le ledger. | contredit | triggers SQLite et tests négatifs UPDATE/DELETE | un accès filesystem hors application pourrait remplacer toute la base ; relève de la sécurité d’exploitation | élevée |
| En stock, engagé, disponible et entrant sont distingués. | démontré | contrat stock opérationnel et vue variante | l’entrant couvre les transferts, pas un module achat fournisseur complet | élevée |
| Le dashboard est actionnable et permissionné. | démontré | service de files, UI, test ordinaire/avancé et E2E contrôlé | volumétrie extrême non testée ; plusieurs requêtes agrégées sont exécutées par chargement | élevée |
| Le parcours offre détecte les conflits avant activation. | démontré | aperçu repository/service/API, test de chevauchement et E2E d’erreur/reprise | la règle de cumul reste une priorité, pas un moteur universel de composition | élevée |
| Une Audience vaut consentement marketing. | contredit | `is_consent=false`, textes FR/EN et test segmentation/consentement | la campagne doit encore vérifier le consentement au moment de l’envoi | élevée |
| Coupons et bons cadeaux sont entièrement gérés dans la même vue. | partiellement démontré | bundles/réductions et type/politique bon cadeau existent ; tables coupons Sale observées | parcours admin coupon et portefeuille bon cadeau non démontrés | élevée |
| Les permissions sont limitées par site, canal et emplacement. | partiellement démontré | site et permissions serveur, emplacement validé dans Sale, canaux d’offre | aucun ACL utilisateur par emplacement distinct n’est démontré | moyenne |

## Matrice consommateur

| Donnée | Autorité | Consommateurs vérifiés |
|---|---|---|
| identité et contenu produit | Business/PIM | admin, snapshots Sale, Storefront |
| prix et réduction catalogue | Business | calcul de prix et snapshots Sale |
| quantité et réservations | Sale ledger | dossier Vente, projection Business, Storefront |
| Audience | Business CRM projection | aperçu marketing et campagnes autorisées |
| consentement | CRM consent ledger | messaging au moment de l’envoi, jamais l’Audience seule |

## Éléments non vérifiés ou absents

- Gestion Opérations complète des coupons Sale : **non vérifié**.
- Portefeuille financier, émission et remboursement d’un bon cadeau depuis cette vue : **non vérifié**.
- ACL par utilisateur et emplacement de stock : **absent** ; l’emplacement est validé par site et l’action par permission globale/site.
- Performance à plusieurs centaines de milliers de variantes, offres ou mouvements : **non vérifié**.
- Achat fournisseur et dates d’arrivage autres que transferts : **absent**.
- Envoi externe réel d’une campagne : **non exécuté**.

## Preuves automatisées dédiées

`business_operations_products_stock_offers_test.php` démontre en 16 assertions : réception, perte, retour, correction comptée, projection Sale→Business, refus de réécriture/suppression, refus du négatif, offre programmée, conflit, Audience distincte du consentement et visibilité ordinaire/avancée.

L’E2E `business-operations-products-stock-offers-38d.spec.ts` couvre dashboard desktop/mobile/clavier, absence de métrique récente rendue, assistant Offre, erreur puis reprise d’aperçu, conflit, Audience et libellé anglais. Les réponses du dashboard et de l’aperçu sont contrôlées ; les transactions stock réelles sont prouvées séparément au niveau service.

## Commandes de preuve

Toutes les lignes ci-dessous correspondent à une exécution effective dans ce lot. Une ligne verte ne couvre que le périmètre décrit ; elle ne vaut pas preuve d’une release ou d’une installation neuve.

| Commande exécutée | Résultat observé |
|---|---|
| `php tools/php/tests/unit/business_operations_products_stock_offers_test.php` | succès, 16 assertions |
| `php tools/php/tests/unit/business_catalog_api_controller_test.php` | succès, 91 assertions |
| `php tools/php/tests/unit/business_catalog_backend_services_test.php` | succès, 20 assertions |
| `php tools/php/tests/unit/business_crm_api_controller_test.php` | succès, 127 assertions |
| `php tools/php/tests/unit/sale_inventory_service_test.php` | succès, 50 assertions |
| `php tools/php/tests/unit/sale_inventory_reconciliation_test.php` | succès, 30 assertions |
| `php tools/php/tests/unit/sale_stock_reconstruction_scenario_test.php` | succès, 26 assertions |
| `php tools/php/tests/unit/business_pricing_offers_bundles_test.php` | succès, 17 assertions |
| `php tools/php/tests/unit/business_segmentation_consent_test.php` | succès, 31 assertions |
| `php tools/php/tests/unit/sale_module_contracts_test.php` | succès, 972 assertions |
| `php tools/php/tests/run.php` | suite fonctionnelle verte ; quatre intégrations TCP ignorées par le sandbox, donc non démontrées par cette commande |
| `sqlite3 :memory: ".read database/modules/business.sql"` | reconstruction Business neuve réussie |
| `sqlite3 :memory: ".read database/modules/sale.sql"` | reconstruction Sale neuve réussie |
| application deux fois de `database/migrations/business/0014_stock_ledger_immutability.sql`, puis `PRAGMA foreign_key_check` | succès, migration idempotente et aucune violation FK |
| application deux fois de `database/migrations/sale/0012_stock_ledger_immutability.sql`, puis `PRAGMA foreign_key_check` | succès, migration idempotente et aucune violation FK |
| `npm run build` dans `frontend/admin-vue` | succès, contrôle TypeScript puis build Vite, 243 modules transformés |
| `python3 tools/cms.py validate` | succès final, 25 familles ; API 646, i18n 6644, documentation 1441 et frontières de dépendances 660 |
| `python3 tools/cms.py docs evaluation-generate` | succès, 21 fichiers JSON générés |
| `python3 tools/cms.py docs evaluation-check` | succès final, 1441 contrôles documentaires |
| `python3 tools/cms.py qualify --profile complete --no-cache` | succès avant les derniers ajustements de libellés/tests : tests, validateurs, runtime, sauvegarde/restauration, dépendances, build, stock, CRM, paiements, documentation et export statique à blanc |
| première exécution de `python3 tools/cms.py e2e --use-built-assets` | 49 tests passés, 2 échecs de sélecteurs obsolètes ; commande en échec, donc non présentée comme réussie |
| seconde exécution de `python3 tools/cms.py e2e --use-built-assets` | 51 tests navigateur passés ; commande initialement en échec sur la preuve statique obsolète « CRM segment and consent » |
| `python3 tools/python/qualification/usability_commerce_gate.py` après correction Audience | succès |
| `python3 -m unittest tools.python.tests.test_qualification_orchestrator` | succès, 8 tests |
| `python3 tools/cms.py e2e --use-built-assets --usability-only` après correction de la preuve | succès, 1 test navigateur et gate d’utilisabilité validée |
| `git diff --check` | succès, aucune erreur d’espace ou de marqueur de conflit |

La création d’une archive de release, l’inspection de son contenu et l’installation de cette archive sur une nouvelle instance n’ont pas été exécutées dans le lot 38d. Elles ne sont donc pas présentées comme réussies. Le profil de qualification a exercé une sauvegarde/restauration et un export statique à blanc, mais pas un déploiement externe réel.
