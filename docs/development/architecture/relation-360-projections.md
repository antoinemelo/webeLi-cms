---
title: Architecture Relation 360 et projections CRM
audience:
  - developer
  - architect
status: current
last_verified: 2026-07-15
source_of_truth: code
source_paths:
  - database/modules/business.sql
  - backend/src/Modules/Business/Services/BusinessRelation360Service.php
  - backend/src/Modules/Business/Services/FormSubmissionRelationProjectionService.php
owners:
  - business
  - sale
  - core
document_type: architecture
generated: false
---
# Architecture Relation 360 et projections CRM

La Relation 360 est un assemblage de lecture dans le module Opérations. Elle ne devient pas propriétaire des commandes ni des soumissions.

| Donnée | Propriétaire canonique | Transport vers Relation 360 | Écriture depuis la fiche |
|---|---|---|---|
| relation, coordonnées, rôles, tâches | Business CRM | lecture locale | oui, permission CRM manage |
| commande, paiement, remboursement, livraison | Sale | outbox / `crm_sale_activities` | non, lien vers Ventes |
| formulaire et contenu soumis | Core Forms | `FormSubmissionActivitySink` / projection minimisée | non, lien vers Formulaires |
| consentement et preuve | Business CRM | lecture locale permissionnée | via API consentement existante |
| compte et rapprochement client | IAM + Sale + Business | service de revue Sale réutilisé | décision avancée auditée |

Les liens vers une commande utilisent la route canonique Ventes et encodent un `return_to` limité à la fiche Relation. L’écran Commandes valide cette destination avant d’afficher le retour ; une valeur externe ou inattendue est ignorée.

Le port Forms est volontairement non bloquant. L’échec de sa projection ne fait pas échouer le stockage canonique de la soumission. Le read model ne possède pas de colonne de payload.

L’adressage explicite emploie un token HMAC contenant version, site, clé du formulaire, type/id de relation et expiration. Un appelant ne doit fournir `verified_email` que si la vérification a eu lieu en amont. Les valeurs simplement saisies par un visiteur ne sont jamais fiables par défaut.

Pour reconstruire from scratch, appliquer `database/modules/business.sql`, puis rejouer les événements Sale et les métadonnées Forms disponibles. Cette reconstruction ne dépend d’aucune migration. Pour une instance Business déjà installée, `database/migrations/business/0013_relation_360.sql` crée idempotemment les nouvelles tables et protections.
