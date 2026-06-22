---
title: Sécuriser les accès API, les webhooks et le CORS
audience:
  - administrator
  - superadministrator
status: stable
version: 1.1
last_verified: 2026-06-14
source_of_truth: code
source_paths:
  - backend/src
  - frontend/admin-vue/src
  - database

owners:
  - operations
  - security
document_type: guide
source_paths:
  - backend/routes/api.php
  - backend/src/Application/Api/Admin/SecurityAdminApiController.php
  - backend/src/Application/Api/Admin/Contract/AdminApiEndpointRegistry.php
  - frontend/admin-vue/src/admin/securityPanel.ts
  - database/migrations/iam
  - `python3 tools/cms.py validate --category security`
generated: false
---
# Sécuriser les accès API, les webhooks et le CORS

Cette page décrit les réglages de **Configuration avancée** qui donnent accès au CMS depuis une application externe. Ces réglages peuvent exposer des contenus ou déclencher des appels vers un service tiers : intervenez avec un compte autorisé et notez la raison de chaque changement.

## Accéder aux réglages intégrés

Le **login admin en deux étapes** protège l’ouverture de session avant l’accès aux réglages sensibles. Une fois connecté, ouvrez `/admin/app/settings`, puis la section **Configuration avancée**. Les réglages sont regroupés dans les onglets **API** et **Webhooks** ; ils ne doivent pas être ajoutés au menu principal. Le contexte du site sélectionné s’applique aux tokens et aux webhooks, tandis que **CORS par site** reste dans **Relations**.

## Avant de commencer

Vérifiez le site actif dans le sélecteur du back-office. Les tokens et les webhooks sont enregistrés dans les tables `api_tokens` et `webhook_endpoints`. Les origines CORS sont, elles, stockées par site dans le réglage `cors_allowed_origins`.

Les permissions sont distinctes :

- `security.tokens.manage` pour les tokens d’API ;
- `security.webhooks.manage` pour les webhooks ;
- `security.cors.manage` pour les origines CORS.

Le rôle `super_admin` les reçoit dans la configuration native. Pour un autre rôle, contrôlez l’affectation réelle avant de communiquer un accès.

## Comprendre les onglets API et Webhooks

L’onglet **API** regroupe la création des **tokens Bearer** utilisés par les intégrations, ainsi que les accès vers la **documentation publique** et la spécification **OpenAPI**. Ces liens décrivent le contrat exposé ; ils ne remplacent pas la gestion des permissions ni la révocation des secrets.

L’onglet **Webhooks** sert à configurer les **webhooks de publication** envoyés lors des événements pris en charge. Les formulaires de ces deux onglets sont construits à partir de **blueprints système**. Dans l’interface, le composant `native-label-with-hint` affiche un **bouton d’information à côté du label**. Le texte d’aide provient de la propriété `help_text` des blueprints système et s’ouvre dans ce bouton, plutôt que sous le champ. Cette présentation permet de comprendre un réglage sans alourdir le formulaire.

## Créer et révoquer un token d’API

1. Ouvrez **Configuration avancée**, puis **API headless publique**.
2. Dans **Tokens API**, choisissez **Créer un token**.
3. Donnez-lui un nom qui indique son usage, par exemple `site-vitrine-production`.
4. Sélectionnez uniquement les portées nécessaires à l’intégration.
5. Validez et copiez immédiatement la valeur affichée : le secret complet n’est pas destiné à être relu ensuite.
6. Testez l’intégration avec ce token avant de l’utiliser en production.

Le CMS conserve une empreinte du token, et non sa valeur exploitable. En cas de doute, de départ d’un prestataire ou d’exposition dans un journal, révoquez le token et créez-en un autre. Ne partagez jamais un token dans un ticket, une capture d’écran ou un dépôt Git.

## Configurer un webhook

1. Ouvrez **Configuration avancée**, puis **Webhooks**.
2. Ajoutez l’URL HTTPS du service destinataire.
3. Choisissez les événements réellement utiles.
4. Enregistrez le secret de signature dans le coffre de secrets du service destinataire.
5. Activez le webhook, puis réalisez une publication de test.
6. Contrôlez la réception, la signature et le code HTTP retourné.

Les webhooks configurés apparaissent dans `webhook_endpoints`. Une URL qui échoue régulièrement doit être désactivée pendant le diagnostic afin d’éviter des tentatives inutiles. Ne contournez pas la vérification TLS pour faire accepter un certificat invalide.

## Autoriser une origine CORS

Le CORS autorise un navigateur chargé depuis une autre origine à appeler l’API publique. Il ne remplace ni l’authentification ni les permissions.

1. Sélectionnez le site concerné.
2. Ouvrez **Configuration avancée**, puis **CORS par site**.
3. Saisissez chaque origine complète, avec son schéma et son port éventuel, par exemple `https://app.example.org`.
4. Enregistrez, puis testez l’appel depuis l’application concernée.
5. Vérifiez aussi qu’une origine non autorisée reste refusée.

Évitez les autorisations trop larges. N’inscrivez pas un chemin d’URL : une origine est composée du schéma, du nom d’hôte et, si nécessaire, du port. Le réglage est enregistré dans `cors_allowed_origins` pour le site sélectionné.

## Connexion par code email

L’activation et la désactivation de la connexion par code email se font depuis **Utilisateurs**, dans la fiche du compte concerné, sous **Modifier l’utilisateur > Configuration avancée**. Ce réglage ne se trouve pas dans les onglets API ou Webhooks : il agit sur l’authentification du compte sélectionné.

Avant de modifier ce réglage, vérifiez l’adresse email du compte et assurez-vous que l’utilisateur conserve un moyen valide de se connecter. La procédure complète, y compris le contrôle des sessions et le dépannage, est décrite dans [Gérer les sessions et la connexion par code email](../users-roles-permissions/sessions-and-2fa.md).

La **connexion par code email** constitue une méthode d’authentification distincte des tokens d’API. Elle sert à ouvrir une session utilisateur dans le back-office ; elle ne doit pas être utilisée comme secret permanent pour une intégration. Vérifiez l’adresse du compte, la durée de validité du code et les journaux d’authentification lorsqu’un utilisateur ne reçoit pas son message.

## Relations avec les utilisateurs et les sites

### Relations avec les utilisateurs

Depuis la fiche d’un utilisateur, le lien `/iam/users/{id}` permet de contrôler ses rôles et sa portée. La présence d’un écran de sécurité ne suffit pas : l’action reste refusée si la permission requise n’est pas accordée au rôle actif.

### Relations avec les sites

Les routes d’administration utilisent le contexte du site. Un token ou un réglage CORS créé pour un site ne doit pas être présenté comme global. Avant toute modification, vérifiez le site affiché dans le back-office et consignez-le dans le compte rendu d’intervention.

## Supprimer un token ou un webhook

La suppression est une opération sensible et limitée au site actif. Avant de confirmer, vérifiez le **`site_id` sélectionné** dans le back-office : l’API refuse de supprimer un token ou un webhook qui n’appartient pas à ce site.

Les actions de suppression appellent l’API administrative avec la méthode `DELETE`. La requête transmet un corps JSON et doit donc inclure l’en-tête `Content-Type: application/json`, avec le `site_id` du contexte courant. Cette donnée permet au serveur de contrôler la portée multisite avant d’effacer l’enregistrement.

Pour supprimer un token API :

1. ouvrez **Configuration avancée > API** ;
2. vérifiez le site actif et le nom du token ;
3. choisissez **Supprimer**, puis confirmez ;
4. contrôlez que l’intégration qui utilisait ce token est arrêtée ou dispose déjà d’un remplacement.

Pour la **suppression d’un webhook** :

1. ouvrez **Configuration avancée > Webhooks** ;
2. vérifiez l’URL, les événements et le site actif ;
3. choisissez **Supprimer**, puis confirmez ;
4. contrôlez les journaux et l’application destinataire.

La suppression d’un webhook efface d’abord ses livraisons enregistrées dans `webhook_deliveries`, puis l’entrée correspondante dans `webhook_endpoints`. Ce nettoyage évite de conserver des livraisons orphelines. La suppression est définitive : recréez le webhook et son secret si l’action a été effectuée par erreur.

## Tester un webhook et conserver le diagnostic

Le bouton **Tester** envoie un ping au point de terminaison sélectionné sans quitter l’onglet **Webhooks**. Après le test, **l'historique du webhook testé reste ouvert** : l’administrateur peut ainsi relire immédiatement la tentative ajoutée, son statut HTTP et le détail du diagnostic sans devoir rouvrir la fiche.

En cas d’échec HTTPS, ouvrez le bloc **Diagnostic SSL/TLS**. Le CMS conserve la vérification du certificat et du nom d’hôte ; il ne désactive pas la validation TLS pour contourner une erreur. Lorsque le certificat ne correspond pas au domaine appelé, contrôlez notamment sa période de validité, sa chaîne de certification et les **Subject Alternative Names** déclarés. Le nom utilisé dans l’URL du webhook doit apparaître dans ces identités autorisées.

Une erreur de certificat doit être corrigée sur le service destinataire. Ne remplacez pas l’URL par une adresse non sécurisée et ne considérez pas un ping réussi comme une preuve que les futures livraisons de publication seront toutes acceptées.

## Présentation homogène des opérations

Dans les onglets **API** et **Webhooks**, les actions utilisent les composants standards du back-office : les **boutons conservent la hauteur standard** et une **typographie normale**, y compris pour les actions secondaires ou sensibles. Cette cohérence évite qu’une opération paraisse plus importante uniquement en raison de sa taille ou de son style.

Les libellés décrivent des **opérations explicites** : créer, tester, nettoyer ou supprimer. Les indicateurs d’activité regroupent les **tests, pings, tentatives** et derniers usages utiles au diagnostic. Ils servent à comprendre l’état d’un token ou d’un webhook ; ils ne remplacent ni les journaux d’audit ni la vérification du service destinataire.

## Contrôles après modification

- testez l’accès autorisé puis un accès volontairement non autorisé ;
- contrôlez les journaux d’audit ;
- vérifiez que les tokens inutilisés sont révoqués ;
- confirmez que chaque webhook répond correctement et valide sa signature ;
- limitez `cors_allowed_origins` aux origines nécessaires ;
- conservez une trace du site, de l’auteur, de la date et du motif du changement.

## En cas d’erreur

**Accès refusé** : contrôlez d’abord la permission du compte et le site actif.  
**Token refusé** : vérifiez qu’il n’a pas été révoqué et que sa portée couvre l’endpoint appelé.  
**Webhook non reçu** : contrôlez l’URL, le certificat TLS, les événements choisis et les journaux de livraison.  
**Erreur CORS dans le navigateur** : comparez l’origine exacte envoyée par le navigateur avec `cors_allowed_origins`; une différence de schéma, de domaine ou de port suffit à provoquer le refus.
