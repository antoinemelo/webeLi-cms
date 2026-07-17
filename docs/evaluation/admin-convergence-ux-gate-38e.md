---
title: Gate UX de convergence Ventes, Opérations, CRM et Commerce (38e)
audience:
  - evaluator
  - administrator
  - developer
status: current
last_verified: 2026-07-15
source_of_truth: code
source_paths:
  - docs/evaluation/machine-readable/admin-convergence-ux-38e.json
  - frontend/admin-vue/tests/e2e/admin-convergence-gate-38e.spec.ts
  - tools/python/qualification/admin_convergence_gate.py
  - docs/reference/admin-convergence-routes.md
  - docs/administration/admin-convergence-roles.md
owners:
  - core
  - business
  - sale
  - commerce
document_type: evaluation
generated: false
---

# Gate UX de convergence Ventes, Opérations, CRM et Commerce (38e)

## Décision

La gate agrège les preuves 38a à 38d et complète les gates 37/38. Elle ne transforme pas la présence d’une route ou d’une classe en preuve : les statuts `passed` de la source machine exigent une preuve PHP, E2E ou de validation explicitement référencée. Aucun P0 ouvert n’est accepté.

## Architecture finale

- **webeLi** désigne la plateforme.
- **DEC CMS Core** porte le noyau éditorial et technique.
- **Cockpit** pilote les tâches transversales, la qualité et la maintenance selon permission.
- **Studio** porte pages, articles, formulaires, SEO et médias.
- **Opérations** porte Relations, Produits/stock, Offres/marketing et Audiences.
- **Ventes** porte Commandes, Paiements/factures et POS.
- **Commerce** gouverne son panneau et, dans un lot ultérieur, l’activation explicite des Shops.

La carte détaillée des destinations et redirections est `docs/reference/admin-convergence-routes.md`. La matrice des rôles et cumuls de permissions est `docs/administration/admin-convergence-roles.md`.

## Couverture des scénarios

| Scénario | Comportement vérifié | Preuve principale | Limite |
|---|---|---|---|
| Service client / Relation | recherche globale, fiche Relation, sections commande/finance/livraison/formulaire, mémo et retour contextuel | E2E 38a, 38b et 38e ; test Relation 360 | données E2E synthétiques |
| Opérateur Ventes | file actionnable et dossier unique avec prochaine action | E2E et service dossier 38c | temps humain non mesuré |
| Produit sur commande | absence de facture anticipée, disponibilité, demande de paiement, reprise et séquence facture/livraison | E2E 38c/38e et tests différés | provider externe réel non appelé |
| POS | immédiat, différé, sur commande/acompte et même modèle Commande | E2E POS, omnicanal et 38c/38e | matériel caisse réel non testé |
| Produit et stock | quatre quantités, réception/inventaire avec aperçu, projection et ledger immuable | test 38d, E2E 38e et gate ledger | ACL utilisateur par emplacement absent |
| Offres et marketing | assistant, cumul, aperçu, conflit et Audience distincte du consentement | E2E et test 38d | coupons et portefeuille bon cadeau non couverts |
| Commerce | activation du module sans effet public, panneau permissionné, désactivation/réactivation sans suppression | test cycle de vie et E2E 38e | activation Shop volontairement non livrée avant 39 |
| Outils avancés | divulgation progressive et double garde serveur | tests contrôleurs Sale/Business et E2E 38e | composition des rôles dépend de l’instance |

## Revue manuelle structurée

Échelle heuristique : 0 bloque ; 1 exige correction ou dérogation ; 2 est acceptable avec amélioration ; 3 est satisfaisant. Les écrans de référence sont notés 2 ou 3 sur clarté, cohérence, prévention, récupération, efficacité, accessibilité, mobile et explication du statut. Aucun score 0 ou 1 n’est retenu dans l’état qualifié.

Observations structurées :

- les tableaux principaux présentent des exceptions à traiter, pas des volumes sans action ;
- le dossier Commande et la fiche Relation indiquent un état et une prochaine action ;
- les termes réservation, fulfillment, identity bridge, provider et projection restent dans les diagnostics ou preuves techniques, pas dans l’action ordinaire requise ;
- les actions risquées de stock et d’offre possèdent un aperçu ;
- les erreurs E2E contrôlées conservent le formulaire et proposent une reprise ;
- les captures desktop/mobile sont générées depuis une reconstruction native, avec hash SHA-256 et contrôles de focus, libellés et débordement ;
- les fixtures sont fictives et les rapports excluent PII, secrets, cartes et texte libre.

## Mesures

Le rapport runtime collecte pour chacun des huit scénarios : résultat, étapes significatives, retours arrière, erreurs, durée technique, visibilité de la prochaine action et reprise. La durée est une baseline ; aucun seuil arbitraire n’est utilisé avant une mesure avec les rôles cibles.

## Écarts

- P0 : aucun dans le périmètre vérifié.
- P1 : organiser une étude avec les rôles réels ; ajouter un ACL utilisateur par emplacement avant délégation de stock fortement segmentée.
- P2 : agréger éventuellement les durées sans PII après gouvernance ; étendre navigateurs et technologies d’assistance.

## Limites

Une suite Playwright ne mesure pas la charge cognitive d’un utilisateur réel. Chromium ne couvre pas tous les navigateurs, zooms et lecteurs d’écran. Les paiements externes restent simulés ou testés via providers locaux. L’activation publique d’un Shop site/langue appartient au prompt 39 et n’est pas présentée comme réussie ici.

La précondition littérale « boutique encore inactive » est contredite pour le site principal de la fixture native : une configuration publique Sale y existe déjà. Le contrôle 38e démontre donc que l’activation/désactivation du **module Commerce laisse l’état Shop existant inchangé** ; il ne prétend pas désactiver cette configuration. Les sites secondaires de la matrice restent affichés « Shop non activé ». L’action explicite d’activation d’un Shop n’existe pas encore et demeure hors périmètre avant 39.

## Commandes de preuve

Commandes exécutées le 2026-07-15 depuis la racine du dépôt :

| Commande | Résultat observé |
|---|---|
| `npm run build` dans `frontend/admin-vue` | succès ; TypeScript validé, 243 modules Vite transformés |
| `python3 tools/cms.py e2e --use-built-assets --admin-convergence-only` | succès final : 8 scénarios sur 8 en 2,0 min ; reconstruction native de l’instance isolée ; rapport runtime et 16 captures produits |
| `python3 tools/python/qualification/admin_convergence_gate.py` | succès ; rapport statique et rapport runtime acceptés |
| `python3 -m unittest tools.python.tests.test_admin_convergence_gate tools.python.tests.test_e2e_harness tools.python.tests.test_qualification_orchestrator` | succès : 19 tests |
| `php tools/php/tests/unit/business_pim_api_controller_test.php` | succès : 140 assertions |
| `php tools/php/tests/unit/sale_admin_api_controller_test.php` | succès : 238 assertions |
| `php tools/php/tests/unit/sale_module_contracts_test.php` | succès : 972 assertions |
| `php tools/php/tests/unit/business_operations_products_stock_offers_test.php` | succès : 16 assertions |
| `python3 tools/cms.py docs generate` | succès ; OpenAPI : 58 chemins, 62 opérations, 23 schémas ; types SDK régénérés |
| `python3 tools/cms.py docs evaluation-generate` | succès : 22 fichiers JSON d’évaluation régénérés |
| `python3 tools/cms.py docs check` | succès : 646 contrôles API et 1 448 contrôles documentaires |
| `python3 tools/cms.py docs evaluation-check` | succès : 1 448 contrôles documentaires |
| `python3 tools/cms.py validate --full --with-slow --no-fail-fast` | succès ; inclut schémas, permissions, sécurité, migration, export statique et sauvegarde/restauration (21 contrôles) |
| `python3 tools/cms.py qualify --profile release --no-cache` | première exécution en échec : manifeste CLI non exhaustif pour les deux nouveaux scripts 38e ; registre régénéré, test ciblé vert, puis seconde exécution entièrement réussie |

La seconde qualification `release --no-cache` a observé : suite unitaire/intégration verte, validateurs verts, backup/restauration vert, build vert, gates métier et 38e verts, E2E global vert en 773,803 s, baseline performance verte, génération/contrôle documentaire verts, export statique à blanc vert, préflight vert, archive créée et vérifiée, installation neuve et smoke test verts. Rapports : `storage/qualification/latest.json` et `storage/qualification/latest.md`.

Les exécutions ciblées Playwright antérieures ont échoué pendant la construction du gate (fixtures incomplètes, sélecteurs ambigus, débordements mobiles et formulaire Stock présent uniquement dans une section commentée). Ces échecs n’ont jamais été présentés comme des contrôles réussis ; ils ont conduit aux corrections produit et test, puis à l’exécution finale 8/8 et à l’E2E global vert.
