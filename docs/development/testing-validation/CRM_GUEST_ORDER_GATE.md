---
title: Gate M7.4 commande invitée et CRM
audience:
  - developer
  - evaluator
  - operator
status: current
last_verified: 2026-07-14
source_of_truth: code
source_paths:
  - tools/python/qualification/crm_guest_order_gate.py
  - docs/evaluation/machine-readable/crm-guest-order-gate-m7.json
  - tools/php/tests/unit/sale_customer_accounts_test.php
  - tools/php/tests/unit/sale_crm_activity_projection_test.php
owners:
  - core-team
document_type: guide
---
# Gate M7.4 commande invitée et CRM

La gate vérifie qu'une commande issue du checkout public peut rester invitée, puis être rattachée ou enrichir une relation CRM sans fusion silencieuse. Le token de panier et la preuve post-achat restent opaques et stockés sous forme de hash. Le site et la langue du canal bornent le traitement.

Un e-mail CRM ne suffit pas à fusionner des relations. Seule une coordonnée vérifiée, unique dans le site, peut rattacher le compte créé après achat à la relation existante. Plusieurs correspondances ou toute correspondance non vérifiée produisent une revue explicable. Les snapshots de commande ne sont jamais réécrits.

La projection CRM consomme l'outbox de façon idempotente. Une indisponibilité CRM ne remonte donc pas dans le checkout : l'événement reste rejouable. Le panier abandonné exige ancienneté, identité suffisante, base légitime et rétention limitée. Aucun achat, compte ou besoin transactionnel ne crée un consentement marketing.

La preuve machine-readable détaille, pour les douze scénarios, les identités avant/après, règles, décisions, événements, activités, consentements et doublons évités :

```bash
python3 tools/python/qualification/crm_guest_order_gate.py
```

La gate est incluse dans les profils `complete` et `release`. Les tests utilisent les schémas canoniques sur des bases temporaires reconstruites from scratch ; aucune migration n'est requise ni planifiée.
