---
title: Tests du module Business CRM
audience:
  - developer
  - evaluator
status: draft
last_verified: 2026-06-27
source_of_truth: code
source_paths:
  - tools/python/tests/test_business_module_smoke.py
  - tools/php/tests/unit/business_crm_service_test.php
  - tools/php/tests/unit/business_crm_api_controller_test.php
  - tools/php/tests/unit/business_csv_service_test.php
  - tools/php/tests/unit/business_mailing_service_test.php
  - tools/php/tests/unit/business_messaging_provider_test.php
  - tools/php/tests/run.php
owners:
  - business
document_type: guide
generated: false
---
# Tests du module Business CRM

## Commandes

Depuis la racine du depot :

```bash
python3 tools/cms.py migrate --module business --plan
python3 tools/cms.py validate
python3 tools/cms.py docs check
python3 tools/cms.py test --timeout 300 --target-duration 120
```

Le smoke Business cible est integre a `tools/cms.py test` via `tools/python/tests/test_business_module_smoke.py`. La commande `tools/cms.py smoke` reste le smoke structurel de release.

## Couverture automatique

- presence de `business.sqlite`, tables et index attendus ;
- seed systeme `Individus` ;
- plan de migration Business non mutatif ;
- routes et contrats Business declares ;
- permissions autorisees/refusees sur API admin ;
- creation entreprise/contact, statuts valides et invalides, lien IAM unique, archivage ;
- memos entreprise/contact, partage IAM, partage public, token faux ou revoque refuse, `noindex` ;
- consentement email/WhatsApp/Telegram et refus sans consentement ;
- mailing : liste, campagne brouillon, preview, enqueue, desabonnement ;
- messaging : provider log-only, WhatsApp/Telegram desactives sans configuration, erreur provider journalisee ;
- import/export CSV avec neutralisation des formules et mode dry-run.

## Ce que les tests ne font pas

Les tests n'appellent jamais les API WhatsApp, Telegram ou un fournisseur externe. Ils valident le decouplage, l'outbox, les providers runtime et les refus de configuration.

## Controle manuel recommande

Quand l'UI est disponible, verifier le parcours `/admin/app/business/crm` : creation entreprise, contact, memo, partage public revocable, liste mailing et preview destinataires.
