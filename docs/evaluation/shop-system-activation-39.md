---
title: Shop système, activation multisite et Studio (39)
audience:
  - evaluator
  - administrator
  - developer
status: current
last_verified: 2026-07-17
source_of_truth: code
source_paths:
  - database/schema/core.sql
  - database/migrations/core/080_shop_system_configurations.sql
  - backend/src/Application/Commerce/ShopConfigurationService.php
  - backend/src/Application/Frontend/ResolvePublicRoute.php
  - frontend/admin-vue/tests/e2e/shop-system-activation-39.spec.ts
  - tools/php/tests/unit/shop_configuration_service_test.php
owners:
  - core
  - business
  - sale
document_type: evaluation
generated: false
---
# Shop système, activation multisite et Studio (39)

## Conclusion

Le cycle Shop par `(site_id, langue)` est **démontré** pour le périmètre M8.1, avec un niveau de confiance élevé. La preuve combine schéma reconstruit, test de service sur deux sites et deux langues, contrôles IAM sur trois rôles, compilation TypeScript, validateurs et navigateur réel sur instance fraîche.

La conclusion ne signifie pas que toutes les fonctions de merchandising de la spécification Shop sont achevées. Le point 40 démontre désormais recherche, facettes, compteurs et tris ; la popularité anonyme et les sections spécialisées restent **partiellement démontrées**. Ces limites n’abaissent pas la preuve du cycle d’activation.

## Faits et preuves

| Conclusion | Statut | Faits observés | Preuve | Limite | Confiance |
|---|---|---|---|---|---|
| configuration unique par site/langue | démontré | contrainte unique, brouillon et snapshot versionnés | schéma SQL et test PHP sur deux sites/deux langues | SQLite uniquement | élevée |
| mise à niveau d’une instance existante | démontré | migration incrémentale sans initialisation ni activation implicite | application sur copie, 3 assertions de régression et application locale avec backup | pas de down migration automatique | élevée |
| aucune activation implicite | démontré | lecture sans écriture ; sauvegarde et publication restent inactives | 25 assertions du test Shop | pas de concurrence distribuée | élevée |
| activation et reprise idempotentes | démontré | rejeu sans doublon ; échec de projection simulé puis réparation | test PHP avec table de projection rendue indisponible | panne réseau externe non simulée | élevée |
| route, menu, panier et headless partagent le même verrou | démontré | disparition après désactivation et retour après activation | Playwright 39 sur Chromium | un seul moteur navigateur | élevée |
| isolation langue et base path | démontré | français actif, anglais `404` ; le lien de prévisualisation combine sous-répertoire d’installation, préfixe de langue et `/shop` | Playwright, test Shop et tests multisite existants | second domaine non parcouru dans ce test navigateur | élevée |
| IAM séparé | démontré | rôle Ventes sans édition ; rôle Studio sans activation ; rôle vide refusé | test contrôleur PHP | composition exacte dépend de l’instance | élevée |
| page système protégée dans Studio | démontré | route, type et périmètre verrouillés ; aperçu de brouillon ; badge dans Pages | compilation et Playwright | l’aperçu est structurel, pas pixel-identique au thème | élevée |
| absence de page CMS par produit | démontré | zéro `content_entry` produit après activation | assertion SQL du test PHP | les contenus éditoriaux liés aux produits restent permis | élevée |
| sections avancées de la page Shop | partiellement démontré | ordre, visibilité et libellés sont configurables ; trois rendus de thème lisent le contrat ; facettes et tris sont couverts au point 40 | source, build, E2E de page 39 et test unitaire 40 | popularité et sections spécialisées incomplètes | moyenne |
| module Commerce autonome absent | démontré | alias redirigé, aucune carte module | tests 38a/39 et lifecycle | alias de compatibilité conservé | élevée |

## Contradictions rencontrées

- La fixture complète `core_default_seed.sql` ne contenait initialement pas la ligne Shop ajoutée au seed de référence. Le premier E2E a donc observé `not_initialized` au lieu de `active`. La fixture a été corrigée, puis l’instance a été reconstruite et le scénario a réussi.
- Une première exécution Playwright après cette correction utilisait un ancien bundle `admin-app`; le navigateur ouvrait bien l’éditeur système, mais avec l’ancien titre et sans le nouvel aperçu. La compilation TypeScript a ensuite révélé un type littéral trop large dans `ContentListView.vue`. Le type a été corrigé, le bundle reconstruit avec code retour 0, puis l’E2E a réussi.
- Une réexécution a révélé une concurrence entre le chargement initial de la liste Pages et la mise à jour du contexte site/langue : une réponse obsolète pouvait masquer la ligne Shop. `ContentListView.vue` ignore désormais les chargements dépassés et conserve le périmètre demandé ; le scénario complet a ensuite réussi sur une nouvelle instance.
- La gate des contrats Sale supposait que toutes les permissions d’une route appartenaient à Sale. Les permissions éditoriales Core du Shop ont rendu cette hypothèse fausse. La gate valide désormais explicitement les trois permissions Core autorisées et les alternatives `A|B`.
- Le schéma de reconstruction contenait initialement `cms_shop_configurations`, mais aucune migration incrémentale ne créait cette table dans une base existante. Le panneau répondait alors par une erreur interne avec `no such table: cms_shop_configurations`. La migration `080_shop_system_configurations.sql` a été ajoutée, vérifiée sur une copie puis appliquée à l’instance locale après sauvegarde.

Ces échecs intermédiaires ne sont pas comptés comme réussites.

## Commandes exécutées le 17 juillet 2026

| Commande | Résultat observé |
|---|---|
| `npm run build` dans `frontend/admin-vue` | succès final ; 249 modules transformés, bundle Shop généré |
| `php tools/php/tests/unit/shop_configuration_service_test.php` | succès final : 30 assertions, dont 3 sur la migration incrémentale et 2 prouvant les chemins configurés `/edu` et `/eve` |
| `php tools/php/tests/integration/multisite_base_path_test.php` | succès : 30 assertions sur les URL avec `APP_BASE_PATH` |
| `python3 tools/cms.py migrate --database core --plan` | succès ; une migration manquante identifiée : `080_shop_system_configurations.sql` |
| application de `080_shop_system_configurations.sql` sur une copie de `core.sqlite`, puis `PRAGMA integrity_check` | succès ; table et index créés, zéro Shop implicite, intégrité `ok` |
| `python3 tools/cms.py migrate --database core --apply --backup --yes` | succès ; archive de sauvegarde SQLite horodatée créée, une migration appliquée |
| `php tools/php/tests/unit/commerce_module_lifecycle_test.php` | succès : 12 assertions |
| `php tools/php/tests/unit/sale_module_contracts_test.php` | succès final : 1 150 assertions |
| `php tools/php/tests/run.php` | succès final ; les deux nouveaux tests 39 sont inscrits dans la suite ; quatre tests HTTP ignorés faute de port TCP dans le sandbox |
| tests ciblés Storefront, catalogue public et Sale public | succès ; test HTTP local ignoré dans le sandbox sans port TCP |
| `python3 tools/cms.py test --timeout 400 --target-duration 200` | succès final : 160 tests, 1 ignoré, 204,278 s ; cible 200 s légèrement dépassée |
| `python3 tools/cms.py validate --category configuration --category database --category content --category permissions --category api --category operations --category security --category documentation --category shared` | succès : toutes catégories vertes |
| `python3 tools/cms.py docs generate` puis `docs check` | succès ; 58 chemins, 62 opérations, 23 schémas ; 647 contrôles API et 1 464 documentaires |
| `python3 tools/cms.py e2e --use-built-assets --spec shop-system-activation-39.spec.ts` | succès final après correction de concurrence : 1 scénario en 1,8 min sur instance reconstruite |

Exécutions non réussies, conservées comme diagnostic :

- `python3 tools/cms.py --database-dir /tmp/dec-cms-shop39-databases rebuild` a été refusé par la façade, qui interdit volontairement un `database-dir` non natif ; la reconstruction isolée a donc été prouvée par le harnais E2E prévu à cet effet ;
- une exécution E2E dans le sandbox a échoué avec `Operation not permitted` lors de l’ouverture des ports locaux ; elle a été rejouée hors sandbox ;
- les exécutions E2E de construction ont successivement détecté la fixture Shop absente, un sélecteur Playwright ambigu, un bundle compilé obsolète, puis une réponse de liste Pages arrivée dans le désordre ;
- une tentative a expiré pendant la détection de Chromium à 30 secondes ; la détection directe a ensuite réussi et la relance finale du même scénario est passée.

## Limites et contrôles manuels restants

- vérifier visuellement les trois thèmes avec un catalogue volumineux et des sections vides ;
- tester Firefox/WebKit, zoom 200 % et lecteur d’écran ;
- mesurer la reconstruction sur un catalogue de production ;
- compléter les sources de popularité avant de les qualifier comme démontrées ; recherche, facettes et tris sont évalués séparément au point 40 ;
- exécuter la qualification release complète si ce lot doit devenir une archive distribuable autonome.
