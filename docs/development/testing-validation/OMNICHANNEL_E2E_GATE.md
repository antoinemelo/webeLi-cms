---
title: Gate E2E omnicanale storefront et POS
audience:
  - developer
  - evaluator
  - administrator
status: current
last_verified: 2026-07-14
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

La gate unique `cms-crm-sale-pos.omnichannel.v1` prouve automatiquement la cohérence M5/M6/M7 entre le storefront, le paiement, le ledger de stock, le fulfillment, le CRM et le POS. Son rapport est au format de preuve `2`; il remplace et étend la preuve initiale sans introduire de seconde gate concurrente.

Le scénario reconstruit une instance isolée from scratch, puis vérifie :

- la création back-office d’un produit physique, de son vendable et de deux unités de stock, puis leur projection vers `/shop` ;
- la page boutique, une collection et la fiche produit, l’ajout au panier depuis l’interface publique et le checkout invité mobile depuis le formulaire SSR ;
- la commande web en paiement sandbox, la capture par webhook signé, une préparation/expédition partielle, le reçu, le retour terminé et le remboursement ;
- le canal, l’emplacement, la caisse et la session POS, puis le panier, le paiement comptant, le reçu, le retour terminé et la fermeture de session ;
- le même `product_id`, le même `sellable_id` et le même schéma de commande dans les deux canaux ;
- des canaux et sources distincts, des prix positifs issus de chaque canal et des snapshots immuables ;
- les réservations, leur consommation, les mouvements de vente et de retour, puis un rapprochement du ledger sans différence ;
- la projection CRM idempotente après deux réconciliations ;
- le rapprochement du paiement et la reconstruction finale de la projection Shop ;
- le refus d’un appel POS anonyme, l’alignement routes/OpenAPI/SDK et l’absence de PII ou secret dans la preuve ;
- l’usage de connexions propriétaires sans `ATTACH DATABASE`, ainsi que la présence du round-trip sauvegarde/restauration sur tout l’inventaire déclaré avec contrôle d’intégrité SQLite.

Le paquet de preuve ne conserve aucune donnée client, carte ou identifiant confidentiel. Il contient uniquement le commit, la version technique de la preuve, les providers exercés, des compteurs avant/après et des empreintes SHA-256 pour les commandes, mouvements, réservations, paiements, événements, activités CRM et rapprochements. Les métriques par rôle couvrent client, opérateur POS, fulfillment, finance et CRM : réussite, étapes significatives, erreurs, récupération et durée automatisée observée.

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

Un rapport absent, invalide, incomplet, contenant une PII, une donnée carte ou un secret, portant une comparaison fausse, une empreinte invalide, un parcours UX incomplet ou un contrôle de régression non prouvé fait échouer la commande. Le profil `release` exécute la suite Playwright complète et refuse également de réutiliser un cache E2E si ce rapport manque ou ne passe plus sa validation.

## Régressions simulées

Les tests unitaires mutent séparément le canal, le prix, le stock, les rapprochements, chaque rôle, chaque parcours UX et chacun des contrôles suivants. Toute mutation doit rendre la gate rouge :

- double checkout et double webhook ;
- interruption après appel provider et refus de paiement ;
- réservation expirée et concurrence sur le dernier article ;
- webhook reçu avant le retour navigateur ;
- indisponibilité CRM et duplication d’activité ;
- rejeu de remboursement et mouvement de stock manquant.

```bash
python3 -m unittest tools.python.tests.test_omnichannel_gate -v
```

Les scénarios UI mutualisés du profil `release` complètent la gate ciblée pour le refus/retry d’un autre moyen de paiement, perte réseau/rafraîchissement, scanner et clavier POS, fulfillment mobile, remboursement administrateur, résolution d’exception de rapprochement, timeline CRM, navigation croisée, FR/EN, accessibilité automatique et navigation clavier. Le rapport v2 exige explicitement leur réussite et l’absence d’impasse.

La stratégie de base de données est exclusivement une reconstruction from scratch de l’instance jetable ; aucune migration n’est planifiée ni exécutée par cette gate.
