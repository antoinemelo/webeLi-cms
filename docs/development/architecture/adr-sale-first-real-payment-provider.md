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
# ADR — providers de paiement réel Stripe et Revolut Checkout

## Décision

Les providers réels v1 sont `stripe_checkout` et `revolut_checkout`, activés explicitement et indépendamment par environnement. Ils gardent la saisie du paiement hors du CMS et s'intègrent au contrat `sale.payment_provider.v1` sans branche Storefront spécifique. Le retour navigateur ne confirme rien : seul un webhook signé ou une réconciliation serveur fait évoluer la commande.

## Options examinées

| Option | Points favorables | Limite pour cette première intégration |
|---|---|---|
| Stripe Checkout | SDK PHP officiel maintenu, page hébergée, idempotence native, fixtures/CLI et signatures documentées | dépendance SaaS et frais à qualifier par l'exploitant |
| Revolut Checkout hébergé | page hébergée, Merchant API order-based, Revolut Pay et autres moyens pilotés par Revolut | compte Revolut Business Merchant et onboarding propres à l'instance |
| Mollie Checkout | API simple et page hébergée | couverture suisse et choix contractuel à valider selon le marchand |
| Datatrans | acteur suisse et large portefeuille local | contractualisation et environnement de test plus spécifiques |

Stripe est retenu pour réduire la surface PCI et parce que son SDK officiel couvre création/récupération de session, remboursement et vérification de signature. Références : [SDK PHP officiel](https://github.com/stripe/stripe-php), [création de Checkout Session](https://docs.stripe.com/api/checkout/sessions/create?lang=php), [sécurisation des webhooks](https://docs.stripe.com/webhooks).

Revolut Checkout est ajouté comme provider alternatif ou simultané. Le CMS crée un ordre Merchant API en capture automatique, fixe ensuite son URL de retour avec l'identifiant opaque Revolut et redirige vers `checkout_url`. Les états sont relus côté serveur ; les événements `ORDER_COMPLETED`, `ORDER_AUTHORISED`, `ORDER_CANCELLED` et `ORDER_FAILED` sont les seuls événements terminaux consommés. Références : [Hosted Checkout Page](https://developer.revolut.com/docs/guides/merchant/accept-payments/online-payments/hosted-checkout-page/api), [Merchant API](https://developer.revolut.com/docs/api/merchant/2026-04-20), [signature des webhooks](https://developer.revolut.com/docs/guides/merchant/monitor-and-observe/webhooks/verify-the-payload-signature).

La capture manuelle et le remboursement Revolut ne sont pas annoncés dans cette version : le checkout utilise la capture automatique et l'API Revolut traite les remboursements de façon asynchrone. Leur activation attend un état local `refund.pending` relié à l'ordre de remboursement Revolut, afin de ne jamais présenter comme terminé un remboursement seulement accepté par l'API.

## TWINT dans Stripe et TWINT Express Checkout

Pour une commande en CHF, Stripe Checkout peut afficher TWINT avec la même session et les mêmes webhooks. Le mode `dynamic` recommandé laisse le Dashboard Stripe activer et ordonner les moyens disponibles ; `explicit` envoie `payment_method_types=[card,twint]`, tandis que `off` force la carte. TWINT doit être activé et approuvé dans le compte Stripe. Voir la [documentation Stripe TWINT](https://docs.stripe.com/payments/twint/accept-a-payment?locale=fr-FR).

Le produit direct **TWINT Express Checkout** n'est pas un simple moyen de paiement : il transmet, avec consentement, les données TWINT ID afin de court-circuiter une partie du formulaire. Il nécessite l'onboarding du portail commerçant, l'UUID du shop et les éléments d'intégration remis au marchand. Il devra devenir un provider/flux express séparé après obtention de ces éléments ; il ne faut pas présenter TWINT via Stripe comme « Express Checkout ».

## Garanties

- secrets exclusivement dans l'environnement runtime, jamais dans Sale SQLite ;
- corps brut vérifié avant parsing, tolérance par défaut de 300 secondes ;
- rotation par secret courant et précédent ;
- identifiant provider ou empreinte stable du corps unique et traitement idempotent ;
- payload conservé expurgé, sans PAN/CVC ;
- page `/checkout/confirmation` récupérable et `no-store/noindex` ;
- provider invisible dans le Shop tant que la configuration n'est pas complète.

## Configuration et exploitation

Définir `PAYMENT_REAL_PROVIDER=stripe_checkout`, `PAYMENT_STRIPE_ENABLED=1`, `PAYMENT_STRIPE_ENV=test|production`, `STRIPE_SECRET_KEY` et `STRIPE_WEBHOOK_SECRET`. `PAYMENT_STRIPE_TWINT_MODE=dynamic` active le pilotage depuis Stripe Dashboard. `STRIPE_WEBHOOK_SECRET_PREVIOUS` permet une rotation transitoire. `APP_PUBLIC_BASE_URL` doit être une URL HTTPS publique. L'endpoint Stripe est `/api/v1/sale/payments/webhooks/stripe_checkout` et doit recevoir uniquement `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed` et `checkout.session.expired`.

Pour Revolut, définir `PAYMENT_REAL_PROVIDERS=stripe_checkout,revolut_checkout` (ou seulement `PAYMENT_REAL_PROVIDER=revolut_checkout`), `PAYMENT_REVOLUT_ENABLED=1`, `PAYMENT_REVOLUT_ENV=sandbox|production`, `REVOLUT_MERCHANT_SECRET_KEY` et `REVOLUT_WEBHOOK_SECRET`. La rotation transitoire utilise `REVOLUT_WEBHOOK_SECRET_PREVIOUS`. `REVOLUT_API_VERSION` vaut `2026-04-20` par défaut. L'endpoint est `/api/v1/sale/payments/webhooks/revolut_checkout`; le webhook Revolut doit être inscrit pour `ORDER_COMPLETED`, `ORDER_AUTHORISED`, `ORDER_CANCELLED` et `ORDER_FAILED`.

La recette UX vérifie : refus et reprise sans ressaisie, double clic neutralisé, refresh de la confirmation, retour tardif, perte réseau avec message récupérable, affichage mobile et annonce `aria-live`. Une vraie recette sandbox nécessite les secrets du provider fournis par l'instance ; les tests du dépôt utilisent des gateways isolés et des payloads signés représentatifs.
