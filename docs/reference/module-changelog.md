---
title: Changelog par module
audience:
  - administrator
  - superadministrator
  - installer
  - developer
  - evaluator
status: stable
last_verified: 2026-07-14
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

# 2026-07-14 — Sale M6.1 : ledger de stock et source de vérité unique

- ledger Sale immuable enrichi de l’emplacement, la corrélation et du solde résultant ;
- état physique, réservé et disponible reconstructible et réparable depuis mouvements et réservations ;
- stocks from scratch initialisés exclusivement par mouvements d’ouverture idempotents, sans migration ;
- écritures transactionnelles Business désactivées au profit de Sale et projection Business conservée ;
- contrat Shop `sale.inventory.availability.v1` limité à quatre statuts et `last_available`, sans quantité brute ;
- vue Vente > Stock avec recherche scanner, filtres persistants, alertes, détail, export et assistant en cinq étapes ;
- gate M6 statique, tests PHP/Python/Playwright et preuve machine-readable.

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

# 2026-07-13 — Sale M5.2 : providers de référence

- providers manuel, virement et test déterministe derrière le contrat v1 ;
- confirmation partielle auditée, idempotente et permissionnée ;
- instructions de virement copiables/imprimables et file de rapprochement ;
- badge `MODE TEST`, scénarios développeur et verrou absolu en production ;
- fixtures fictives dans le schéma canonique, sans migration.

# 2026-07-13 — Sale M5.3 : Stripe Checkout réel

- Stripe Checkout derrière le contrat provider v1 et le SDK PHP officiel ;
- sélection par configuration de site/canal, page de retour Shop récupérable ;
- signature du corps brut avec tolérance temporelle, rotation et déduplication ;
- rate-limit webhook, payload expurgé, métriques et reprise idempotente ;
- secrets exclusivement hors base et aucune donnée de carte persistée.
- TWINT proposé dans Stripe Checkout pour les commandes CHF, en mode dynamique ou explicite ; distinction documentée avec TWINT Express Checkout direct.

# 2026-07-13 — Sale M5.3 extension : Revolut Checkout réel

- Hosted Checkout Revolut derrière le même contrat provider v1, activable seul ou avec Stripe ;
- Merchant API `2026-04-20`, environnements sandbox/production et ordre à capture automatique ;
- redirection Shop récupérable, lecture serveur de l'état et annulation ;
- signatures HMAC Revolut vérifiées sur le corps brut avec tolérance et rotation de secret ;
- moyen de paiement ajouté au schéma canonique reconstruit from scratch, sans migration.

# 2026-07-13 — Sale M5.4 : captures, remboursements et réconciliation

- captures immédiates, différées, partielles et multiples pilotées par les capacités du provider ;
- journal durable idempotent avant tout appel externe, reprises exponentielles et dead-letter sans faux échec financier ;
- remboursements partiels, multiples et asynchrones avec motif structuré et lien facultatif vers un retour ;
- réconciliation manuelle ou planifiée des statuts, montants, devises, captures, remboursements et webhooks manquants ;
- centre d'exceptions orienté métier, priorisé, prévisualisation de lot sûre et résolution humaine auditée ;
- écran Paiements enrichi avec capture et remboursement guidés, santé et opérations en attente ;
- scénarios de test couvrant rejeu, dépassements, capture multiple, crash ambigu et réparation contrôlée.

# 2026-07-13 — Sale M5.5 : gate d’interchangeabilité providers

- matrice explicite authorize, capture différée/partielle, remboursement partiel, webhook et réconciliation ;
- suite contractuelle commune au provider test, au manuel, au virement, à Stripe et à Revolut ;
- gate M5 machine-readable intégrée aux profils de qualification `complete` et `release` ;
- comparaison UX Stripe/Revolut sur desktop, mobile, clavier, FR/EN, refus et reprise sans cul-de-sac ;
- sélection par méthode de paiement du canal, support de `PROVIDER_REAL_2` et absence de branche provider dans les contrôleurs ou le Shop ;
- rapport UX et preuves de sécurité sans PII ni secret.

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

# 2026-07-14 — Sale M6.2 : réservations, disponibilité et backorder

- politique de réservation configurable par canal, avec déclencheur documenté par défaut au début du checkout ;
- réservation atomique, renouvellement borné, confirmation, consommation, libération, annulation et expiration idempotente ;
- backorder explicite et traçable, désactivé pour POS et retrait, avec délai annoncé pour la livraison ;
- projection reconstruisible des états `deliverable`, `in_stock`, `backorder` et `unavailable` depuis Sale vers Business/Storefront ;
- récupération Shop sans perte du panier en cas de conflit, vue opérateur dédiée et libération manuelle motivée/auditée ;
- schémas canoniques reconstruits from scratch uniquement, sans migration.

# 2026-07-14 — Business × Sale M6.3 : bundles et stock composé

- stratégies obligatoires `OWN_STOCK`, `COMPONENT_DERIVED` et `NON_STOCKED`, avec compatibilité déterministe de l'ancien mode ;
- plan de composants imbriqués aplati, ratios fusionnés, facteur limitant et disponibilité globale Shop ;
- assistant catalogue avec recherche, quantité, réorganisation, explications et estimation immédiate ;
- réservation atomique par emplacement, consommation idempotente et rattachement opérateur au bundle parent ;
- retours de bundle complet ou de composants explicitement autorisés depuis le snapshot historique ;
- gate M6.3 machine-readable, tests de concurrence/multi-location et schémas canoniques from scratch sans migration.

# 2026-07-14 — Sale M6.4 : fulfillment, transferts, retrait et inventaire

- allocations par emplacement et fulfillments partiels sans double consommation du stock ;
- préparation progressive, problèmes reprenables, expédition et retrait avec code et preuve opérateur ;
- transferts demandés, expédiés puis reçus avec mouvements séparés et écarts motivés ;
- inventaires progressifs ou à l’aveugle, revue et validation permissionnée des ajustements ;
- retours orientés vers le stock vendable, la quarantaine ou le non-vendable ;
- file Opérations responsive et suivi client Shop des retraits, colis et fulfillments partiels ;
- schéma canonique reconstruit from scratch uniquement, sans aucune migration.

# 2026-07-14 — Sale M6.5 : reconstruction et réconciliation du stock

- diagnostic dry-run par défaut comparant caches, journal, réservations, fulfillments, retours, transferts et projection Business/Shop ;
- rapport persistant avec sévérité, causes probables, impact, références et aperçu avant/après ;
- réparation jamais automatique, permission et motif obligatoires, sauvegarde Sale/Business avant écriture et preuve par correction ;
- comptage physique externe traduit en mouvement correctif, sans modification du journal immuable ;
- administration autonome avec téléchargement du rapport, progression, historique et reprise ciblée des échecs ;
- scénario complet et gate M6.5 machine-readable, base vide et restauration vérifiées depuis les schémas canoniques actuels, sans migration.

# 2026-07-14 — IAM × CRM × Sale M7.1 : identité client et dédoublonnage

- bridges réels pour la projection CRM et les identités clients, avec renommage compatible du bridge historique CMS ;
- séparation formelle des profils transactionnel, CRM, IAM et organisation, sans FK inter-base ;
- file de revue avec provenance des champs, concordances, divergences et score de confiance expliqué ;
- résolution conservative par preuve post-achat, e-mail vérifié, téléphone vérifié explicitement autorisé ou action administrative, jamais sur le nom seul ;
- fusion prévisualisée champ par champ, journal complet et séparation auditée sans modification rétrospective des commandes ;
- gate M7.1, tests invité/doublons/multisite et parcours Playwright, sur schémas canoniques from scratch sans migration.
