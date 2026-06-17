---
title: Administrer l’expérience API headless
audience:
  - administrator
  - superadministrator
  - api-integrator
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: manual
owners:
  - documentation
  - api
document_type: guide
generated: false
---

# Administrer l’expérience API headless

## Expérience headless vendable

La page API headless vendable rassemble, dans le back-office, les informations nécessaires pour connecter un site front-end au CMS sans exposer les détails internes de l’administration. Elle complète la documentation technique de l’**API publique** avec des liens adaptés au site sélectionné, des exemples copiables et les contrôles de sécurité utiles.

Cette page ne remplace pas les contrats machine-readable. Elle aide l’administrateur à préparer l’accès, puis oriente l’intégrateur vers les références officielles.

## Configuration — page API headless

Ouvrez **Cockpit > Configuration > API**. L’onglet présente d’abord les opérations de gestion des tokens. L’aide d’intégration est placée **en bas de l’onglet**, afin que les actions opérationnelles restent prioritaires.

Pour le **site sélectionné**, la page fournit notamment :

- l’accès à `docs/public-api/index.html` ;
- le contrat JSON `docs/public-api/openapi.v1.json` ;
- le contrat YAML `docs/public-api/openapi.v1.yaml` ;
- un exemple `fetch` copiable ;
- un exemple SDK TypeScript copiable ;
- les variables utiles à la configuration d’un projet front-end.

Les URL sont calculées à partir du site actif. Par exemple, une installation dans un sous-répertoire peut produire :

```text
/mod/site_a/docs/public-api/openapi.v1.json
```

Les liens préservent aussi le base path d’installation. Une documentation publique peut donc être publiée à une adresse telle que `https://webe.li/mod/site-b/docs/public-api/index.html`.

## Ce que l’API expose

L’API publique est en lecture : **seuls les contenus publiés sont exposés**. Un brouillon, une révision de travail ou un contenu archivé ne doit pas être considéré comme disponible pour le front-end.

Les exemples couvrent les usages courants : route publique, menu, contenu et recherche. Ils servent de point de départ ; les paramètres complets restent décrits dans OpenAPI.

## Tokens Bearer

Un intégrateur utilise un token adapté aux scopes nécessaires. Lorsqu’il n’existe **aucun token actif**, créez-en un depuis l’onglet API avec les droits minimaux requis, puis transmettez le secret par un canal sûr.

Ne recopiez jamais un secret dans la documentation, un dépôt Git ou une capture d’écran. Le secret doit être stocké dans les variables d’environnement du projet consommateur.

## Variables utiles

Les exemples de projets utilisent les variables suivantes :

- `AMCMS_BASE_URL` : URL publique de l’instance ou du site ;
- `AMCMS_TOKEN` : token Bearer utilisé par le client ;
- `AMCMS_SITE` : identifiant ou code du site ;
- `AMCMS_LANG` : langue demandée.

Les exemples livrés couvrent **Next, Nuxt, Astro et vanilla** :

- `examples/headless-next/` ;
- `examples/headless-nuxt/` ;
- `examples/headless-astro/` ;
- `examples/headless-vanilla/`.

## Webhooks

L’onglet **Webhooks** permet de gérer les notifications envoyées à un système externe lors des événements pris en charge par le CMS. Comme pour l’API, la documentation d’aide se trouve en bas de l’onglet, après les opérations de création et de suivi.

Avant d’activer un endpoint :

1. vérifiez son URL et son protocole HTTPS ;
2. limitez les événements aux besoins réels ;
3. testez la réception ;
4. contrôlez les tentatives et les erreurs ;
5. désactivez le webhook s’il n’est plus utilisé.

## Relations et CORS

CORS reste dans **Configuration > Relations**. Le réglage est associé au site courant et détermine quels domaines front-end peuvent appeler l’API depuis un navigateur.

Lorsque l’intégration fonctionne côté serveur mais échoue dans le navigateur, commencez par vérifier :

- l’origine exacte du front-end ;
- le protocole et le port ;
- le site actuellement sélectionné ;
- la présence de l’origine dans la configuration CORS ;
- l’absence d’espace ou de barre oblique inutile dans la valeur enregistrée.

Une origine trop large augmente inutilement la surface d’exposition. N’autorisez que les domaines réellement exploités.

## Contrôle avant remise à l’intégrateur

Avant de transmettre les accès :

1. sélectionnez le bon site ;
2. ouvrez la documentation publique et les deux contrats OpenAPI ;
3. vérifiez que les URL conservent le sous-répertoire d’installation ;
4. créez un token aux scopes minimaux ;
5. testez un appel simple avec l’exemple `fetch` ;
6. vérifiez CORS dans **Configuration > Relations** ;
7. confirmez qu’un contenu non publié n’est pas retourné ;
8. transmettez séparément l’URL, le token et les variables utiles.

## Résultat attendu

L’intégrateur dispose d’une URL publique cohérente avec le site actif, d’un contrat OpenAPI lisible par les outils, d’exemples de démarrage et d’un token limité. L’administrateur conserve les réglages sensibles dans les espaces appropriés : API et Webhooks dans leurs onglets, CORS dans Relations.
