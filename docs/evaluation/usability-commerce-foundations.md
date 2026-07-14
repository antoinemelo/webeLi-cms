---
title: Gate d’utilisabilité Commerce M5–M7
audience:
  - developer
  - evaluator
  - administrator
status: current
last_verified: 2026-07-14
source_of_truth: code
source_paths:
  - frontend/admin-vue/tests/e2e/commerce-usability-gate.spec.ts
  - docs/evaluation/machine-readable/usability-commerce-foundations.json
  - tools/python/qualification/usability_commerce_gate.py
owners:
  - sale
  - business
  - core
document_type: procedure
generated: false
---

# Gate d’utilisabilité Commerce M5–M7

Cette gate vérifie que les fondations Commerce livrées aux jalons M5 à M7 restent compréhensibles, accessibles et récupérables pour leurs cinq rôles critiques. Elle prépare M8, mais ne remplace ni la gate finale du Shop ni une étude avec des utilisateurs réels.

## Résultat

La revue structurée et les contrôles automatisés ne relèvent aucun blocage P0. Les parcours de référence sont utilisables au clavier, sans débordement horizontal sur les vues mobiles contrôlées, avec un focus visible et des commandes nommées. Les états métier plus profonds restent prouvés par leurs scénarios E2E M5–M7 existants; cette gate les agrège au lieu de les recopier.

| Rôle | Tâches critiques couvertes | Sources principales |
| --- | --- | --- |
| Client mobile | découverte, fiche, panier, commande invitée, paiement et récupération | `commerce-usability-gate.spec.ts`, gate omnicanale, gate providers |
| Opérateur POS | session, recherche, encaissement, erreur terminal, ticket, retour, clôture | vue POS et scénarios POS/omnicanal |
| Opérations | réservation bloquée, exécution partielle, retrait, transfert, inventaire, réconciliation | vue Opérations et scénarios stock |
| Service client / finance | commande, paiement, remboursement partiel, exception, lien CRM | vue Vente et scénarios omnicanal/stock |
| CRM | chronologie, doublon, provenance, segment, consentement | vue Relations et scénarios CRM |

## Revue structurée des captures

Les captures sont recréées dans `storage/qualification/usability/captures/` par l’environnement Playwright isolé. Leur SHA-256 est inscrit dans le rapport runtime afin qu’une preuve issue d’une autre exécution ne puisse pas être substituée silencieusement.

| Capture | Point contrôlé | Observation structurée |
| --- | --- | --- |
| `shop-mobile.png` | découverte sur 390 × 844 | parcours fonctionnel et sans débordement; filtres natifs et cartes encore trop denses, à finaliser dans M8 |
| `product-mobile.png` | fiche et tiroir panier | disponibilité, prix et action principale restent visibles; ajout au clavier prouvé |
| `checkout-mobile.png` | commande invitée | sections, libellés, consentements et action suivante explicites; aucune saisie personnelle dans la preuve |
| `pos-desktop.png` | poste de vente | état de session, catalogue et encaissement regroupés dans le contexte opérateur |
| `operations-mobile.png` | exécution logistique | fonctions spécialisées séparées par onglets; diagnostic de reconstruction explicite |
| `finance-desktop.png` | paiements et exceptions | état, prochaine action, test mode et récupération sont distingués |
| `crm-mobile.png` | relations | vue cartes sans débordement; accès visible aux détails et aux actions liées |

## Évaluation heuristique

Échelle : 0 bloque la release; 1 exige une correction ou une dérogation documentée; 2 est acceptable avec amélioration; 3 est satisfaisant. Les valeurs détaillées par dimension et par écran sont la source machine `usability-commerce-foundations.json`. Aucun score 0 ou 1 n’est accepté par le validateur.

Les huit dimensions contrôlées sont la clarté, la cohérence, la prévention des erreurs, la récupération, l’efficacité, l’accessibilité, la lisibilité mobile et l’explication du statut. Les scores 2 reflètent principalement la densité intrinsèque des postes POS/finance et le besoin de tests d’usage réels; ils ne masquent pas un blocage fonctionnel.

## États et récupération

La matrice couvre les états chargement, vide, succès, erreur récupérable, erreur bloquante, partiel, permission refusée et indisponible. Elle vérifie notamment la conservation de saisie lors d’un refus de paiement, le retour à la quantité précédente au POS, les exécutions et remboursements partiels, ainsi que les aperçus avant correction destructive.

Les liens entre objets restent explicites : commande vers relation CRM, différence de stock vers mouvements/réservations et paiement vers commande. Une erreur récupérable présente une action suivante; une permission refusée n’est pas présentée comme une panne.

## Instrumentation respectueuse de la vie privée

Le contrat `commerce.usability.v1` décrit six événements anonymes : succès, abandon, erreur, récupération, durée par tranche et annulation. La collecte est désactivée par défaut et le payload suit une liste blanche fermée. Aucun nom, e-mail, adresse, téléphone, identifiant client/commande, texte libre ou donnée de carte n’est admis. La gate prouve le contrat avec des échantillons synthétiques; elle n’active aucun transport analytique.

## Recommandations

- P0 : aucune.
- P1 : organiser des tests avec des utilisateurs réels avant la gate Shop M8, avec au minimum un client mobile, un opérateur POS et un profil opérations/finance.
- P1 : finaliser dans M8 la présentation mobile des filtres et des cartes de la liste Shop; cette gate de fondations ne se substitue pas à la gate Shop finale.
- P2 : après consentement et revue de protection des données, mesurer uniquement des agrégats de durée, abandon et récupération par rôle.

## Limites

Cette preuve automatisée et sa revue structurée ne constituent pas un test avec de vrais utilisateurs. Les captures Chromium ne couvrent pas toutes les combinaisons de navigateur, d’appareil, de zoom et de technologie d’assistance. Elles forment une baseline reproductible; une étude terrain reste nécessaire pour valider le vocabulaire, la charge cognitive et la rapidité réelle des tâches.
