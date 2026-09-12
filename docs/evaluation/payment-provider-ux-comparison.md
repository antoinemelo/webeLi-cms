---
title: "Comparaison UX des providers de paiement M5"
audience:
  - administrator
  - developer
  - operator
status: accepted
last_verified: 2026-07-13
source_of_truth: manual
source_paths:
  - frontend/theme-default/assets/js/guest-checkout.js
  - frontend/admin-vue/tests/e2e/payment-provider-interchangeability.spec.ts
  - docs/evaluation/machine-readable/sale-payment-provider-interchangeability.json
owners:
  - sale
document_type: evaluation
generated: false
---
# Comparaison UX des providers de paiement M5

## Conclusion

Stripe Checkout et Revolut Checkout utilisent exactement le même parcours CMS : formulaire de commande, récapitulatif, création d'une commande en attente, bouton générique « Continuer le paiement », page hébergée, retour de confirmation puis attente de la preuve serveur. Le Shop ne contient aucun branchement sur leur identifiant.

| Contrôle | Stripe Checkout | Revolut Checkout | Résultat |
|---|---:|---:|---|
| Étapes significatives avant la page hébergée | 2 | 2 | identique |
| Action FR | Continuer le paiement | Continuer le paiement | identique |
| Action EN | Continue payment | Continue payment | identique |
| Navigation clavier | vérifiée | vérifiée | identique |
| Largeur mobile 390 px | vérifiée | vérifiée | identique |
| Reprise après refus | retour au choix du moyen | retour au choix du moyen | aucun cul-de-sac |
| État après retour navigateur | attente de confirmation serveur | attente de confirmation serveur | identique |
| Mode test | absent | absent | réservé au provider test |
| Back-office | session, état, chronologie, exceptions | session, état, chronologie, exceptions | identique |

## Différences justifiées

Les capacités financières sont affichées uniquement quand elles autorisent réellement une action. Stripe annonce capture différée/partielle et remboursement partiel. Revolut Checkout fonctionne ici en capture automatique et son remboursement asynchrone n'est pas annoncé tant que son lifecycle spécifique n'est pas relié au journal générique. Cette différence n'ajoute aucune étape au checkout client et ne modifie aucun contrôleur.

La sélection se fait par les méthodes de paiement actives du canal. `PAYMENT_REAL_PROVIDERS` active les adapters disponibles ; `PROVIDER_REAL_2` permet d'ajouter explicitement le second adapter. Le canal décide ensuite quelles méthodes sont publiques. Les templates, le panier et le contrat de checkout restent inchangés.

## Preuves

- Suite commune : `tools/php/tests/unit/sale_payment_provider_interchangeability_test.php`.
- Gate machine-readable : `docs/evaluation/machine-readable/sale-payment-provider-interchangeability.json`.
- Gate UX Playwright : `frontend/admin-vue/tests/e2e/payment-provider-interchangeability.spec.ts`.
- Validateur : `tools/python/qualification/payment_provider_gate.py`.
