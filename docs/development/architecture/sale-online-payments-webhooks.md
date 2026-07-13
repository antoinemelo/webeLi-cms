---
title: Paiements en ligne, providers et webhooks Sale
audience:
  - developer
  - administrator
  - evaluator
status: stable
last_verified: 2026-07-13
source_of_truth: code
source_paths:
  - database/modules/sale.sql
  - backend/src/Modules/Sale/Payments/OnlinePaymentProvider.php
  - backend/src/Modules/Sale/Payments/SandboxPaymentProvider.php
  - backend/src/Modules/Sale/Services/SaleOnlinePaymentService.php
  - tools/php/tests/unit/sale_online_payment_workflow_test.php
owners:
  - sale
document_type: architecture
generated: false
---

# Paiements en ligne, providers et webhooks Sale

## Contrat provider v1

`OnlinePaymentProvider` porte le contrat `sale.payment_provider.v1` sans casser le port historique des paiements locaux. Il couvre création d'intention, capture, annulation, remboursement, lecture d'état et normalisation d'un webhook vérifié. Une implémentation ne manipule que des références opaques, montants en unité mineure, devise et états normalisés.

Le provider `sandbox` est persistant et testable de bout en bout. Il accepte les résultats `success`, `authorize`, `decline`, `abandon` et `timeout`, ainsi qu'un montant de capture partielle. Son jeton d'action n'est retourné qu'à la création et seule son empreinte SHA-256 est conservée. Il ne présente aucun champ carte.

## Workflow commande, stock et paiement

Pour `payment.code=sandbox_online`, le checkout crée d'abord une commande `pending_payment`. Les snapshots client, adresses, livraison, lignes, taxes, montants et méthode de paiement sont alors figés. Le panier devient `converted`, mais ses réservations restent `confirmed` avec une échéance.

Le retour navigateur lit les états local et provider et retourne toujours `payment_proof=false`. Il ne modifie ni commande, ni paiement, ni stock. Seuls un webhook signé et frais, ou une lecture provider exécutée par la réconciliation administrative, peuvent appliquer une transition fiable.

Une capture complète alloue exactement le montant reçu, confirme la commande et consomme les réservations dans la même transaction. Une capture partielle garde la commande en attente et le stock réservé. Un refus, abandon ou timeout sans montant capturé annule la commande et libère les réservations. L'expiration locale applique la même politique.

## Webhooks

Le endpoint serveur-à-serveur est `POST /api/v1/sale/payments/webhooks/{provider}`. Pour le sandbox, `X-Sale-Signature` utilise :

```text
t=<timestamp_unix>,v1=HMAC_SHA256(secret, timestamp + "." + corps_brut)
```

La tolérance est de cinq minutes. L'identifiant `(provider_key, provider_event_id)` est unique. Un rejeu retourne un succès idempotent sans nouvelle transaction. Un événement antérieur à `last_provider_event_at` est conservé avec l'état `ignored_out_of_order`, mais ne peut pas faire régresser le paiement. Les événements traités sont immuables.

Les payloads persistés passent par une expurgation récursive des clés PAN, numéro de carte, CVC/CVV, expiration et secret. Les logs ne contiennent que site, provider, type de métrique, sévérité et identifiant interne d'intention.

## Réconciliation et observabilité

`POST /admin/api/sale/payments/reconcile` compare l'état provider et l'état local. Il répare une capture provider absente localement, applique un état terminal tardif, et déclenche une alerte critique si une capture locale n'a aucune confirmation provider. Un second passage sans changement est `consistent`.

`POST /admin/api/sale/payments/expire` termine les intentions arrivées à échéance. `GET /admin/api/sale/payments/observability` expose les compteurs, alertes récentes et résultats de réconciliation. Les événements minimaux suivis incluent création d'intention, webhook traité, doublon, ordre invalide et divergence.

## Scénario sandbox

1. Appeler le checkout public avec `payment.code=sandbox_online` et une clé d'idempotence.
2. Conserver `payment.reference` et `payment.sandbox_token` de la première réponse.
3. Appeler `POST /api/v1/sale/payments/sandbox/{reference}/simulate` avec le jeton et le résultat voulu.
4. Utiliser `deliver_webhook=false` pour tester un retour navigateur précoce, puis lancer la réconciliation admin.
5. Vérifier que le retour public reste informatif et que la commande ne converge qu'après preuve fiable.

La base Sale est reconstruite intégralement depuis `database/modules/sale.sql`; aucun historique d'évolution de schéma n'est requis pour ce projet réinitialisable.
