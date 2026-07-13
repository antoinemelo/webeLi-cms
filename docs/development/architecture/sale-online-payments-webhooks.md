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
  - backend/src/Modules/Sale/Payments/PaymentProviderContractV1.php
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

`PaymentProviderContractV1` est l'unique frontière utilisée par les services Sale. Il adapte le port d'extension historique et porte le contrat `sale.payment_provider.v1`. Son vocabulaire canonique est : `createPaymentSession`, `updatePaymentSession`, `authorize`, `capture`, `cancel`, `refund`, `verifyWebhookSignature`, `parseWebhook` et `reconcile`. Les contrôleurs ne choisissent jamais une implémentation et ne contiennent aucune branche propre à un provider.

`PaymentProviderRegistry` publie la version et les capacités de chaque implémentation. Un provider ne manipule que des références opaques, montants en unité mineure, devise et états provider normalisés. Les états métier restent portés par la commande, les transactions et remboursements ; les événements provider bruts restent séparés des événements financiers internes.

Le provider `sandbox` est persistant et testable de bout en bout. Il accepte les résultats `success`, `authorize`, `decline`, `abandon` et `timeout`, ainsi qu'un montant de capture partielle. Son jeton d'action n'est retourné qu'à la création et seule son empreinte SHA-256 est conservée. Il ne présente aucun champ carte.

## Moyens disponibles dans Shop

`SalePaymentMethodService` résout les moyens actifs pour le site, le canal storefront, la langue, la devise et le montant. La réponse publique ne contient pas la clé provider. Elle expose le code stable, le libellé localisé, une description, le mode, les capacités utiles, le caractère récupérable et la prochaine action (`redirect`, `display_instructions`, `await_confirmation`, etc.).

Le checkout résout à nouveau le moyen côté serveur avant de placer la commande. Un moyen désactivé, hors devise ou hors bornes de montant est refusé même si son code est envoyé manuellement. Le provider est ensuite obtenu par le registry à partir de la configuration canonique : aucun branchement provider n'est présent dans le contrôleur public.

## Providers de référence M5.2

Les providers `manual_card`, `bank_transfer` et `test` implémentent le même contrat v1.

- **Manuel** : une session reste en attente jusqu'à la confirmation d'un opérateur possédant `sale.payments.confirm`. Montant partiel, référence, commentaire et identifiant de preuve sont tracés dans la transaction et l'événement d'audit. Aucun statut libre n'est accepté.
- **Virement** : Shop reçoit bénéficiaire, IBAN de démonstration/configuré, montant, devise, référence déterministe et délai attendu. La commande reste `pending_payment`; le stock reste réservé. La file **Virements à rapprocher** est triée du plus ancien au plus récent. La confirmation guidée utilise la même action auditée que le manuel.
- **Test déterministe** : les scénarios `success_immediate`, `authorize_then_capture`, `refused`, `temporary_error`, `timeout`, `cancelled`, `duplicate_webhook`, `out_of_order_webhook` et `reconciliation_divergence` sont reproductibles à partir de l'intention et de la clé d'idempotence. Shop affiche en permanence `MODE TEST` et place ces choix dans un panneau développeur.

Le registry n'enregistre ni `test` ni `sandbox` lorsque `APP_ENV` vaut `production` ou `prod`. Une configuration qui les référence reste donc inutilisable et n'est jamais exposée par le sélecteur public. Aucun endpoint de simulation ne peut produire de confirmation dans ce contexte.

### Procédure opérateur

1. Ouvrir **Vente → Paiements** puis, pour les virements, **Virements à rapprocher**.
2. Vérifier commande, client, montant attendu, devise et référence.
3. Saisir le montant effectivement reçu. Un acompte laisse le solde en attente.
4. Ajouter référence, commentaire ou identifiant de preuve seulement si utile.
5. Lire l'impact annoncé puis confirmer. La réponse fournit une confirmation/reçu imprimable.

Une erreur de saisie ne modifie rien. Une même clé d'idempotence ne crée jamais deux transactions. Une annulation précède toute capture ; après capture, utiliser remboursement ou correction auditée selon le cas.

### Limites

La preuve est une référence vers un média déjà autorisé, pas un fichier bancaire interprété automatiquement. L'import de relevé et les connexions bancaires réelles sont futurs. Les coordonnées du fixture sont volontairement fictives et doivent être remplacées dans la configuration de l'instance.

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

`GET /admin/api/sale/payments` fournit la liste filtrable des sessions en conservant la collection historique `payments` pour compatibilité. `GET /admin/api/sale/payments/{id}` regroupe commande, client, montants autorisé/capturé/remboursé, prochaine action et chronologie. Références provider, tentatives, webhooks et rapprochements restent dans un bloc `technical` secondaire.

Les états publics et administratifs sont présentés sans jargon : action requise, reçu, refusé, annulé ou expiré. Les états non terminaux ou en échec déclarent explicitement une action de reprise ; un refus propose une nouvelle tentative ou un autre moyen, et une expiration demande de redémarrer la session.

## Scénario sandbox

1. Appeler le checkout public avec `payment.code=sandbox_online` et une clé d'idempotence.
2. Conserver `payment.reference` et `payment.sandbox_token` de la première réponse.
3. Appeler `POST /api/v1/sale/payments/sandbox/{reference}/simulate` avec le jeton et le résultat voulu.
4. Utiliser `deliver_webhook=false` pour tester un retour navigateur précoce, puis lancer la réconciliation admin.
5. Vérifier que le retour public reste informatif et que la commande ne converge qu'après preuve fiable.

La base Sale est reconstruite intégralement depuis `database/modules/sale.sql`; aucune migration n'est planifiée ni nécessaire pour ce projet réinitialisable.
