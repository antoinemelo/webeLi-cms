---
title: Audit différentiel RM 1.0
audience:
  - evaluator
  - developer
  - administrator
status: draft
last_verified: 2026-07-10
source_of_truth: analysis
source_paths:
  - /home/amelo/Documents/DEV/Ecol_WebeLi/web/0_RMa/
  - backend/routes/api.php
  - backend/src/Core/App.php
  - backend/src/Module/ModuleRouteLoader.php
  - backend/src/Modules/Business/
  - backend/src/Modules/Sale/
  - backend/src/Application/PublicApi/
  - backend/src/Application/Capability/
  - backend/src/Security/PublicApiCorsGuard.php
  - database/modules/
  - database/migrations/
  - docs/public-api/openapi.v1.json
  - docs/reference/contracts/
  - packages/amcms-client/
  - tools/cms.py
owners:
  - core
  - business
  - sale
document_type: evaluation
generated: false
---
# Audit différentiel RM 1.0

## Résumé exécutif

Le dépôt contient déjà un socle CMS validé, un module Business/Opérations riche
et un module Sale/Vente transactionnel utilisable en v1 interne. La commande
`python3 tools/cms.py validate --full --no-fail-fast` passe sur l'état audité.

RM 1.0 n'est toutefois pas fermée. Les écarts prioritaires sont concentrés sur
le contrat public M0 : les routes publiques Sale existent dans le provider, mais
ne sont pas publiées dans les contrats headless, l'OpenAPI public ou les types
SDK ; les deux OpenAPI publics présents dans le dépôt ne décrivent pas le même
périmètre ; le CORS par défaut ne couvre pas les méthodes nécessaires au
checkout public ; et les capacités métier attendues par RM1 ne sont pas encore
exposées via le registre de capacités.

Le périmètre Business/PIM, CRM, Sale, POS, stock, paiements internes et
remboursements est majoritairement présent côté back-office. Les manques restent
principalement l'industrialisation publique : checkout complet documenté,
compte client, paiement provider réel, livraison, synchronisation outbox
robuste, tests HTTP/E2E de bout en bout et critères de performance.

## Commandes et preuves utilisées

| Preuve | Résultat |
|---|---|
| `python3 tools/cms.py validate --full --no-fail-fast` | OK : `API_SPEC`, `DEPENDENCY_BOUNDARIES`, `RUNTIME_INTEGRITY`, `DOCUMENTATION_CONTRACTS` et autres validateurs passent. |
| Inventaire statique `backend/routes/api.php` | 391 routes statiques : 33 sous `/api/v1`, 358 sous `/admin/api`, 185 sous `/admin/api/business`. |
| Inventaire OpenAPI `docs/public-api/openapi.v1.json` | 31 chemins, incluant catalogue public et POS catalog. |
| Inventaire OpenAPI `docs/reference/contracts/public-api/openapi.v1.json` | 21 chemins, sans catalogue public/POS catalog récent. |
| Inventaire contrats `docs/reference/contracts/headless-v1/*.json` | 29 contrats headless ; aucun contrat public Sale/cart/checkout. |
| Registry SQLite `storage/database/core.sqlite` | Modules `business` et `sale` installés/activés, version `0.1.0`; tables `module_routes`, `module_api_contracts`, `module_databases` vides sur cette instance. |
| Recherche capacités | Seule capacité core directe trouvée : `core.context.describe`; aucun provider module n'implémente `ModuleCapabilityProvider`. |
| Recherche lien contenu-produit | Aucun lien explicite `business_product_content_*` ou `content_entry_id` pour relier CMS/Core et Business/PIM. |
| Revue Sale existante | `docs/evaluation/sale-module-review.md` conclut à une v1 interne avec réserves : pas de paiement online réel, shipping avancé, POS offline ni parcours CMS e-commerce complet. |

## Matrice différentielle

| Exigence | État réel | Preuve | Écart | Priorité |
|---|---|---|---|---|
| Audit différentiel RM1 sans modification fonctionnelle | présent | Ce fichier ; prompt `0_RMa/prompts/00_AUDIT_DIFFERENTIEL_RM1.md`. | Aucun écart pour le lot 00 si ce rapport reste documentaire. | P0 |
| Validation structurelle du dépôt | présent | `python3 tools/cms.py validate --full --no-fail-fast` OK. | Ne remplace pas la qualification complète release/E2E/performance. | P0 |
| Inventaire routes statiques public/admin | présent | `backend/routes/api.php` : 391 routes statiques, dont 33 `/api/v1` et 358 `/admin/api`. | L'inventaire statique ne suffit pas pour les routes dynamiques de modules. | P0 |
| Chargement routes modules admin | présent | `backend/src/Core/App.php`, `backend/src/Module/ModuleRouteLoader.php`, `SaleModuleProvider::adminRoutes()`. | Les tables registry `module_routes` et `module_api_contracts` sont vides ; la vérité réelle reste le provider. | P1 |
| Chargement routes modules publiques | partiel | `SaleModuleProvider::publicHeadlessRoutes()` déclare 7 routes `/api/v1/sale/...`; `App::shouldLoadModuleRoutes()` ne charge le public que si `app.public_api_module_routes` est activé. | Routes publiques Sale absentes des contrats headless/OpenAPI/SDK et probablement désactivées par défaut. | P0 |
| Correspondance routes publiques/OpenAPI | partiel | `docs/public-api/openapi.v1.json` : 31 chemins ; `docs/reference/contracts/public-api/openapi.v1.json` : 21 chemins. | Deux sources OpenAPI divergent ; Sale public n'est dans aucune des deux. | P0 |
| SDK TypeScript public | partiel | `packages/amcms-client/src/generated/openapi-types.ts`, générateur `tools/python/generators/c44_generate_sdk_types_from_openapi.py`. | Types générés depuis les schémas OpenAPI seulement ; pas de client d'opérations complet et pas de Sale public. | P0 |
| Méthodes HTTP publiques RM1 | partiel | Routes statiques `/api/v1` : GET/POST seulement ; Sale public provider : GET/POST/PATCH/DELETE. | PATCH/DELETE du checkout public existent côté provider mais ne sont pas contractés ni validés en OpenAPI. | P0 |
| CORS public | partiel | `backend/src/Security/PublicApiCorsGuard.php` autorise par défaut `GET, OPTIONS` et headers `Authorization, Content-Type`. | Pour checkout public, il faut valider POST/PATCH/DELETE et `Idempotency-Key` en prévol OPTIONS. | P0 |
| Idempotency-Key | partiel | `PublicSaleApiHandler::idempotencyKey()`, `SaleIdempotencyService`, `SaleIdempotencyRepository`. | Couvert pour Sale, pas défini comme contrat transversal API publique ; CORS ne l'autorise pas par défaut. | P0 |
| Authentification, permissions, site isolation | présent | Validateurs `PERMISSION_MODEL`, `SECURITY_BASELINE`, routes admin avec permissions modules, services site-aware. | Les nouvelles routes publiques Sale devront avoir des tests HTTP explicites par site/canal. | P1 |
| Endpoints documentés sans 500 | non vérifié | `API_SPEC` passe, tests publics existants pour contenu/media/cookies/catalogue. | Pas de test HTTP complet sur toutes les routes documentées, notamment Sale public absent de l'OpenAPI. | P1 |
| Propriété CMS/Core contenu, blocs, routes, SEO | présent | Tables core `content_entries`, `layout_blocks`, `routes`, `seo_metadata`; validateurs content/projections OK. | À préserver lors du lien produit-contenu. | P1 |
| Propriété IAM | présent | Tables IAM et validateurs permissions ; docs générées permissions. | Pas d'écart RM1 identifié dans ce lot. | P2 |
| Propriété CRM Business | présent | `business.sqlite`, `backend/src/Modules/Business`, docs CRM, tests Business CRM. | Connecteur activité Sale -> CRM reste nul/incomplet. | P1 |
| Propriété PIM Business | présent | `business_products`, variantes, attributs, assets, tax classes ; docs Business/PIM ; tests catalogue. | Pas de lien canonique CMS content <-> product trouvé. | P1 |
| Prix et catalogues par canal | partiel | `BusinessCatalogPricingRepository`, `BusinessCatalogSellableReadService`, routes catalog/POS. | Listes de prix avancées par segment/client/date/priorité non prouvées dans cet audit. | P2 |
| Panier, commande, historique transactionnel | présent | `sale.sqlite`, `SaleCartService`, `SaleCheckoutService`, `SaleOrderRepository`, tests Sale. | Besoin de tests HTTP/E2E publics et critères de non-régression checkout. | P1 |
| Paiements internes, captures, remboursements | partiel | Tables payment intents/transactions/refunds, `SalePaymentService`, docs Sale. | Pas de provider online réel, webhook signé, SCA ou rapprochement externe. | P2 |
| Stock, réservations, mouvements | présent | Tables Sale stock/reservations/movements ; `sale_inventory_service_test.php`. | Synchronisation Business disponibilité <-> Sale stock à formaliser via outbox/projection. | P1 |
| Disponibilité catalogue calculée vers Business | partiel | Business expose stock, backorder, completeness ; Sale a stock transactionnel. | Projection retour Sale -> Business non industrialisée. | P1 |
| Accès directs inter-modules | partiel | Ports Sale `SellableCatalogPort`, `CustomerSnapshotPort` et adaptateurs Business ; `DEPENDENCY_BOUNDARIES` OK. | Le validateur est peu profond ; Business routes restent majoritairement centralisées dans `backend/routes/api.php`. | P1 |
| Adaptateurs nuls CRM/Sale | partiel | `NullCrmActivitySink`, `NullCmsAccountBridge`, `ServiceFactory::saleCrmActivitySink()`, `saleCmsAccountBridge()`. | Activité CRM et comptes clients ne sont pas branchés. | P1 |
| Capacités métier actionnables | absent | `CapabilityRegistry` core ; aucun module trouvé avec `ModuleCapabilityProvider`. | RM1 attend des capacités type catalogue/pricing/cart/checkout/payment/fulfillment/CRM. | P1 |
| PIM produits physiques/services/bundles/variantes | partiel | Business products, variants, bundles, services visibles dans code/tests. | Bons cadeaux et règles avancées non vérifiés. | P2 |
| Import/export catalogue | présent | `CatalogCsvService`, imports/exports Business et Sale, docs d'administration. | À relier aux critères de release RM1. | P2 |
| Checkout public | partiel | `PublicSaleApiHandler`, `SaleModuleProvider::publicHeadlessRoutes()`. | Non publié en OpenAPI/SDK ; pas de parcours CMS complet panier/paiement/livraison. | P0 |
| Comptes clients | absent | `NullCmsAccountBridge` ; pas de lien IAM/CRM/Sale public prouvé. | Comptes, profil, historique commandes et claim guest manquants. | P2 |
| Livraison | partiel | Champs shipping address/totals et fulfillment status dans Sale ; docs Sale mentionnent le shipping avancé hors v1. | Méthodes/zones/tarifs/transporteurs et fulfilment complet absents. | P2 |
| Fiscalité | partiel | `business_tax_classes`, tax rate snapshots, tax lines Sale. | Fiscalité avancée multi-pays/règles légales non prouvée. | P2 |
| Événements et outbox | partiel | `outbox_events` core, `sale_outbox`, `crm_message_outbox`, attempts/max_attempts côté Business. | Worker générique, dead-letter, retries observables et supervision non formalisés. | P1 |
| Interface française/anglaise | partiel | UI et docs majoritairement en français ; certains contrats/messages structurés. | Couverture i18n anglaise non auditée et nombreuses chaînes probablement codées en dur. | P2 |
| Documentation et aide par rôle | partiel | Docs administration, API, architecture, évaluation. | Aide contextuelle par rôle et parcours RM1 client/admin/dev à compléter. | P2 |
| Reconstruction from scratch | présent | `database/modules/*.sql`, `tools/cms.py validate`, tests smoke Python Business/Sale. | Qualification rebuild complète non rejouée dans ce lot. | P1 |
| Migrations | partiel | `database/migrations/business`, `database/migrations/sale`, `schema_migrations`. | Nouvelles migrations probables pour liens contenu-produit, comptes, fulfilment, outbox. | P1 |
| Sauvegarde/restauration | partiel | `tools/cms.py backup`, docs `reproducible-checks.md`, validateurs slow disponibles. | Roundtrip backup/restore toutes bases non exécuté dans cet audit. | P1 |
| Tests HTTP | partiel | Tests publics contenu/media/cookies/catalogue ; tests Sale unitaires et intégration. | Pas de matrice HTTP couvrant toutes les routes admin/module/public, surtout Sale public. | P1 |
| E2E | partiel | E2E blueprint, CRM, publication, webhook. | Pas d'E2E POS/Sale/checkout public RM1. | P1 |
| Qualification release | partiel | `tools/cms.py qualify --list`, validate OK. | Profil release complet, backup-restore, frontend build et static export non rejoués dans ce lot. | P1 |
| Observabilité/workers | partiel | Tables jobs/outbox/logs, docs runbook existantes. | État workers, métriques, retries, dead-letter et alerting RM1 non prouvés. | P1 |
| Performance | non vérifié | Aucun benchmark exécuté dans ce lot. | Définir budgets temps réponse API/admin/checkout/rebuild. | P2 |
| Audit dépendances/secrets/PII | partiel | `SECURITY_BASELINE` et `PII_EMAIL_GUARD` OK ; bloc Maintenance dépendances ajouté ailleurs. | Audit dépendances complet et secrets release à intégrer au gate RM1. | P1 |
| Non-régression recherche SSR | présent | `CONTENT_CONTRACTS`, `RUNTIME_INTEGRITY`, tests éditoriaux existants ; validate OK. | Garder test explicite dans release gate. | P1 |
| Non-régression cookies anonymes/OpenAPI | présent | Routes cookies public POST, contrat headless cookies, tests cookies publics ; validate OK. | Ajouter prévol CORS avec POST. | P1 |
| Non-régression média public SQL | présent | Tests `public_api_media_test.php`, validate OK. | Garder dans suite rapide. | P1 |
| Non-régression PII seeds/logs | présent | `PII_EMAIL_GUARD` OK. | Étendre à nouveaux seeds RM1. | P1 |
| Non-régression tests source vs release | partiel | Docs `reproducible-checks.md`, `release-audit-policy.md`. | Vérifier sur archive RM1 réelle. | P1 |

## Chemins de fichiers concernés

| Zone | Chemins |
|---|---|
| Roadmap RM1 | `/home/amelo/Documents/DEV/Ecol_WebeLi/web/0_RMa/README.md`, `01_SOCLE_COMMUN.md`, `prompts/00_*.md` à `prompts/14_*.md` |
| Routes runtime | `backend/routes/api.php`, `backend/src/Core/App.php`, `backend/src/Module/ModuleRouteLoader.php`, `backend/src/Modules/Sale/SaleModuleProvider.php`, `backend/src/Modules/Business/BusinessModuleProvider.php` |
| API publique | `backend/src/Application/Api/PublicHeadlessController.php`, `backend/src/Application/PublicApi/PublicCatalogApiHandler.php`, `backend/src/Application/PublicApi/PosCatalogApiHandler.php`, `backend/src/Application/PublicApi/PublicSaleApiHandler.php`, `backend/src/Security/PublicApiCorsGuard.php` |
| Contrats et OpenAPI | `docs/reference/contracts/headless-v1/`, `docs/public-api/openapi.v1.json`, `docs/public-api/openapi.v1.yaml`, `docs/reference/contracts/public-api/openapi.v1.json`, `tools/python/generators/c40_generate_openapi_headless_v1.py` |
| SDK | `packages/amcms-client/src/generated/openapi-types.ts`, `tools/python/generators/c44_generate_sdk_types_from_openapi.py` |
| Business/CRM/PIM | `backend/src/Modules/Business/`, `database/modules/business.sql`, `database/migrations/business/`, `docs/development/architecture/business-*.md` |
| Sale | `backend/src/Modules/Sale/`, `database/modules/sale.sql`, `database/migrations/sale/`, `docs/development/architecture/sale-module.md`, `docs/evaluation/sale-module-review.md` |
| Capacités | `backend/src/Application/Capability/`, `backend/src/Application/Api/Admin/CapabilityApiController.php` |
| Exploitation | `tools/cms.py`, `tools/python/validation/`, `tools/python/qualification/`, `docs/evaluation/reproducible-checks.md`, `docs/operations/` |

## Routes et contrats divergents

1. `docs/public-api/openapi.v1.json` et
   `docs/reference/contracts/public-api/openapi.v1.json` ne décrivent pas le
   même périmètre. Le premier contient 31 chemins, le second 21.
2. Les routes publiques catalogue et POS catalog sont présentes dans
   `docs/public-api/openapi.v1.json`, mais pas dans
   `docs/reference/contracts/public-api/openapi.v1.json`.
3. Les routes publiques Sale déclarées par
   `SaleModuleProvider::publicHeadlessRoutes()` ne sont pas présentes dans
   `docs/reference/contracts/headless-v1/`, ni dans
   `docs/public-api/openapi.v1.json`, ni dans les types SDK.
4. Les routes publiques statiques `/api/v1` utilisent seulement GET/POST. Les
   routes publiques Sale prévues ajoutent PATCH/DELETE pour les lignes de
   panier, sans contrat OpenAPI associé.
5. `PublicApiCorsGuard` autorise par défaut `GET, OPTIONS` et
   `Authorization, Content-Type`. Le checkout public a besoin d'un contrat CORS
   couvrant POST/PATCH/DELETE et `Idempotency-Key`.
6. La registry SQLite `module_routes` et `module_api_contracts` existe mais est
   vide sur l'instance auditée. La source de vérité effective des routes modules
   est donc le code provider, pas la registry persistée.

## Frontières modules violées ou fragiles

| Frontière | État | Commentaire |
|---|---|---|
| Sale -> Business catalogue | sain mais fragile | Sale passe par `SellableCatalogPort` et `BusinessSellableCatalogAdapter`; garder cette règle stricte. |
| Sale -> Business clients | sain mais incomplet | `CustomerSnapshotPort` existe ; activité CRM post-vente non branchée. |
| Sale -> CRM activity | incomplet | `NullCrmActivitySink` indique un connecteur volontairement nul. |
| Sale -> comptes CMS/IAM | incomplet | `NullCmsAccountBridge` indique que comptes publics et historique client ne sont pas branchés. |
| Business routes | fragile | Business expose beaucoup de routes depuis `backend/routes/api.php`; la gouvernance module provider n'est pas homogène avec Sale. |
| Registry modules | fragile | Tables `module_routes`, `module_api_contracts`, `module_databases` vides malgré modules installés. |
| Validation frontières | partielle | `DEPENDENCY_BOUNDARIES` passe, mais le validateur contrôle surtout des patterns directs ; il ne prouve pas toutes les dépendances métier. |
| CMS content -> Business product | absent | Aucun lien canonique contenu-produit trouvé ; éviter les FK inter-base et préférer projection/association par table propriétaire. |

## Migrations probablement nécessaires

Ces migrations ne sont pas à appliquer dans le lot 00. Elles listent les besoins
probables pour les prompts suivants.

| Besoin | Base probable | Objectif |
|---|---|---|
| Association contenu CMS <-> produit Business | `business.sqlite` ou projection dédiée sans FK inter-base | Relier fiches CMS, SEO et blocs éditoriaux aux produits sans déplacer la propriété canonique. |
| Comptes clients publics | `iam.sqlite`, `business.sqlite`, `sale.sqlite` | Lier user public, contact CRM, paniers et commandes ; gérer claim guest. |
| Fulfillment/livraison | `sale.sqlite` | Méthodes de livraison, zones, tarifs, expéditions, tracking, snapshots commande. |
| Outbox générique robuste | `core.sqlite` et/ou bases modules | Dead-letter, claims, backoff, métriques, état worker et replay. |
| Synchronisation stock/disponibilité | `sale.sqlite` + `business.sqlite` | Projeter disponibilité transactionnelle Sale vers catalogue Business. |
| Registre routes/contrats modules | `core.sqlite` ou génération docs | Aligner providers, registry persistée, OpenAPI et docs. |
| Capacités métier | `core.sqlite` si persistance nécessaire | Déclarer capacités RM1 avec schémas input/output, permissions, dry-run et audit. |
| Prix avancés | `business.sqlite` | Listes de prix par canal/segment/client/date si le prompt M2 les exige explicitement. |

## Dépendances entre travaux

1. Fermer M0 contrats publics avant d'industrialiser checkout public : routes,
   contrats headless, OpenAPI, SDK, CORS et tests HTTP doivent être cohérents.
2. Fermer outbox/worker avant les synchronisations CRM, stock et notifications.
3. Formaliser le lien CMS content -> Business product avant les pages produit
   publiques riches.
4. Stabiliser pricing/catalog snapshots avant commande, paiement et POS avancés.
5. Stabiliser compte client après guest checkout, mais avant historique public
   des commandes.
6. Ajouter fulfillment/livraison avant d'annoncer un checkout e-commerce complet.
7. Ajouter E2E/performance après les contrats HTTP stables, sinon les tests
   figeront des surfaces encore mouvantes.

## Découpage recommandé en pull requests

| PR | Contenu | Critère de fermeture |
|---|---|---|
| PR-00 Audit RM1 | Ce rapport uniquement. | Fichier `docs/evaluation/rm1-gap-analysis.md` présent, pas de changement runtime. |
| PR-M0-1 Contrats publics | Unifier source OpenAPI, ajouter contrats Sale public, régénérer SDK/types, documenter routes. | Diff runtime/OpenAPI nul pour les routes publiques supportées. |
| PR-M0-2 CORS et idempotence publique | OPTIONS tests, méthodes checkout, `Idempotency-Key`, erreurs contractées. | Tests HTTP prévol + replay idempotent passent. |
| PR-M0-3 Outbox/workers | Envelope commun, retries, dead-letter, état worker, commandes CLI. | Test worker/retry/dead-letter + doc runbook. |
| PR-M0-4 Gate release | Backup/restore toutes bases, qualification release, static export, audit secrets/PII. | `qualify` release reproductible sur instance propre. |
| PR-M1 Capacités/i18n/docs | Capacités métier minimales, aide par rôle, couverture FR/EN prioritaire. | Registry capacités testée, docs rôle complètes, chaînes critiques externalisées. |
| PR-M2 PIM/public product | Lien contenu-produit, pages produit, pricing public, imports/exports vérifiés. | Produit visible public depuis CMS + OpenAPI/SDK + tests. |
| PR-M3 Sale/POS interne | Renforcer POS, paiements internes, retours, rapports et E2E admin. | Parcours caisse/commande/remboursement E2E. |
| PR-M4 Checkout public | Panier public, compte/guest, paiement provider, livraison, emails. | Parcours achat public complet et mesuré. |

## Risques de compatibilité

- Publier les routes Sale publiques dans OpenAPI peut créer un contrat durable :
  ne les exposer qu'une fois les erreurs, CORS et permissions stabilisés.
- Activer `app.public_api_module_routes` expose des routes dynamiques ; vérifier
  site/canal public et désactivation par défaut.
- Unifier les deux OpenAPI peut casser des liens documentation existants si le
  chemin ancien est encore référencé.
- Étendre CORS augmente la surface publique ; limiter par site/origine et tester
  les prévols.
- Ajouter des liens contenu-produit ne doit pas introduire de FK inter-base ni
  déplacer la propriété canonique du PIM.
- Les migrations Business/Sale doivent être compatibles avec reconstruction
  from scratch et backup/restore.
- Les tests E2E peuvent devenir instables si ajoutés avant stabilisation des
  contrats HTTP.

## Critères mesurables de fermeture

| Écart | Critère mesurable |
|---|---|
| OpenAPI divergents | Un seul artefact public source de vérité ou deux fichiers générés identiques sur le périmètre supporté ; `docs check` vérifie l'absence de divergence. |
| Sale public non contracté | Chaque route `publicHeadlessRoutes()` a un contrat `docs/reference/contracts/headless-v1/*.json`, un chemin OpenAPI et un test HTTP. |
| SDK incomplet | Le package client expose au minimum les types et helpers/opérations pour chaque route publique supportée. |
| CORS incomplet | Tests OPTIONS pour GET/POST/PATCH/DELETE et `Idempotency-Key` sur origine autorisée/refusée. |
| Idempotence publique | Deux requêtes checkout identiques avec même clé retournent le même résultat sans double commande. |
| Registry modules vide | Provider, registry générée/persistée et documentation donnent les mêmes routes/contrats, ou la registry vide est explicitement documentée comme non source de vérité. |
| Capacités absentes | Capacité par workflow RM1 critique avec permission, schéma JSON, dry-run si possible, audit `action_runs`. |
| Compte client absent | Tests guest checkout, claim compte, historique commandes et isolation site. |
| Fulfillment absent | Méthodes de livraison, snapshot commande, statut fulfillment et test de changement d'état. |
| Outbox partielle | Worker avec claim, retry, dead-letter, métriques et replay testé. |
| Backup/restore partiel | Roundtrip restaure `core`, `iam`, `business`, `sale`, `forms`, `cookies`, `ai` et compare manifest/hashes. |
| E2E insuffisants | Parcours CRM, PIM produit public, POS, checkout public et webhook passent sur instance isolée. |
| Performance non vérifiée | Budgets définis et mesurés pour admin maintenance, catalogue public, checkout, rebuild search/projections. |

## Ordre recommandé des prompts suivants

1. Continuer avec le socle commun M0 : contrats HTTP, OpenAPI, SDK, CORS,
   idempotence et gate release.
2. Traiter l'outbox/workers avant toute synchronisation inter-module.
3. Passer à M1 pour capacités, i18n et documentation par rôle.
4. Passer à M2 pour PIM/catalogue public et lien CMS-produit.
5. Passer à M3 pour durcir Sale/POS interne.
6. Terminer par M4 pour checkout public complet, compte client, paiement
   provider, livraison et parcours e-commerce.

## Limites de cet audit

- Aucun test E2E ni qualification release complète n'a été relancé dans ce lot.
- Aucune migration, route, interface ou logique runtime n'a été modifiée.
- Les constats `non vérifié` indiquent une absence de preuve locale suffisante,
  pas nécessairement une absence de fonctionnalité.
