---
title: Utilisateurs, rôles et permissions
audience:
  - administrator
  - superadministrator
status: stable
last_verified: 2026-06-25
source_of_truth: manual
owners:
  - documentation
document_type: guide
generated: false
---

# Utilisateurs, rôles et permissions

## Modèle

Les autorisations sont contrôlées côté backend. L’interface masque également des cartes et actions, mais cette visibilité ne constitue pas la sécurité. Les affectations peuvent être globales ou contextualisées selon les structures IAM et multisite.

## Rôles globaux et accès multisite

Un rôle affecté dans `iam_user_roles` est global : ses permissions s’appliquent à tous les sites auxquels le CMS donne accès. La fonction d’autorisation conserve alors une portée globale, représentée par une liste de sites autorisés vide dans le repository.

Les affectations enregistrées dans `iam_user_site_roles` sont limitées au site associé. Elles servent à cloisonner les utilisateurs qui ne disposent d’aucun rôle global. Lorsqu’un utilisateur cumule plusieurs affectations, le backend calcule l’union des permissions globales et des permissions propres au site courant.

Conséquences pratiques :

- n’attribuez un rôle global que lorsque l’utilisateur doit réellement intervenir sur tous les sites ;
- utilisez un rôle par site pour limiter un éditeur ou un publicateur à un périmètre précis ;
- dans la fiche utilisateur du back-office, les réglages **Mode de connexion**, **Rôles globaux** et **Accès par site** se trouvent dans **Configuration avancée** afin de garder les champs courants lisibles ;
- la disparition d’une carte ou d’un bouton dans le back-office ne remplace jamais le contrôle backend ;
- vérifiez les accès avec un compte distinct après toute modification de rôle.


## Matrice des permissions de sécurité

La matrice IAM distingue la consultation d’un réglage de sa modification. Un utilisateur ne doit recevoir que les permissions nécessaires à sa mission.

| Domaine | Consulter | Modifier | Remarque |
|---|---|---|---|
| Tokens API | `security.tokens.read` | `security.tokens.manage` | La consultation permet de voir l’inventaire et l’état des tokens. La gestion autorise leur création, leur révocation et la modification de leurs scopes. |
| Webhooks | `security.webhooks.read` | `security.webhooks.manage` | La lecture donne accès aux endpoints et à leur état. La gestion permet de créer, modifier, désactiver ou relancer une configuration. |
| CORS | `security.cors.read` | `security.cors.manage` | Les origines autorisées restent liées au site courant. La modification doit être limitée aux administrateurs qui comprennent l’impact sur l’API publique. |
| Modes de connexion IAM | — | `users.email_2fa.manage` | Permission conservée par compatibilité pour modifier `login_mode` : mot de passe, code par e-mail ou mot de passe + TOTP. Le code e-mail n’est pas un TOTP. |
| Modèles de contenu | permissions `blueprints.*` | permissions `blueprints.*` | Les blueprints restent séparés des réglages de sécurité. Ils sont mentionnés ici pour rappeler qu’un rôle d’administration du contenu ne doit pas recevoir automatiquement les droits sur les tokens, webhooks ou CORS. |

Les permissions de lecture déterminent l’accès aux informations. Les permissions de gestion contrôlent les actions qui modifient l’état du système. Le back-office s’appuie sur cette distinction pour afficher ou masquer les onglets et les commandes, mais le backend reste l’autorité finale.

## Responsabilités

- **Administrateur de site** : gère les ressources de son périmètre.
- **Administrateur global** : gère plusieurs sites et les réglages transversaux autorisés.
- **Superadministrateur** : intervient sur les opérations sensibles et globales.

## Procédure sûre

1. Créez l’utilisateur.
2. Affectez le rôle minimal nécessaire.
3. Limitez la portée au site utile lorsque le modèle le permet.
4. Testez avec un compte distinct.
5. Contrôlez menu, bouton, endpoint et effet métier.
6. Retirez les droits devenus inutiles.

La liste générée des permissions se trouve dans [la référence](../../reference/generated/permissions.md). Les seeds IAM restent la source des rôles natifs; ils ne doivent pas être remplacés par une matrice rédigée manuellement.
