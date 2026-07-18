---
title: Point 46 — commande, logistique, expédition et suivi client
audience:
  - developer
  - evaluator
  - administrator
status: current
last_verified: 2026-07-17
source_of_truth: code
source_paths:
  - database/modules/sale.sql
  - backend/src/Modules/Sale/Services/SaleFulfillmentService.php
  - backend/src/Modules/Sale/Services/SaleOrderDocumentService.php
  - backend/src/Modules/Sale/Services/SaleOrderNotificationService.php
  - tools/php/tests/unit/sale_order_logistics_tracking_test.php
owners:
  - sale
document_type: evidence
generated: false
---
# Point 46 — commande, logistique, expédition et suivi client

## Conclusion vérifiée

Le dossier de commande, le stock, les réservations et `sale_fulfillments` restent les seules sources métier. Le point 46 les raccorde à une confirmation automatique immuable, au suivi transporteur sécurisé, à un journal de notifications transactionnelles et à une lecture client filtrée. Statut : **démontré** pour le noyau PHP et les contrats ; **partiellement démontré** pour l’acheminement externe des messages et les événements transporteur, qui dépendent d’adaptateurs configurés. Confiance : élevée sur le noyau, moyenne sur les intégrations externes.

## Faits et preuves

- `SaleCheckoutService` émet automatiquement une `order_confirmation` après placement. Son empreinte exclut les états financiers/logistiques ultérieurs : un remboursement ou une livraison ne crée pas artificiellement une nouvelle confirmation.
- La confirmation reprend les lignes, prix, remises, taxes incluses, livraison, total, adresses, mode de livraison et un mode de paiement filtré. Pour « payer à disponibilité », elle précise qu’il ne s’agit ni d’une facture finale ni d’un paiement définitif, donne le délai connu et annonce la demande de paiement séparée.
- `SaleFulfillmentService` conserve allocation, préparation progressive, problèmes de ligne, retrait, expédition et livraison dans le modèle M6 existant. Les transitions exigeant paiement et préparation restent vérifiées côté serveur.
- Une expédition peut porter transporteur, référence et URL HTTPS. Les URL locales, privées, authentifiées, non HTTPS et les domaines incompatibles avec un transporteur connu sont refusés avant la transition.
- `sale_fulfillment_tracking_events` sépare les événements provider de l’état métier. Un incident transporteur ne transforme pas silencieusement une expédition en un autre statut métier.
- `sale_order_notifications` journalise mise en file, tentative, échec et renvoi. Les clés fonctionnelles empêchent les doublons ; un renvoi explicite crée une nouvelle entrée reliée à l’originale.
- L’outbox ne contient ni adresse e-mail en clair, ni snapshot de commande, ni secret de paiement. Le consentement marketing n’est jamais consulté pour ces messages transactionnels.
- Le suivi client authentifié expose prochaine étape, fulfillments partiels, suivi, documents et chronologie filtrée. Le code de vérification du retrait n’est jamais exposé.
- Le suivi invité réutilise la preuve post-achat opaque, expirante et révocable. Aucun endpoint n’accepte le seul numéro séquentiel de commande.

## Commandes exécutées

```text
php tools/php/tests/unit/sale_order_logistics_tracking_test.php
[OK] UNIT M8.8 order logistics tracking and notifications (13 assertions)

php tools/php/tests/unit/sale_customer_accounts_test.php
[OK] UNIT sale customer accounts IAM CRM Sale (37 assertions)

php tools/php/tests/unit/sale_logistics_workflows_test.php
[OK] UNIT sale fulfillment transfers pickup and inventory (25 assertions)

php tools/php/tests/unit/sale_order_dossier_workflow_test.php
[OK] UNIT sale order dossier workflow (25 assertions)

php tools/php/tests/unit/sale_domain_workflows_test.php
[OK] UNIT sale domain workflows (49 assertions)

php tools/php/tests/unit/sale_public_api_handler_test.php
[OK] UNIT sale public ecommerce API (87 assertions)

php tools/php/tests/unit/sale_module_contracts_test.php
[OK] UNIT sale module contracts (1213 assertions)

npm --prefix frontend/admin-vue run build
Succès : vue-tsc puis build Vite.

python3 tools/cms.py e2e --use-built-assets --spec order-logistics-tracking-46.spec.ts
1 passed (33.7s), instance isolée reconstruite.

php -r '... new App\Core\Migrator(...0013_order_logistics_tracking.sql)...'
Migration appliquée sur une copie SQLite temporaire ; `PRAGMA foreign_key_check` vide et événement `sale.notification.requested` insérable.

python3 tools/cms.py validate --category configuration --category database --category content --category permissions --category api --category operations --category security --category documentation --category shared
Tous les validateurs sélectionnés sont OK, dont MIGRATION_SAFETY, API_SPEC, PII_EMAIL_GUARD et I18N_COVERAGE.

php tools/php/tests/run.php
PHP functional suites: OK. Quatre scénarios HTTP ignorés car le bind TCP était indisponible dans le bac à sable.
```

## Limites

- Le PDF n’est pas déclaré comme démontré : l’infrastructure actuelle fournit un HTML figé et imprimable.
- Le journal prouve la mise en file et l’état de l’outbox. La remise effective dépend du worker et du transport configurés.
- Aucun agrégateur transporteur n’est imposé. Le mode manuel est opérationnel ; les événements provider disposent d’un port HTTP/admin mais exigent un adaptateur externe pour l’automatisation.
- Le scan mobile repose encore sur la recherche/SKU et les contrôles de quantité existants ; une intégration caméra native n’est pas démontrée.
- Les tests unitaires couvrent replay, sécurité URL, snapshot, retrait et suivi signé. Le scénario navigateur ciblé couvre le fulfillment partiel, le lien sécurisé, la chronologie, le renvoi et l’absence de débordement mobile ; la suite E2E complète n’a pas été rejouée dans ce point.

## Contradiction levée

Avant ce point, une référence de suivi libre et un code de retrait pouvaient apparaître dans les données, sans URL validée, chronologie provider séparée ni état de notification consultable. Leur simple présence ne démontrait donc pas un suivi client fiable. Le schéma, les refus serveur et les scénarios ci-dessus apportent désormais cette preuve sous les limites énoncées.
