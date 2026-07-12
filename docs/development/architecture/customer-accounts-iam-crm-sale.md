---
title: Comptes clients IAM CRM Vente
audience:
  - developer
  - api-integrator
  - administrator
status: draft
last_verified: 2026-07-12
source_of_truth: code
source_paths:
  - backend/src/Modules/Sale/Services/SaleCustomerAccountService.php
  - backend/src/Application/PublicApi/PublicCustomerAccountApiHandler.php
  - backend/src/Application/Frontend/PublicCustomerAccountController.php
  - frontend/theme-default/assets/js/customer-account.js
  - database/migrations/iam/0009_customer_accounts.sql
  - database/migrations/business/0006_customer_account_site_links.sql
  - database/migrations/sale/0006_customer_accounts_links.sql
owners:
  - sale
  - business
document_type: specification
generated: false
---
# Comptes clients : séparation IAM, CRM et Vente

Le compte client est optionnel et se crée après un achat invité. Le checkout émet une preuve opaque, hachée en base, liée à une commande, un site et l’e-mail figé dans le snapshot de commande. Cette preuve expire après sept jours et ne peut être consommée qu’une fois.

- IAM possède l’identité, le mot de passe, la vérification d’e-mail et les sessions client HttpOnly.
- CRM possède le contact, ses canaux vérifiés et ses consentements. Aucun consentement marketing n’est déduit de la création du compte.
- Vente possède les liens explicites compte–commande et le carnet d’adresses. Les snapshots historiques des commandes ne sont jamais réécrits.

Un e-mail ou un nom identique ne suffit jamais pour fusionner ou revendiquer une commande. Le rattachement exige la preuve dédiée et une identité vérifiée. Deux contacts CRM non vérifiés restent donc deux contacts. Une fusion est réservée à `sale.customer_accounts.manage`, demande une source, une cible et une justification, désactive le lien source et conserve un avant/après auditable dans `sale_customer_merge_audit`.

Les routes `/api/v1/customer/*` utilisent le cookie `amcms_customer_session` (`HttpOnly`, `SameSite=Lax`, `Secure` en HTTPS), sont isolées par site et ne retournent que les commandes explicitement liées. La désactivation ou suppression IAM révoque l’accès sans supprimer les commandes ni leurs liens historiques dans Vente.

La vue `/account`, servie avec `Cache-Control: no-store, private`, consomme ces routes avec `credentials: include`. Elle expose la connexion, l’historique et le détail des commandes, le carnet d’adresses, le profil et la demande de retour. Après le checkout invité, le formulaire propose facultativement de consommer immédiatement la preuve pour créer le compte.
