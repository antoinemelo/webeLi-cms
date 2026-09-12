---
title: Plan d'architecture du module Business CRM
audience:
  - developer
  - administrator
status: draft
last_verified: 2026-06-26
source_of_truth: analysis
source_paths:
  - docs/development/extending/modules.md
  - docs/development/architecture/module-admin-governance.md
  - backend/src/Module/ModuleProvider.php
  - backend/src/Module/ModuleLifecycleService.php
  - backend/src/Module/ModuleNavigationRegistry.php
  - backend/src/Module/ModuleRouteLoader.php
  - backend/src/Module/ModuleDatabaseManager.php
  - backend/src/Module/ModulePermissionRegistrar.php
  - backend/config/modules.php
  - backend/config/databases.php
  - backend/routes/api.php
  - backend/src/Application/Api/Admin/Contract/AdminApiEndpointRegistry.php
  - tools/python/lib/database_inventory.py
  - tools/python/validation/operations/module_manifests.py
  - tools/python/validation/operations/migration_safety.py
  - ops/modules.local.json.example
  - examples/modules/client-notes/module.json
owners:
  - core
  - business
document_type: architecture
generated: false
---
# Plan d'architecture du module Business CRM

Cette note cadre l'ajout progressif du module `business` avant toute implementation metier. Elle s'appuie sur les fichiers reels du projet et limite volontairement le perimetre a un CRM leger, au mailing simple et a une couche messaging configurable.

## Diagnostic

Le socle module existe deja. Le contrat applicatif est `App\Module\ModuleProvider`; il declare les bases SQLite, permissions IAM, blueprints, navigation admin, routes, migrations, seeds et contrats API d'un module. Le cycle de vie est porte par `ModuleLifecycleService`, qui installe les bases, synchronise les permissions, publie les blueprints et enregistre les routes/contrats declares.

Les modules systeme officiels sont decouverts via `backend/config/modules.php`, `providers` et `system_manifest_paths`, avec des manifestes sous `backend/src/Modules/<Module>/module.json`. Les modules clients sont decouverts via `ops/modules.local.json` et doivent rester sous `local/modules/<module-key>/`. L'exemple `examples/modules/client-notes/` documente la structure minimale d'un module client, mais n'est pas charge en production.

Les outils Python lisent les manifestes JSON sans executer de code PHP. `tools/python/lib/database_inventory.py` ajoute les bases de modules a l'inventaire SQLite. `tools/python/validation/operations/module_manifests.py` controle les manifestes, chemins providers, schemas et migrations. `tools/python/validation/operations/migration_safety.py` verifie que `migrate --plan` reste non mutatif.

Les routes admin natives sont dans `backend/routes/api.php`. Les routes declarees par un provider actif sont chargees par `ModuleRouteLoader`. Les routes publiques de module restent des routes headless et ne doivent etre ajoutees que pour des besoins justifies, par exemple partage public de memo ou desabonnement mailing.

## Recommandation de packaging

Pour une fonctionnalite CRM prevue dans l'update officielle de `/cms`, le bon statut est un module produit optionnel livre comme module systeme, et non un module local client. Cela permet de le valider, documenter et packager dans la release, tout en gardant le noyau editorial separe.

Emplacements recommandes :

| Element | Emplacement |
|---|---|
| Manifeste | `backend/src/Modules/Business/module.json` |
| Provider | `backend/src/Modules/Business/BusinessModuleProvider.php` |
| Code PHP metier | `backend/src/Modules/Business/{Repositories,Services,...}` |
| Controleurs admin | `backend/src/Application/Api/Admin/BusinessApiController.php` ou `backend/src/Modules/Business/Controllers/*` avec cablage explicite |
| Schema de reference | `database/modules/business.sql` |
| Migrations | `database/migrations/business/0001_init.sql`, puis fichiers incrementaux immuables |
| Base runtime | `storage/database/business.sqlite` |
| UI admin | `frontend/admin-vue/src/views/business/*` et routage/navigation Vue existants |
| Contrats API | `docs/reference/contracts/admin-api-v1/admin.business.*.json` |
| Documentation | `docs/user-guide/business/`, `docs/reference/`, `docs/development/architecture/` |

Si le CRM devait rester propre a une seule instance, il faudrait plutot utiliser `local/modules/business/`, declarer `ops/modules.local.json`, et eviter toute modification de `backend/config/modules.php`. Ce n'est pas la recommandation pour une extension distribuee avec le CMS.

## Declaration du module

Le futur manifeste systeme devra declarer :

- `key`: `business`
- `type`: `system`
- `provider_class`: `App\Modules\Business\BusinessModuleProvider`
- `provider_file`: `backend/src/Modules/Business/BusinessModuleProvider.php`
- base `business` avec `path` `storage/database/business.sqlite`
- `schema`: `database/modules/business.sql`
- `migrations`: `database/migrations/business`

`backend/config/modules.php` devra ajouter le provider et le manifeste dans `providers` et `system_manifest_paths`. L'ajout a `enabled` doit etre decide separement : l'option prudente est de rendre le module decouvrable puis installable/activable, sans l'imposer au noyau editorial. Si l'inventaire release exige la base systeme, la creation from scratch devra produire `business.sqlite`.

## Permissions IAM

Les permissions doivent etre declarees par `BusinessModuleProvider::permissions()` et synchronisees par `ModulePermissionRegistrar`. Permissions minimales proposees :

| Permission | Usage |
|---|---|
| `business.crm.read` | Lire entreprises, contacts, tags, consentements et memos autorises. |
| `business.crm.manage` | Creer et modifier entreprises, contacts, tags et consentements. |
| `business.memo.share` | Partager un memo en interne ou par lien public revocable. |
| `business.messaging.send` | Declencher un envoi via provider configure, apres consentement. |
| `business.mailing.manage` | Gerer listes, campagnes simples, envois et desabonnements. |

Chaque controleur admin devra appeler `Authorization::require()` ou une verification equivalente cote backend. La navigation Vue ne suffit pas.

## Strategie de schema et migrations

Chaque table doit exister dans `database/modules/business.sql` et dans une migration incrementale. La premiere migration `database/migrations/business/0001_init.sql` doit creer le socle CRM : entreprises, contacts, memos, partages, tags, consentements et tables minimales mailing/messaging si elles sont dans le prompt correspondant.

Le schema doit rester dans une seule base metier `business.sqlite`. Les liens vers le noyau doivent rester par identifiants stables, par exemple `iam_user_id`, `site_id` ou futures references commande/facture, sans jointure SQL cross-database obligatoire dans les contraintes SQLite.

Les migrations deja publiees ne doivent jamais etre modifiees. Toute correction doit passer par un nouveau fichier incremental.

## Routes et contrats

Les routes admin doivent etre declarees par `BusinessModuleProvider::adminRoutes()` afin d'etre chargees via `ModuleRouteLoader`. Les contrats admin stables devront etre ajoutes sous `docs/reference/contracts/admin-api-v1/` et references par `apiContracts()`.

Point technique a ne pas ignorer : `App\Core\App::makeController()` instancie explicitement les controleurs avec dependances. Le fallback ne sait instancier que des classes sans constructeur. Pour un controleur Business propre, les prompts suivants devront soit ajouter un cablage minimal dans `ServiceFactory` et `App::makeController()`, soit concevoir un controleur module sans dependances constructeur. La premiere option est plus propre pour partager `Request`, `AuthRepository`, `Authorization`, `SiteRepository`, la connexion `business.sqlite` et les services metier.

Les routes publiques sont hors perimetre initial sauf :

- lecture publique d'un memo partage par lien secret ;
- desabonnement mailing.

Ces routes devront etre lecture seule ou action ciblee, non indexables, revocables, journalisees et testees. Elles ne doivent pas exposer l'ensemble du CRM.

## Navigation admin

Le provider doit exposer `adminNavigation()` avec une entree `Business` ou `CRM` dans la section Modules. La permission d'affichage minimale doit etre `business.crm.read`. Les actions sensibles restent protegees par permissions dediees cote API.

La UI Vue devra s'appuyer sur les capacites/permissions renvoyees par `/admin/api/context` et par le registre module, mais ne doit pas devenir la source d'autorisation.

## Fichiers a creer ou modifier ensuite

Fichiers a creer :

- `backend/src/Modules/Business/module.json`
- `backend/src/Modules/Business/BusinessModuleProvider.php`
- `backend/src/Modules/Business/Repositories/*`
- `backend/src/Modules/Business/Services/*`
- `backend/src/Application/Api/Admin/BusinessApiController.php`
- `database/modules/business.sql`
- `database/migrations/business/0001_init.sql`
- `docs/reference/contracts/admin-api-v1/admin.business.*.json`
- `docs/user-guide/business/README.md`
- tests PHP/Python dedies au module Business

Fichiers a modifier avec parcimonie :

- `backend/config/modules.php`
- `backend/src/Core/ServiceFactory.php`
- `backend/src/Core/App.php`
- `frontend/admin-vue/src/router` ou son equivalent local
- `frontend/admin-vue/src/views/business/*`
- `docs/reference/generated/*` uniquement via generation

## Strategie de tests

Tests automatiques minimaux :

- validation manifeste module ;
- plan de migration `business` non mutatif ;
- creation from scratch de `business.sqlite` ;
- permissions : acces autorise/refuse sur une route admin Business ;
- repository CRM : entreprise systeme `Individus`, contact rattache a une seule entreprise ;
- partage public memo : lien valide, lien revoque, non-indexation ;
- messaging/mailing : refus sans consentement, provider nul utilisable sans secret externe.

Commandes de controle :

```bash
python3 tools/cms.py validate
python3 tools/cms.py docs check
python3 tools/cms.py migrate --module business --plan
python3 tools/cms.py backup --output storage/backups/pre-business-check.zip
```

## Risques et limites

- Un module systeme ajoute a l'inventaire release peut rendre `business.sqlite` attendu dans les archives. Le rebuild devra donc le produire proprement.
- Les routes module publiques ne sont pas toutes chargees par defaut ; il faut verifier la configuration avant de compter sur une route headless module.
- Les controles IAM doivent etre ecrits dans chaque endpoint Business, meme si la navigation masque l'ecran.
- Les providers WhatsApp/Telegram doivent rester des interfaces et providers configurables. Aucun scraping ni secret en clair ne doit entrer dans le code, les seeds ou la documentation.
- Les futures references commerce/comptabilite doivent rester des champs de preparation, sans implementation de commande, facture, paie ou comptabilite dans cette phase.
