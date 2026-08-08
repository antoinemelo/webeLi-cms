---
title: Administration
audience:
  - administrator
  - superadministrator
status: stable
last_verified: 2026-08-04
source_of_truth: manual
owners:
  - core
document_type: guide
generated: false
---
# Administration

Cet espace rassemble les réglages qui changent durablement le comportement du CMS pour plusieurs personnes. Les procédures indiquent ce que le réglage influence et comment contrôler le résultat. Les sauvegardes, déploiements et mises à jour système restent dans [Exploitation](../operations/README.md).

## Avant de modifier un réglage

1. Vérifiez l’installation, le site et la langue concernés.
2. Notez la valeur actuelle et les utilisateurs ou contenus qui en dépendent.
3. Pour un changement de structure, d’accès ou de module, prévoyez une sauvegarde et un moyen de retour arrière.
4. Effectuez le changement dans un contexte de test lorsqu’il peut modifier le rendu public ou l’accès au back-office.
5. Contrôlez le résultat avec un compte représentatif, pas seulement avec un compte superadministrateur.

## Choisir la bonne rubrique

| Vous souhaitez… | Guide | Effet principal |
|---|---|---|
| Ajouter un domaine ou une langue | [Sites, domaines et langues](sites-and-languages/manage-sites.md) | Routage public, contenus disponibles et contexte de travail |
| Inviter une personne ou limiter ses actions | [Utilisateurs, rôles et permissions](users-roles-permissions/overview.md) | Accès aux sites, menus et opérations autorisées |
| Révoquer une connexion ou imposer la 2FA | [Sessions et authentification](users-roles-permissions/sessions-and-2fa.md) | Sécurité immédiate des comptes |
| Changer la structure d’un contenu | [Blueprints](content-model/blueprints.md) | Champs attendus et validation des contenus |
| Réutiliser des groupes de champs ou blocs | [Champs, fieldsets et blocs](content-model/fields-fieldsets-blocks.md) | Cohérence du modèle éditorial |
| Activer ou désactiver une fonction | [Gestion des modules](modules/manage-modules.md) | Menus, données et intégrations disponibles |
| Importer, exporter ou produire un site statique | [Imports et exports](imports-exports/editorial-static.md) | Échanges de données et publication |
| Connecter un service externe | [Tokens, CORS et webhooks](security/tokens-cors-webhooks.md) | Accès distant et notifications |

## Sites, langues et domaines

Un site définit une présence publique ; une langue définit une version éditoriale de ce site. Avant d’ajouter un domaine, assurez-vous qu’il pointe vers la bonne instance et que le certificat HTTPS peut le couvrir. Après modification, testez l’URL publique, le changement de langue, les liens canoniques et le contexte du back-office.

## Utilisateurs, rôles et permissions

Accordez un rôle selon les tâches réelles, puis limitez si nécessaire son périmètre aux sites concernés. Un intitulé de rôle ne constitue pas une preuve suffisante : ouvrez une session de test et vérifiez les actions de lecture, création, modification, publication et administration attendues.

La [référence générée des permissions](../reference/generated/permissions.md) sert à l’audit technique. Pour décider quoi attribuer à une personne, utilisez d’abord le guide fonctionnel des rôles et portées.

## Modèle de contenu et modules

Un blueprint décrit les données ; le template décrit leur affichage. Modifier un champ peut donc affecter les formulaires existants, les validations, les imports, l’API et le rendu public. Inventoriez les contenus concernés avant de renommer ou retirer un élément.

Un module peut être activé indépendamment. Sa désactivation peut retirer des menus sans supprimer automatiquement ses données. Consultez [Gestion des modules](modules/manage-modules.md) et la documentation propre au module avant le changement. Pour Business, utilisez [Configurer le module Business](business/configuration.md).

## Intégrations et maintenance fonctionnelle

- [Maintenance et versions installées](maintenance.md) explique les informations visibles dans le back-office.
- [API headless dans le back-office](headless-api-ux.md) explique l’exposition de contenu à d’autres interfaces.
- [API administrative du catalogue Business](business/catalog-admin-api.md) s’adresse aux intégrations de gestion.
- [Administrer l’assistant IA](ai-assistant/administer.md) couvre les fournisseurs, limites et contrôles humains.

## Vérification après changement

Contrôlez au minimum : la connexion, le choix du site, l’écran modifié, une action autorisée, une action qui doit rester interdite et le rendu public concerné. Conservez la date, le responsable et la raison du changement lorsque le réglage a un impact de sécurité ou de publication.
