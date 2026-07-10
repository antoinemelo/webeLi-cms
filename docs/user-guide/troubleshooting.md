---
title: Résoudre les erreurs fréquentes du back-office
audience:
  - editor
  - publisher
  - seo
status: stable
last_verified: 2026-06-14
source_of_truth: manual
source_paths:
  - backend/src
  - frontend/admin-vue/src
  - backend/routes/api.php
  - admin-app/src

owners:
  - editorial
document_type: procedure
generated: false
---
# Résoudre les erreurs fréquentes du back-office

## Résultat attendu

Résoudre les erreurs fréquentes du back-office.

## Public et droits

**Profils concernés :** editor, publication, seo, admin.  
**Permissions :** varie selon l’action.

## Prérequis

identifier le site, la langue, l’URL, l’heure et l’action exacte
## Diagnostic rapide

1. Rechargez le contexte et vérifiez site/langue.
2. Notez le message et le code HTTP.
3. Vérifiez la permission requise dans la référence générée.
4. Contrôlez les champs de validation et le statut de la révision.
5. Pour une erreur serveur, consultez les journaux avec un administrateur.

## Cas fréquents

- **401 :** session absente ou expirée.
- **403 :** permission ou portée insuffisante.
- **404 :** ressource absente dans le contexte actif.
- **409 :** conflit de route, version ou verrou.
- **422 :** données invalides.
- **429 :** limite de requêtes atteinte.

Ne contournez pas une erreur de permission en partageant un compte.
