---
title: Gérer les sites et les langues
audience:
  - administrator
  - superadministrator
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: code
source_paths:
  - backend/src
  - frontend/admin-vue/src
  - database

owners:
  - administration
document_type: guide
permissions:
  - settings.read
  - settings.manage
source_paths:
  - backend/routes/api.php
  - backend/src/Application/Api/Admin/ConfigurationApiController.php
  - backend/src/Application/Api/Admin/MultisiteApiController.php
  - backend/src/Application/Configuration/ConfigurationRepository.php
  - frontend/admin-vue/src/views/system/SystemConfigurationView.vue
generated: false
---
# Gérer les sites et les langues

Cette page explique comment régler le site courant et ses langues depuis le back-office. Ces paramètres influencent directement les URL publiques, les menus, les contenus disponibles, les balises `hreflang` et les exports. Une modification doit donc être préparée et contrôlée comme une opération de publication.

## Profils concernés

- **Administrateur** : consulte la configuration avec `settings.read` et la modifie avec `settings.manage`, dans la portée des sites qui lui sont attribués.
- **Superadministrateur** : peut intervenir sur l’ensemble des sites et sur les opérations multisites sensibles.

Le rôle seul ne suffit pas : l’accès effectif dépend aussi de la portée de l’affectation et des permissions enregistrées pour l’utilisateur.

## Avant de commencer

Réunissez les informations suivantes :

- la clé interne et le nom du site ;
- le domaine principal ;
- le chemin de base éventuel, par exemple `/association` ;
- les langues à proposer ;
- la langue par défaut ;
- le préfixe d’URL de chaque langue ;
- la langue de repli éventuelle ;
- les codes `hreflang` attendus.

Évitez de modifier domaine, chemin de base et préfixes de langue au même moment qu’une importante publication éditoriale. Ces réglages peuvent modifier plusieurs routes publiques à la fois.

## Ouvrir la configuration

1. Connectez-vous au back-office.
2. Vérifiez le **site actif** dans le sélecteur de contexte.
3. Ouvrez **Configuration**.
4. Utilisez l’onglet **Multisite** pour l’identité, le domaine et le chemin de base.
5. Utilisez l’onglet **Langues** pour les langues de contenu et la langue de l’interface d’administration.

La lecture de cet écran appelle `GET /admin/api/configuration`. L’enregistrement appelle `PATCH /admin/api/configuration`. Le serveur contrôle les permissions : masquer un bouton dans l’interface ne constitue pas une protection suffisante.

## Modifier les réglages du site courant

Dans **Configuration > Multisite** :

1. contrôlez le nom et la clé du site ;
2. vérifiez le domaine principal ;
3. renseignez un chemin de base uniquement lorsque le site est réellement publié sous un sous-répertoire ;
4. enregistrez le groupe de configuration ;
5. rechargez la page pour confirmer que le contexte est encore résolu correctement.

Un chemin de base doit commencer par `/` lorsqu’il n’est pas vide. N’ajoutez pas de slash final et n’utilisez ni espace, ni segment `.` ou `..`, ni double slash.

### Créer un sous-site

La création d’un sous-site est une opération multisite distincte, exposée par le contrôleur d’administration multisite. Elle nécessite `settings.manage` depuis le site principal.

1. Placez-vous sur le site principal.
2. Ouvrez **Configuration > Multisite**.
3. Lancez l’action de création d’un sous-site lorsqu’elle est proposée.
4. Saisissez une clé stable, un libellé, un domaine ou un chemin de base non utilisé.
5. Créez le site, puis ouvrez son contexte avant de régler ses langues et son apparence.

Ne réutilisez jamais une clé de site existante. Une clé sert de référence durable dans les exports, les scripts et les contrats d’API.

## Activer et configurer les langues

Dans **Configuration > Langues** :

1. ajoutez chaque langue de contenu nécessaire ;
2. utilisez un code court normalisé, par exemple `fr`, `de` ou `en` ;
3. indiquez la locale lorsque le format régional doit être plus précis ;
4. définissez le préfixe d’URL ;
5. renseignez le code `hreflang` ;
6. choisissez, si nécessaire, une langue de repli différente de la langue courante ;
7. marquez exactement une langue comme langue par défaut ;
8. vérifiez que la langue par défaut est active ;
9. enregistrez.

Le CMS refuse notamment :

- deux langues avec le même code ;
- deux langues avec le même préfixe d’URL ;
- plusieurs langues par défaut ;
- une langue par défaut inactive ;
- une langue de repli absente de la liste ;
- une langue qui se désigne elle-même comme langue de repli.

La langue de l’interface d’administration est indépendante de la langue du contenu. La changer ne traduit ni ne déplace les contenus.

## Contrôles après enregistrement

Effectuez ces vérifications avant de considérer l’opération terminée :

1. changez de site et de langue depuis le sélecteur du back-office ;
2. ouvrez une page existante dans chaque langue active ;
3. vérifiez les URL publiques et l’absence de double slash ;
4. contrôlez le menu dans chaque langue ;
5. prévisualisez puis publiez un contenu de test lorsque la langue vient d’être ajoutée ;
6. contrôlez les liens canoniques et `hreflang` dans le HTML public ;
7. lancez l’audit SEO du site ;
8. vérifiez la recherche et l’API publique pour le contexte concerné.

## Conséquences à connaître

- **Contenus** : une langue active n’ajoute pas automatiquement une traduction aux contenus existants.
- **Publication** : une révision ne peut être publiée que dans sa propre langue et sur une langue active du site.
- **Menus** : les libellés et destinations peuvent être localisés ; ils doivent être revus après l’ajout d’une langue.
- **SEO** : les canoniques et `hreflang` dépendent du domaine, du chemin de base et des préfixes.
- **Recherche** : l’index est séparé par site et par langue.
- **API** : le site et la langue font partie du contexte de lecture ; un contexte invalide est refusé.

## Erreurs fréquentes

### « Impossible d’enregistrer la configuration »

Vérifiez d’abord votre permission `settings.manage`, puis les erreurs affichées sous les champs. Les incohérences de langue sont validées côté serveur.

### Le site ouvre une mauvaise URL

Contrôlez le domaine primaire et le chemin de base. Une collision entre deux sites rend la résolution ambiguë. Ne créez pas une route de contenu égale au chemin de base du site.

### Une langue n’apparaît pas dans l’éditeur

Confirmez qu’elle est active sur le site courant, puis rechargez le contexte administratif. L’existence du code langue dans un autre site ne l’active pas ici.

### Une page existe mais n’est pas visible dans la nouvelle langue

La langue doit disposer d’une révision publiée et d’une route active. L’activation d’une langue ne fabrique pas de contenu ni de traduction.

### Les liens `hreflang` sont incomplets

Vérifiez le code `hreflang`, les préfixes d’URL et la présence d’une version publiée pour chaque langue concernée.

## Limites observées

La configuration permet de gérer les réglages du site courant et de créer des sous-sites depuis le site principal. Elle ne remplace pas une opération de migration de domaine : lorsqu’un domaine public change, prévoyez aussi la configuration du serveur web, les redirections, les contrôles SEO et un plan de retour arrière.

Pour les clés et contrats exacts, consultez les références générées dans `docs/reference/` plutôt que de recopier leur contenu dans une procédure.
