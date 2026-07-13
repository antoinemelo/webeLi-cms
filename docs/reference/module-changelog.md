---
title: Changelog par module
audience:
  - administrator
  - superadministrator
  - installer
  - developer
  - evaluator
status: stable
last_verified: 2026-07-13
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

Cette page centralise le suivi lisible des changements module par module. Les versions installées et attendues sont inventoriées dans **Maintenance** ; les sources techniques restent les manifests et schémas canoniques des modules.

| Module | État documentaire | Source de vérité | Notes de changement |
|---|---|---|---|
| Core | Maintenu | `backend/config/updates.php`, `database/schema/core.sql` | Les changements de structure sont portés par le schéma canonique et validés sur une reconstruction from scratch. |
| Business CRM | Maintenu | `backend/src/Modules/Business`, `docs/business` | CRM, contacts, sociétés, mémos, consentements et messagerie. |
| Catalogue/PIM | Maintenu | `backend/routes/api.php`, `docs/business/catalogue.md` | Produits, variantes, marques, catégories, qualité et exports. |
| Vente/POS | Maintenu | `backend/src/Modules/Sale`, `docs/business/vente.md` | Commandes, POS, paiements, stock transactionnel et canaux. |
| Assistant IA | Maintenu | `docs/administration/ai-assistant/administer.md` | Configuration et usages assistés ; dépendance externe à qualifier selon l’instance. |
| Cookies | Maintenu | `docs/user-guide/forms-cookies/cookie-consent.md` | Consentements, catégories, services tiers et scripts conditionnels. |
| Formulaires | Maintenu | `docs/user-guide/forms-cookies/forms-search-import-export-ai.md` | Formulaires publics, soumissions et exports. |

## Règle de release

Un module ne doit pas annoncer une capacité comme stable si elle n’a pas de preuve dans [Scénarios vérifiables](../getting-started/verified-scenarios.md), un test automatisé ou une procédure reproductible.

# 2026-07-13 — Sale M5 : contrat provider et modèle paiement

- contrat canonique versionné `sale.payment_provider.v1` et registry de capacités ;
- résolution Shop des moyens par site, canal, langue, devise et montant ;
- états récupérables et prochaines actions publiques ;
- liste/détail Paiements dans l'administration, chronologie et panneau technique ;
- schéma canonique et fixtures reconstruits from scratch, sans migration.

# 2026-07-13 — CRM × Vente : timeline commerciale v1

- projection idempotente des événements Vente web et POS dans la timeline CRM ;
- ventes anonymes conservées sans création de contact, avec rattachement tardif audité ;
- réconciliation des activités manquantes ou dupliquées ;
- contrat Pricing en lecture seule avec segments et état du consentement marketing ;
- schémas canoniques uniquement, sans migration.

# 2026-07-13 — Gate omnicanale storefront × POS

- même produit et même vendable démontrés sur les deux canaux ;
- paiement sandbox web, paiement comptant POS, reçus, retours et fermeture de caisse ;
- schéma de commande partagé, snapshots immuables, stock et CRM idempotent ;
- rapport JSON sans PII ni secret, validé indépendamment et bloquant pour la qualification release ;
- mutations canal, prix, stock et permission couvertes par tests négatifs.
