---
title: Changelog par module
audience:
  - administrator
  - superadministrator
  - installer
  - developer
  - evaluator
status: stable
last_verified: 2026-07-11
source_of_truth: manual
source_paths:
  - backend/src/Modules
  - local/modules
  - docs/operations/client-instance-update.md
owners:
  - core
  - modules
document_type: reference
generated: false
---
# Changelog par module

Cette page centralise le suivi lisible des changements module par module. Les versions installées et attendues sont inventoriées dans **Maintenance** ; les sources techniques restent les manifests de modules et les migrations.

| Module | État documentaire | Source de vérité | Notes de changement |
|---|---|---|---|
| Core | Maintenu | `backend/config/updates.php`, `database/schema/core.sql` | Les changements de structure passent par migrations et validation documentaire. |
| Business CRM | Maintenu | `backend/src/Modules/Business`, `docs/business` | CRM, contacts, sociétés, mémos, consentements et messagerie. |
| Catalogue/PIM | Maintenu | `backend/routes/api.php`, `docs/business/catalogue.md` | Produits, variantes, marques, catégories, qualité et exports. |
| Vente/POS | Maintenu | `backend/src/Modules/Sale`, `docs/business/vente.md` | Commandes, POS, paiements, stock transactionnel et canaux. |
| Assistant IA | Maintenu | `docs/administration/ai-assistant/administer.md` | Configuration et usages assistés ; dépendance externe à qualifier selon l’instance. |
| Cookies | Maintenu | `docs/user-guide/forms-cookies/cookie-consent.md` | Consentements, catégories, services tiers et scripts conditionnels. |
| Formulaires | Maintenu | `docs/user-guide/forms-cookies/forms-search-import-export-ai.md` | Formulaires publics, soumissions et exports. |

## Règle de release

Un module ne doit pas annoncer une capacité comme stable si elle n’a pas de preuve dans [Scénarios vérifiables](../getting-started/verified-scenarios.md), un test automatisé ou une procédure reproductible.
# 2026-07-13 — CRM × Vente : timeline commerciale v1

- projection idempotente des événements Vente web et POS dans la timeline CRM ;
- ventes anonymes conservées sans création de contact, avec rattachement tardif audité ;
- réconciliation des activités manquantes ou dupliquées ;
- contrat Pricing en lecture seule avec segments et état du consentement marketing ;
- schémas canoniques uniquement, sans migration.
