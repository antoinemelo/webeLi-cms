---
title: "ADR — premier provider de paiement réel"
audience:
  - developer
  - administrator
  - operator
status: accepted
last_verified: 2026-07-13
source_of_truth: manual
source_paths:
  - backend/src/Modules/Sale/Payments
  - backend/config/app.php
owners:
  - sale
document_type: architecture
generated: false
---
# ADR — Stripe Checkout comme premier provider réel

## Décision

Le provider réel v1 est `stripe_checkout`, activé explicitement par environnement. Stripe Checkout garde la saisie carte sur une page hébergée et s'intègre au contrat `sale.payment_provider.v1` sans branche Storefront spécifique. Le retour navigateur ne confirme rien : seul un webhook signé ou une réconciliation serveur fait évoluer la commande.

## Options examinées

| Option | Points favorables | Limite pour cette première intégration |
|---|---|---|
| Stripe Checkout | SDK PHP officiel maintenu, page hébergée, idempotence native, fixtures/CLI et signatures documentées | dépendance SaaS et frais à qualifier par l'exploitant |
| Mollie Checkout | API simple et page hébergée | couverture suisse et choix contractuel à valider selon le marchand |
| Datatrans | acteur suisse et large portefeuille local | contractualisation et environnement de test plus spécifiques |

Stripe est retenu pour réduire la surface PCI et parce que son SDK officiel couvre création/récupération de session, remboursement et vérification de signature. Références : [SDK PHP officiel](https://github.com/stripe/stripe-php), [création de Checkout Session](https://docs.stripe.com/api/checkout/sessions/create?lang=php), [sécurisation des webhooks](https://docs.stripe.com/webhooks).

## Garanties

- secrets exclusivement dans l'environnement runtime, jamais dans Sale SQLite ;
- corps brut vérifié avant parsing, tolérance par défaut de 300 secondes ;
- rotation par secret courant et précédent ;
- identifiant `evt_*` unique et traitement idempotent ;
- payload conservé expurgé, sans PAN/CVC ;
- page `/checkout/confirmation` récupérable et `no-store/noindex` ;
- provider invisible dans le Shop tant que la configuration n'est pas complète.

## Configuration et exploitation

Définir `PAYMENT_REAL_PROVIDER=stripe_checkout`, `PAYMENT_STRIPE_ENABLED=1`, `PAYMENT_STRIPE_ENV=test|production`, `STRIPE_SECRET_KEY` et `STRIPE_WEBHOOK_SECRET`. `STRIPE_WEBHOOK_SECRET_PREVIOUS` permet une rotation transitoire. `APP_PUBLIC_BASE_URL` doit être une URL HTTPS publique. L'endpoint Stripe est `/api/v1/sale/payments/webhooks/stripe_checkout` et doit recevoir uniquement `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed` et `checkout.session.expired`.

La recette UX vérifie : refus et reprise sans ressaisie, double clic neutralisé, refresh de la confirmation, retour tardif, perte réseau avec message récupérable, affichage mobile et annonce `aria-live`. Une vraie recette sandbox nécessite des secrets Stripe fournis par l'instance ; les tests du dépôt utilisent un payload signé représentatif et l'algorithme du SDK officiel.
