---
title: Point 45 — bons cadeaux, émission, solde et utilisation
audience:
  - developer
  - evaluator
  - administrator
status: current
last_verified: 2026-07-17
source_of_truth: code
source_paths:
  - database/modules/sale.sql
  - backend/src/Modules/Sale/Services/SaleGiftCardService.php
  - tools/php/tests/unit/sale_gift_card_lifecycle_test.php
owners:
  - sale
document_type: evidence
generated: false
---
# Point 45 — bons cadeaux, émission, solde et utilisation

## Conclusion vérifiée

Le type catalogue `gift_card` est désormais raccordé à un instrument de valeur Sale. Le comportement démontré couvre l’émission après paiement intégral, la livraison par jeton de révélation à usage unique, la validation publique limitée, le débit atomique, le complément par un provider, le recrédit et le remboursement, ainsi que le back-office masqué. Statut : **démontré** pour le noyau et les contrats ; **partiellement démontré** pour la livraison e-mail réelle, qui dépend du transport configuré. Confiance : élevée.

## Faits et preuves

- Le schéma canonique `database/modules/sale.sql` porte une politique par site/devise, le bon, son journal, ses livraisons et la fenêtre de limitation publique. Aucune migration n’a été ajoutée.
- `SaleGiftCardService` ne conserve que `code_verifier` (HMAC-SHA-256) et `code_last4`. La révélation utilise AES-256-GCM et un jeton dont seul le hash est stocké.
- Les triggers `trg_sale_gift_card_ledger_immutable_update/delete` refusent la modification ou la suppression d’une écriture.
- Une contrainte unique `(origin_order_line_id, origin_unit_number)` empêche une double émission. Une clé unique du ledger empêche le double débit.
- Le débit et la création de commande partagent la même transaction SQLite. Le paiement provider est créé sur `grand_total_minor - paid_total_minor`.
- Les produits `gift_card` ne sont pas éligibles au paiement par bon. Un seul bon par commande est accepté ; ce refus est explicite dans la politique canonique.
- `sale.gift_card.issued` et `sale.gift_card.redeemed` ne contiennent ni code ni jeton. La Relation 360 les projette depuis la commande d’origine sans modifier les consentements.
- Les routes publiques de validation et de révélation sont `no-store`. La validation renvoie un résultat neutre et est limitée par empreinte de requérant.
- Le back-office `/sale/gift-cards` n’affiche que la référence interne, les quatre derniers caractères, le statut, le solde, l’origine et le journal. Renvoi, annulation et ajustement exceptionnel sont permissionnés ; l’ajustement exige un aperçu, un motif et une clé d’idempotence.

## Politique fonctionnelle

- promotions : appliquées avant le bon, puisque le débit porte sur le total déjà calculé ;
- taxes et livraison : éligibles dans le total final ;
- achat d’un autre bon : exclu ;
- plusieurs bons : non supporté et refusé ;
- expiration : contrôlée à la première consultation/utilisation et inscrite au journal ;
- annulation : solde ramené à zéro avec motif opérateur obligatoire ;
- échec provider récupérable : débit conservé pour permettre la reprise ;
- annulation ou expiration provider terminale : recrédit et correction financière corrélés ;
- remboursement : la part payée par bon est recréditée sur le bon d’origine.

## Commandes exécutées

```text
php tools/php/tests/unit/sale_gift_card_lifecycle_test.php
[OK] UNIT gift card lifecycle M8.7 (33 assertions)

php tools/php/tests/unit/sale_online_payment_workflow_test.php
[OK] UNIT sale online payment workflow (69 assertions)

php tools/php/tests/unit/sale_internal_sales_test.php
[OK] UNIT sale internal payments returns receipts timeline (43 assertions)

php tools/php/tests/unit/sale_public_api_handler_test.php
[OK] UNIT sale public ecommerce API (84 assertions, avant ajout des 3 contrôles de route du point 45)

php tools/php/tests/unit/sale_admin_api_controller_test.php
[OK] UNIT sale admin API controller (253 assertions)

php tools/php/tests/unit/sale_crm_activity_projection_test.php
[OK] UNIT Sale to CRM activity projection (39 assertions)

npm --prefix frontend/admin-vue run build
Succès : vue-tsc puis build Vite.

python3 tools/cms.py e2e --use-built-assets --spec gift-card-lifecycle-45.spec.ts
2 passed (15.5s), instance isolée reconstruite.

php tools/php/tests/run.php
PHP functional suites: OK. Quatre scénarios HTTP ont été explicitement ignorés car le bind TCP du serveur PHP était indisponible dans ce bac à sable.
```

## Limites

- Le scénario navigateur dédié est exécuté. La suite E2E complète n’a pas été rejouée dans ce point ; seules les deux preuves ciblées du point 45 sont démontrées.
- Le succès de l’envoi e-mail dépend de `mail.transport`. En transport désactivé, le jeton reste accessible au retour autorisé et au back-office de renvoi, mais aucun e-mail externe n’est démontré.
- Le débit concurrent est protégé par transaction SQLite et compare-and-swap de version ; un test multi-processus complet reste souhaitable sur la cible de déploiement.
- La clé `APP_GIFT_CARD_SIGNING_KEY` doit être propre à l’instance et stable. Sa rotation invalide les codes et jetons existants ; aucune rotation transparente n’est fournie.

## Contradiction levée

Avant ce point, le type, la politique Business et les noms d’événements existaient, mais aucune table de bons, aucun ledger et aucun débit checkout n’étaient exécutables. Leur présence ne constituait donc pas une preuve de fonctionnalité. Le test du cycle de vie apporte maintenant cette preuve, sous les limites ci-dessus.
