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

## TWINT dans Stripe et TWINT Express Checkout

Pour une commande en CHF, Stripe Checkout peut afficher TWINT avec la même session et les mêmes webhooks. Le mode `dynamic` recommandé laisse le Dashboard Stripe activer et ordonner les moyens disponibles ; `explicit` envoie `payment_method_types=[card,twint]`, tandis que `off` force la carte. TWINT doit être activé et approuvé dans le compte Stripe. Voir la [documentation Stripe TWINT](https://docs.stripe.com/payments/twint/accept-a-payment?locale=fr-FR).

Le produit direct **TWINT Express Checkout** n'est pas un simple moyen de paiement : il transmet, avec consentement, les données TWINT ID afin de court-circuiter une partie du formulaire. Il nécessite l'onboarding du portail commerçant, l'UUID du shop et les éléments d'intégration remis au marchand. Il devra devenir un provider/flux express séparé après obtention de ces éléments ; il ne faut pas présenter TWINT via Stripe comme « Express Checkout ».

## Garanties

- secrets exclusivement dans l'environnement runtime, jamais dans Sale SQLite ;
- corps brut vérifié avant parsing, tolérance par défaut de 300 secondes ;
- rotation par secret courant et précédent ;
- identifiant `evt_*` unique et traitement idempotent ;
- payload conservé expurgé, sans PAN/CVC ;
- page `/checkout/confirmation` récupérable et `no-store/noindex` ;
- provider invisible dans le Shop tant que la configuration n'est pas complète.

## Configuration et exploitation

Définir `PAYMENT_REAL_PROVIDER=stripe_checkout`, `PAYMENT_STRIPE_ENABLED=1`, `PAYMENT_STRIPE_ENV=test|production`, `STRIPE_SECRET_KEY` et `STRIPE_WEBHOOK_SECRET`. `PAYMENT_STRIPE_TWINT_MODE=dynamic` active le pilotage depuis Stripe Dashboard. `STRIPE_WEBHOOK_SECRET_PREVIOUS` permet une rotation transitoire. `APP_PUBLIC_BASE_URL` doit être une URL HTTPS publique. L'endpoint Stripe est `/api/v1/sale/payments/webhooks/stripe_checkout` et doit recevoir uniquement `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed` et `checkout.session.expired`.

La recette UX vérifie : refus et reprise sans ressaisie, double clic neutralisé, refresh de la confirmation, retour tardif, perte réseau avec message récupérable, affichage mobile et annonce `aria-live`. Une vraie recette sandbox nécessite des secrets Stripe fournis par l'instance ; les tests du dépôt utilisent un payload signé représentatif et l'algorithme du SDK officiel.
