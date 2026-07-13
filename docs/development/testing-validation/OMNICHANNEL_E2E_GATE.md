---
title: Gate E2E omnicanale storefront et POS
audience:
  - developer
  - evaluator
  - administrator
status: current
last_verified: 2026-07-13
source_of_truth: code
source_paths:
  - frontend/admin-vue/tests/e2e/omnichannel-release-gate.spec.ts
  - tools/python/qualification/omnichannel_gate.py
  - tools/python/operations/testing/run_playwright_e2e.py
  - tools/python/qualification/run_all.py
owners:
  - sale
  - business
  - core
document_type: procedure
generated: false
---
# Gate E2E omnicanale storefront et POS

La gate `cms-crm-sale-pos.omnichannel.v1` prouve automatiquement qu’un même produit et un même vendable peuvent être achetés depuis le storefront et le POS tout en conservant les mêmes contrats métier.

Le scénario reconstruit une instance isolée from scratch, puis vérifie :

- les pages boutique, collection et produit, le panier invité, le checkout et le paiement sandbox capturé ;
- la commande web confirmée, son reçu, son retour et son remboursement ;
- le canal, l’emplacement, la caisse et la session POS, puis le panier, le paiement comptant, le reçu, le retour et la fermeture de session ;
- le même `product_id`, le même `sellable_id` et le même schéma de commande dans les deux canaux ;
- des canaux et sources distincts, des prix positifs issus de chaque canal et des snapshots immuables ;
- une consommation de stock attribuée à chaque commande et un retour en stock ;
- la projection CRM idempotente après deux réconciliations ;
- le refus d’un appel POS anonyme, l’alignement routes/OpenAPI/SDK et l’absence de PII ou secret dans la preuve ;
- l’usage de connexions propriétaires sans `ATTACH DATABASE`, ainsi que la présence du round-trip sauvegarde/restauration sur tout l’inventaire déclaré avec contrôle d’intégrité SQLite.

## Exécution ciblée

```bash
python3 tools/cms.py e2e --use-built-assets --omnichannel-only
```

La commande crée elle-même l’instance, les bases, le compte administrateur et les services HTTP locaux. Elle ne requiert aucune variable `E2E_*` et ne modifie jamais les bases de travail.

Le rapport est écrit avec des permissions restrictives dans `storage/qualification/omnichannel/latest.json`, puis validé indépendamment :

```bash
python3 tools/python/qualification/omnichannel_gate.py \
  --report storage/qualification/omnichannel/latest.json --json
```

Un rapport absent, invalide, contenant une PII ou un secret, ou portant une comparaison fausse fait échouer la commande. Le profil `release` exécute la suite Playwright complète et refuse également de réutiliser un cache E2E si ce rapport manque ou ne passe plus sa validation.

## Régressions simulées

Les tests unitaires mutent séparément le canal, le prix, le stock et la preuve de permission. Chacune de ces mutations doit rendre la gate rouge :

```bash
python3 -m unittest tools.python.tests.test_omnichannel_gate -v
```

La stratégie de base de données est exclusivement une reconstruction from scratch de l’instance jetable ; aucune migration n’est planifiée ni exécutée par cette gate.
