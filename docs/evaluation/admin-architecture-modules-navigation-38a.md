---
title: Audit et preuve — architecture des modules et navigation (38a)
audience:
  - evaluator
  - administrator
  - developer
status: current
last_verified: 2026-07-15
source_of_truth: code
source_paths:
  - frontend/admin-vue/src/views/modules/BusinessCrmView.vue
  - frontend/admin-vue/src/views/modules/SaleView.vue
  - frontend/admin-vue/src/views/modules/CommerceView.vue
  - backend/src/Application/Api/Admin/SaleAdminApiController.php
  - tools/php/tests/unit/commerce_module_lifecycle_test.php
  - tools/php/tests/unit/sale_admin_api_controller_test.php
owners:
  - core
  - business
  - sale
  - commerce
document_type: evaluation
generated: false
---
# Audit et preuve — architecture des modules et navigation (38a)

## Périmètre et faits observés avant modification

L’audit a porté sur les providers Business et Sale, le registre et le cycle de vie des modules, le routeur Vue, `AdminShell`, `GlobalSearch`, `BusinessCrmView`, `SaleView`, leurs vues techniques et les tests E2E associés.

Faits observés :

- Sale présentait neuf onglets ordinaires, dont Réservations, Stock, Opérations logistiques et Identités.
- Business regroupait CRM, segments, catalogue, offres et réglages dans des onglets pilotés par état local ; plusieurs destinations ne possédaient pas d’URL canonique.
- la recherche globale ne renvoyait que des actions et des contenus éditoriaux ; elle n’ouvrait ni relation, ni commande, ni produit ;
- les API techniques réutilisaient uniquement les permissions métier et ne possédaient pas de garde avancée commune ;
- aucun provider Commerce n’était déclaré ;
- le mécanisme existant de module savait découvrir, installer, activer et désactiver un provider sans suppression de données ;
- le Shop public et ses projections existaient indépendamment de tout module Commerce. Leur état devait donc rester strictement inchangé.

## Matrice de destination

| Écran avant 38a | Rôle principal | Tâche | Destination canonique | Visibilité | Permission minimale |
|---|---|---|---|---|---|
| Business / dashboard | Administrateur Opérations | Voir les éléments à traiter | `/business` | ordinaire | `business.crm.read` |
| Business / CRM, sociétés, contacts, mémos | Opérateur relations | Rechercher et gérer une relation | `/business/relations` | ordinaire | `business.crm.read` ou `business.memo.read` |
| Business / catalogue produits | Gestionnaire PIM | Gérer produits et stock PIM | `/business/products-stock` | ordinaire | `business.catalog.read` |
| Business / offres | Marketing | Gérer les offres | `/business/offers-marketing` | ordinaire | `business.catalog.read` |
| Business / segments | Marketing | Gérer les audiences | `/business/offers-marketing/audiences` | ordinaire | `business.segment.read` |
| Business / mailing et messaging | Marketing | Gérer les campagnes | `/business/offers-marketing/campaigns` | ordinaire | `business.mailing.read` ou `business.messaging.admin` |
| Business / providers | Administrateur Opérations | Régler les providers | `/business/settings` | ordinaire selon rôle | `business.messaging.admin` |
| Business / rapprochement activités Sale | Super-admin / développeur | Réparer une projection CRM | API depuis outils concernés | avancée | `business.advanced_tools.manage` + `business.crm.manage` |
| Business / reconstruction storefront | Super-admin / développeur | Reconstruire une projection storefront | API depuis outils concernés | avancée | `business.advanced_tools.manage` + `business.catalog.write` |
| Sale / dashboard | Opérateur Ventes | Voir les éléments à traiter | `/sale` | ordinaire | `sale.read` |
| Sale / commandes | Opérateur Ventes | Suivre une commande | `/sale/orders` | ordinaire | `sale.orders.read` |
| Sale / paiements | Opérateur Ventes | Suivre paiements et factures | `/sale/payments` | ordinaire | `sale.payments.read` |
| Sale / POS | Caissier | Utiliser un point de vente | `/sale/pos` | ordinaire si autorisé | `sale.pos.use` |
| Sale / réglages | Administrateur Ventes | Régler les canaux et méthodes | `/sale/settings` | ordinaire selon rôle | `sale.settings.manage` |
| Sale / stock et ledger | Super-admin / développeur | Diagnostiquer le stock transactionnel | `/sale/advanced/stock` | avancée | `sale.advanced_tools.manage` + `sale.stock.read` |
| Sale / réservations | Super-admin / développeur | Diagnostiquer les réservations | `/sale/advanced/reservations` | avancée | `sale.advanced_tools.manage` + `sale.stock.read` |
| Sale / opérations | Super-admin / développeur | Piloter l’exécution logistique interne | `/sale/advanced/logistics` | avancée | `sale.advanced_tools.manage` + permission logistique |
| Sale / identités | Super-admin / développeur | Rapprocher les identités | `/sale/advanced/identities` | avancée | `sale.advanced_tools.manage` + `sale.customer_accounts.manage` |
| Sale / diagnostics de paiement | Super-admin / développeur | Diagnostiquer provider, webhook et rapprochement | panneau technique permissionné | avancée | `sale.advanced_tools.manage` + permission paiement |
| Catalogue des modules / Commerce | Super-admin | Activer le panneau Commerce | `/modules/commerce` | catalogue | `modules.read` / `modules.manage` |
| Commerce | Responsable Commerce | Lire la matrice site × langue | `/commerce` | ordinaire si module actif | `commerce.read` |
| Shop site/langue | Responsable Commerce | Activer un Shop public | non livré dans 38a | future | `commerce.shops.manage` |

## Décisions et preuves de conception

- Les propriétaires internes restent inchangés : Business/PIM pour le produit, CRM pour les relations, Sale pour le transactionnel et DEC CMS Core pour les routes et contenus publics.
- `CommerceModuleProvider` n’a ni base, ni migration, ni seed, ni hook, ni route headless publique. Son unique route est l’API admin de matrice.
- La permission avancée est vérifiée dans les contrôleurs avant la permission métier. Un refus empêche donc le chargement des données, même avec une URL directe.
- Les anciennes URL Business et Sale sont des redirections explicites ; elles ne montent plus un écran concurrent.
- `ModuleSecondaryNavigation` utilise des liens natifs et un sélecteur mobile construit depuis la même liste permissionnée.
- La recherche globale interroge séparément contenus, relations, commandes et produits selon les permissions, puis produit des deep links avec identifiant.

## Limites

- Le lot 38a n’implémente volontairement pas l’activation d’un Shop site/langue.
- La matrice Commerce reflète une configuration publique existante mais ne la certifie pas comme fonctionnelle de bout en bout.
- Les rôles métier complets Opérations, Ventes et POS restent à composer dans IAM selon chaque instance ; la navigation se fonde sur les permissions effectives.
- L’activation d’un Shop site/langue, sa publication, son rollback et son exposition publique restent hors périmètre du lot 38a ; ils ne sont donc pas présentés comme réussis.

## Conclusions vérifiées

| Conclusion | Statut | Faits et preuve | Limites | Confiance |
|---|---|---|---|---:|
| La navigation ordinaire Ventes et Opérations est organisée par tâche, utilisable au clavier et remplacée par un sélecteur sur petit écran. | démontré | `admin-architecture-navigation.spec.ts`, `admin-i18n.spec.ts` et `business-crm-smoke.spec.ts` passent dans l’instance E2E isolée. | La validation visuelle exhaustive sur tous les navigateurs et lecteurs d’écran n’a pas été exécutée. | élevée |
| Les anciennes URL ciblées redirigent vers une destination canonique unique. | démontré | Les redirections Vue sont explicites et le scénario E2E contrôle `/sale/stock` et `/business/catalog`. | Les favoris externes non présents dans l’inventaire des routes n’ont pas été testés. | élevée |
| Une recherche autorisée peut ouvrir une relation, une commande ou un produit. | démontré | Le scénario E2E injecte des réponses distinctes, vérifie les trois types puis ouvre le deep link relation. Les branches sont conditionnées par `context.can(...)`. | Le classement de pertinence sur un grand volume de données n’est pas qualifié. | élevée |
| Une URL avancée Ventes exige une permission serveur dédiée en plus de la permission métier. | démontré | `sale_admin_api_controller_test.php` obtient un refus avec `sale.stock.read` sans `sale.advanced_tools.manage`; le contrôleur applique la garde commune avant les données techniques. | La composition finale des rôles appartient à chaque instance. | élevée |
| Commerce est découvrable et activable comme module sans créer route, menu ou projection publics. | démontré | `commerce_module_lifecycle_test.php` active réellement le module et compare avant/après les routes core, menus et mappings storefront ; 16 assertions passent. Le provider ne déclare aucun hook ni route publique. | Cela ne démontre pas une future activation de Shop, volontairement absente. | élevée |
| Le panneau Commerce actif expose une matrice de lecture site × langue et ne promet pas d’activation de Shop. | démontré | API admin permissionnée, vue dédiée et scénario E2E du catalogue ; les effets d’activation retournés sont tous `false`. | La matrice décrit la configuration existante, pas la santé fonctionnelle d’un Shop. | élevée |

Aucune contradiction résiduelle n’a été observée entre les contrats générés, le comportement E2E et les conclusions ci-dessus. La première tentative E2E a cependant révélé et permis de retirer une synchronisation globale trop coûteuse placée sur chaque requête HTTP ; ce comportement n’est pas conservé dans l’état qualifié.

## Commandes de preuve

Commandes exécutées depuis la racine du dépôt, sauf indication contraire :

| Commande | Résultat observé |
|---|---|
| `php tools/php/tests/unit/commerce_module_lifecycle_test.php` | succès, 16 assertions ; activation réelle du module sans effet public observé |
| `php tools/php/tests/unit/sale_admin_api_controller_test.php` | succès, 238 assertions ; refus avancé inclus |
| `php tools/php/tests/unit/sale_module_contracts_test.php` | succès, 950 assertions |
| `npm run build` depuis `frontend/admin-vue` | succès après correction de l’appel résiduel `go(...)`; `vue-tsc` sans erreur et Vite construit 243 modules |
| `python3 tools/cms.py validate` | succès, code 0 |
| `python3 tools/cms.py docs generate` puis `python3 tools/cms.py docs check` | succès ; 639 contrôles API_SPEC et 1 402 contrôles DOCUMENTATION_CONTRACTS |
| `python3 tools/cms.py docs evaluation-generate` puis `python3 tools/cms.py docs evaluation-check` | succès ; 21 artefacts JSON générés et cohérents au moment du contrôle |
| `python3 tools/cms.py qualify --profile quick --no-cache --no-reports` | succès, code 0 |
| `python3 tools/cms.py e2e --use-built-assets` | tentative initiale interrompue avant Playwright : serveur non prêt après 30 s ; aucune fonctionnalité déclarée réussie sur cette tentative |
| `python3 tools/cms.py e2e --use-built-assets --keep-instance` | même échec de démarrage ; le journal conservé a identifié la maintenance globale sur le chemin HTTP |
| `python3 tools/cms.py e2e --use-built-assets` après retrait de la régression de démarrage | première campagne : 39/43, puis seconde : 42/43 ; échecs limités aux sélecteurs des tests devenus incompatibles avec la navigation compacte |
| `python3 tools/cms.py e2e --use-built-assets` final | succès, 43/43 en 8,3 min ; gates E2E omnicanale et utilisabilité Commerce validées |
| `python3 tools/cms.py qualify --profile complete --no-cache` | succès, `passed`, code 0, 250 427 ms ; 151 tests réussis et 1 ignoré, syntaxes, validateurs, runtime, sauvegarde/restauration (21 contrôles), dépendances, build, gates M5–M7, documentation et export statique à blanc réussis |

Le profil `release`, la création d’une archive, son inspection et une installation neuve depuis cette archive n’ont pas été exécutés pour ce lot ; aucun succès n’est revendiqué pour ces contrôles.
