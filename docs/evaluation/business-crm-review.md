---
title: Revue finale Business CRM
audience:
  - evaluator
  - administrator
  - developer
status: draft
last_verified: 2026-06-27
source_of_truth: analysis
source_paths:
  - backend/src/Modules/Business
  - backend/src/Application/Api/Admin/BusinessCrmApiController.php
  - backend/src/Application/Api/Admin/BusinessMailingApiController.php
  - backend/src/Application/Api/Admin/BusinessMessagingApiController.php
  - backend/src/Application/Frontend/BusinessMemoShareController.php
  - backend/src/Application/Frontend/BusinessUnsubscribeController.php
  - database/modules/business.sql
  - database/migrations/business/0001_init.sql
  - tools/php/tests/unit/business_crm_service_test.php
  - tools/php/tests/unit/business_crm_api_controller_test.php
  - tools/php/tests/unit/business_csv_service_test.php
  - tools/php/tests/unit/business_mailing_service_test.php
  - tools/php/tests/unit/business_messaging_provider_test.php
  - tools/python/tests/test_business_module_smoke.py
owners:
  - business
document_type: evaluation
generated: false
---
# Revue finale Business CRM

## Resume executif

Conclusion : merge possible avec reserves.

Le module `business` respecte le perimetre CRM leger vise : une seule base `business.sqlite`, entreprises, contacts, memos, consentements, mailing simple et outbox messaging. Il ne met pas en place de pipeline commercial, taches, rappels, ERP, paie ou comptabilite.

La revue finale a corrige un defaut d'isolation : les memos verifient maintenant que leurs cibles `company_id` et `contact_id` appartiennent au meme site que le memo, et qu'un couple entreprise/contact est coherent. Le test `business_crm_service_test.php` couvre cette regression.

Les reserves restantes ne bloquent pas le merge fonctionnel, mais doivent etre connues avant activation large : les routes Business sont cablees dans `backend/routes/api.php` plutot que declarees via `BusinessModuleProvider::adminRoutes()`, l'UI n'a pas encore ete validee manuellement, et les providers WhatsApp/Telegram restent des abstractions configurees/desactivees sans implementation d'appel externe.

## Conformite au perimetre

| Question d'audit | Resultat |
|---|---|
| Base metier unique | Conforme : schema, migration et runtime utilisent `storage/database/business.sqlite`. |
| CRM simple | Conforme : pas de pipeline, taches, rappels, ERP, paie ou comptabilite. |
| Contact toujours lie a une entreprise | Conforme : `business_contacts.company_id` est obligatoire ; contact sans entreprise explicite utilise `Individus`. |
| Entreprise systeme `Individus` | Conforme : seed presente, contrainte unique par site, archivage des entreprises systeme bloque par repository. |
| Lien IAM optionnel | Conforme : `iam_user_id` nullable et unique uniquement pour les contacts actifs. |
| Statuts CRM contraints | Conforme : schema et repositories limitent `prospect`, `client`, `supplier`, `former_client`, `other`. |
| Memos correctement lies | Conforme apres correction : cible obligatoire, cibles meme site, couple entreprise/contact coherent. |
| Liens publics | Conforme : token aleatoire hashe, token retourne une seule fois, revocation, expiration, `noindex,nofollow`, logs sans token clair. |
| Permissions backend | Conforme : chaque controleur admin appelle `requireAuth()` puis `Authorization::require(...)`. |
| Consentements mailing/messaging | Conforme : preview/enqueue et message contact exigent canal et opt-in compatibles. |
| WhatsApp/Telegram | Conforme : providers desactives sans configuration, pas de secret en dur, pas de scraping ni API externe obligatoire. |
| Erreurs provider | Conforme : outbox et delivery events stockent les echecs provider. |
| Tests sans reseau externe | Conforme : providers log-only et tests unitaires locaux ; aucun appel WhatsApp/Telegram reel. |
| Documentation honnete | Conforme : limites v1 documentees et API publique non promise. |
| Validateurs | Conforme sur les commandes executees ci-dessous. |

## Fichiers ajoutes ou modifies

### Backend et module

- `backend/config/modules.php`
- `backend/routes/api.php`
- `backend/routes/web.php`
- `backend/src/Application/Api/Admin/AdminContextApiController.php`
- `backend/src/Application/Api/Admin/BusinessCrmApiController.php`
- `backend/src/Application/Api/Admin/BusinessMailingApiController.php`
- `backend/src/Application/Api/Admin/BusinessMessagingApiController.php`
- `backend/src/Application/Frontend/BusinessMemoShareController.php`
- `backend/src/Application/Frontend/BusinessUnsubscribeController.php`
- `backend/src/Core/App.php`
- `backend/src/Core/ServiceFactory.php`
- `backend/src/Security/AdminApiRequestGuard.php`
- `backend/src/Modules/Business/**`

### Donnees, migrations et inventaire

- `database/modules/business.sql`
- `database/migrations/business/0001_init.sql`
- `database/migrations/iam/0008_business_permissions.sql`
- `database/seeds/iam_seed.sql`
- `tools/python/lib/database_inventory.py`
- `tools/python/operations/database/b0_db_seed.py`
- `tools/python/validation/database/inventory.py`
- `tools/python/validation/operations/module_manifests.py`

### Back-office

- `frontend/admin-vue/src/router/index.ts`
- `frontend/admin-vue/src/views/modules/BusinessCrmView.vue`
- `admin-app/index.html`
- `admin-app/.vite/manifest.json`
- `admin-app/assets/*` generes par le build admin

### Tests

- `tools/php/tests/run.php`
- `tools/php/tests/unit/business_crm_service_test.php`
- `tools/php/tests/unit/business_crm_api_controller_test.php`
- `tools/php/tests/unit/business_csv_service_test.php`
- `tools/php/tests/unit/business_mailing_service_test.php`
- `tools/php/tests/unit/business_messaging_provider_test.php`
- `tools/python/tests/test_business_module_smoke.py`
- `tools/python/tool-manifest.json`
- `tools/python/tests/test_characterization_baseline.py`

### Documentation

- `docs/user-guide/business/**`
- `docs/administration/business/configuration.md`
- `docs/development/architecture/business-crm-functional-spec.md`
- `docs/development/architecture/business-module-plan.md`
- `docs/development/architecture/business-module.md`
- `docs/development/testing-validation/business-crm.md`
- `docs/api/admin-internal/business-crm.md`
- index documentaires modifies dans `docs/user-guide`, `docs/administration`, `docs/development`, `docs/api/admin-internal`
- `docs/reference/generated/**` rafraichis par `docs generate`

## Tests executes

Commandes executees pendant la revue finale :

```bash
CMS_PHP_BINARY=/home/amelo/Documents/DEV/Ecol_WebeLi/web/runtime/dist/webeLi-audit-runtime-linux-x86_64-php8.4/bin/php /home/amelo/Documents/DEV/Ecol_WebeLi/web/runtime/dist/webeLi-audit-runtime-linux-x86_64-php8.4/bin/php tools/php/tests/unit/business_crm_service_test.php
CMS_PHP_BINARY=/home/amelo/Documents/DEV/Ecol_WebeLi/web/runtime/dist/webeLi-audit-runtime-linux-x86_64-php8.4/bin/php python3 -m unittest tools.python.tests.test_business_module_smoke -v
```

Resultats :

- `business_crm_service_test.php` : PASS, 33 assertions.
- `test_business_module_smoke.py` : PASS, 4 tests.

Commandes executees juste avant cette revue, apres documentation Business :

```bash
CMS_PHP_BINARY=/home/amelo/Documents/DEV/Ecol_WebeLi/web/runtime/dist/webeLi-audit-runtime-linux-x86_64-php8.4/bin/php python3 tools/cms.py docs generate
CMS_PHP_BINARY=/home/amelo/Documents/DEV/Ecol_WebeLi/web/runtime/dist/webeLi-audit-runtime-linux-x86_64-php8.4/bin/php python3 tools/cms.py docs check
CMS_PHP_BINARY=/home/amelo/Documents/DEV/Ecol_WebeLi/web/runtime/dist/webeLi-audit-runtime-linux-x86_64-php8.4/bin/php python3 tools/cms.py migrate --module business --plan
CMS_PHP_BINARY=/home/amelo/Documents/DEV/Ecol_WebeLi/web/runtime/dist/webeLi-audit-runtime-linux-x86_64-php8.4/bin/php python3 tools/cms.py validate
```

Resultats :

- `docs generate` : PASS.
- `docs check` : PASS.
- `migrate --module business --plan` : PASS, 0 migration a appliquer.
- `validate` : PASS.

## Defauts bloquants

Aucun defaut bloquant restant identifie apres correction de l'isolation des cibles memo.

Defaut corrige pendant la revue :

- `BusinessMemoRepository` acceptait une cible entreprise/contact existante dans `business.sqlite` sans verifier explicitement son `site_id`. La correction ajoute `assertTargetsBelongToSite()` et refuse aussi un couple entreprise/contact incoherent.

## Defauts non bloquants

- Les routes Business sont cablees dans `backend/routes/api.php` et `backend/routes/web.php`, alors que le provider expose surtout navigation, permissions, base et contrats. Cela fonctionne, mais une etape future pourrait migrer les routes vers `BusinessModuleProvider::adminRoutes()` si le loader module devient la convention stricte.
- Les providers WhatsApp/Telegram valident la configuration et restent decouples, mais n'executent pas encore d'appel officiel complet. C'est volontaire pour la v1.
- L'UI Vue a ete compilee, mais le parcours manuel complet n'a pas encore ete joue dans un navigateur par un utilisateur final.
- Les tests HTTP reels couvrent surtout les patterns existants et les tests Business sont majoritairement unitaires/structurels. Un E2E Business peut etre ajoute plus tard si l'UI devient critique.

## Recommandations avant merge

1. Relancer la suite complete `tools/cms.py test` apres tout dernier rebase.
2. Lancer un build front si les assets admin ont change.
3. Faire un test manuel minimal dans `/admin/app/business/crm` : entreprise, contact, memo, partage public, liste mailing, preview.
4. Verifier sur une instance multisite que les listes CRM restent bien contextualisees par site.
5. Ne pas activer de provider externe sans documenter l'environnement, la politique de consentement et le traitement des erreurs.

## Commandes exactes a relancer

```bash
export CMS_PHP_BINARY=/home/amelo/Documents/DEV/Ecol_WebeLi/web/runtime/dist/webeLi-audit-runtime-linux-x86_64-php8.4/bin/php
python3 tools/cms.py docs generate
python3 tools/cms.py docs check
python3 tools/cms.py migrate --module business --plan
python3 tools/cms.py validate
python3 tools/cms.py test --timeout 400 --target-duration 200
npm --prefix frontend/admin-vue run build
```

## Decision

Decision recommandee : merge possible avec reserves.

Les reserves concernent l'ergonomie et la couverture E2E, pas la coherence du schema, des permissions, des consentements ou des validations deterministes actuellement executees.
