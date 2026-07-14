---
title: Activités CRM alimentées par événements
audience:
  - developer
  - administrator
  - operator
status: stable
last_verified: 2026-07-14
source_of_truth: code
source_paths:
  - backend/src/Modules/Business/Contracts/CrmActivityV2.php
  - backend/src/Modules/Business/Services/SaleCrmActivityProjectionService.php
  - database/modules/business.sql
  - database/modules/sale.sql
owners:
  - business
  - sale
document_type: architecture
generated: false
---
# Activités CRM alimentées par événements

La chronologie commerciale est un read model CRM reconstruit depuis `sale_events` et `sale_outbox`. Elle ne scrute pas les commandes, paiements, retours ou paniers. Une indisponibilité CRM incrémente l’échec du consommateur sans interrompre la transaction Vente ; l’événement reste disponible pour relecture.

## Contrat

`CrmActivityV2` publie le contrat `crm.activity.v2` : type, date, site, canal et `channel_id`, contact/organisation facultatifs, source stable, résumé métier, provenance et métadonnées strictement autorisées. Le CRM ne conserve ni panier complet, ni adresse, ni lignes ou payload produit. L’unicité `(source_type, source_id, contract_version)` rend les relectures idempotentes.

Les événements réellement projetés couvrent paniers abandonnés éligibles, commandes, captures/échecs de paiement, annulations, retours, remboursements, bons cadeaux, ventes POS, livraisons et créations de comptes clients. Un paiement reçu avant l’identité reste `pending`. L’arrivée ultérieure de la commande le rattache par corrélation d’événements, sans relire ni modifier le snapshot Sale. Le rattachement manuel reste motivé et audité.

## Panier abandonné

L’expiration ne publie une activité que si la durée minimale est atteinte, qu’une identité interne suffisante existe et qu’une base licite est fournie. La production actuelle utilise le consentement explicite, fixe une rétention de 90 jours et ne déclenche aucune communication. Le contrat refuse une rétention supérieure à 180 jours. Le consentement à l’activité ne vaut jamais consentement à une campagne marketing.

## Exploitation

- `POST /admin/api/business/sale-activities/reconcile` avec `repair: true` répare les sources manquantes.
- Le même endpoint avec `mode: rebuild` rejoue toutes les sources sans effacer les rattachements manuels.
- `GET /admin/api/business/sale-activities/unlinked` expose la file d’événements en attente.
- `POST /admin/api/business/sale-activities/{id}/link` réalise un rattachement tardif audité.

La fiche CRM groupe les événements par interaction, propose recherche, filtres de type et canal, pagination, liens selon permissions et détails techniques secondaires. Vente affiche le retour vers la relation CRM lorsque les références et permissions le permettent.

Les schémas `business.sql` et `sale.sql` sont canoniques et reconstruits from scratch. Aucune migration n’est requise ni planifiée.
