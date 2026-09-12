---
title: Comptes clients IAM CRM Vente
audience:
  - developer
  - api-integrator
  - administrator
status: stable
last_verified: 2026-07-14
source_of_truth: code
source_paths:
  - backend/src/Modules/Sale/Services/SaleCustomerAccountService.php
  - backend/src/Application/PublicApi/PublicCustomerAccountApiHandler.php
  - backend/src/Application/Frontend/PublicCustomerAccountController.php
  - frontend/theme-default/assets/js/customer-account.js
  - frontend/admin-vue/src/views/modules/SaleIdentityReviewView.vue
  - database/iam.sql
  - database/modules/business.sql
  - database/modules/sale.sql
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

Les quatre objets restent explicitement distincts : `TransactionalCustomer` fige l’identité de vente, `IamAccount` authentifie, `CrmContact` porte la relation, et `Organization` représente l’entreprise éventuelle. Les tables de liaison conservent leurs identifiants sans FK entre bases.

Un e-mail ou un nom identique ne suffit jamais pour fusionner ou revendiquer une commande. Le rattachement exige la preuve dédiée et une identité vérifiée. Deux contacts CRM non vérifiés restent donc deux contacts. Le téléphone normalisé n’est comparé que si l’opérateur autorise cette stratégie et si le canal est vérifié. Le nom n’ajoute qu’un faible signal explicatif et ne crée jamais un candidat à lui seul.

La vue **Vente > Identités** construit une file conservatrice à partir des commandes non liées. Chaque cas montre les profils séparés, les sources `checkout`, `account`, `import`, `operator` ou `event`, les concordances pondérées, les divergences, les commandes et les activités concernées. Les actions `lier`, `ne pas lier` et `reporter` exigent un motif et écrivent `sale_identity_resolution_audit`.

Une fusion est réservée à `sale.customer_accounts.manage`. Son aperçu compare chaque champ et exige un choix explicite pour tous les conflits ; l’e-mail vérifié cible ne peut pas être remplacé silencieusement. Le journal `sale_customer_merge_audit` conserve avant, après et décisions de champs. Une séparation auditée rétablit les liens de commandes et d’adresses enregistrés avant la fusion. Fusion comme séparation laissent `customer_snapshot_json` strictement inchangé.

Les routes `/api/v1/customer/*` utilisent le cookie `amcms_customer_session` (`HttpOnly`, `SameSite=Lax`, `Secure` en HTTPS), sont isolées par site et ne retournent que les commandes explicitement liées. La désactivation ou suppression IAM révoque l’accès sans supprimer les commandes ni leurs liens historiques dans Vente.

La vue `/account`, servie avec `Cache-Control: no-store, private`, consomme ces routes avec `credentials: include`. Elle expose la connexion, l'historique et le détail des commandes, le carnet d'adresses, le profil et la demande de retour. Après le checkout invité, le formulaire propose facultativement de consommer immédiatement la preuve pour créer le compte.

`CustomerIdentityBridge` remplace le nom historique ambigu `CmsAccountBridge`, conservé uniquement comme alias de compatibilité. `SaleCustomerAccountService` est l’adaptateur réel IAM–CRM–Sale. `NullCustomerIdentityBridge` et l’ancien `NullCmsAccountBridge` ne sont que des fallbacks de test. Le port `CrmActivitySink` est branché sur `SaleCrmActivityProjectionService`, et non plus sur le sink nul.

La projection d’activités suit le contrat non sensible `crm.activity.v2`, documenté dans [Activités CRM alimentées par événements](crm-event-activities.md). Elle consomme l’outbox, conserve les événements sans identité puis les rattache par corrélation ou décision auditée, sans lecture continue des tables transactionnelles.

Les schémas sont canoniques et reconstruits from scratch. Aucune migration n’est requise ni planifiée.
