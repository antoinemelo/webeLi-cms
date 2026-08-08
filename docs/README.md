---
title: Documentation du DEC CMS
audience:
  - editor
  - publisher
  - seo
  - administrator
  - superadministrator
  - installer
  - api-integrator
  - developer
  - evaluator
status: stable
last_verified: 2026-08-04
source_of_truth: manual
source_paths:
  - README.md
  - docs

owners:
  - core
document_type: guide
generated: false
---
# Bienvenue dans la documentation

Cette documentation vous accompagne depuis la première connexion jusqu’à l’exploitation d’une instance en production. Elle ne suppose pas de connaître l’architecture du CMS : commencez par ce que vous souhaitez accomplir, puis ouvrez les détails techniques seulement lorsqu’ils sont utiles.

## Trouver une réponse en moins d’une minute

1. Choisissez l’un des six parcours affichés en haut de l’écran.
2. Ouvrez une rubrique dans la colonne de gauche ou recherchez des mots ordinaires, par exemple « publier », « sauvegarder » ou « droits d’accès ».
3. Dans une page longue, utilisez le sommaire **Sur cette page**. Les liens **Page précédente** et **Page suivante** permettent de poursuivre le même parcours.

Le filtre de profil raccourcit la liste sans masquer définitivement de contenu. Si vous hésitez, laissez **Tous** sélectionné.

## Je veux…

| Votre objectif | Commencez ici |
|---|---|
| Prendre mes repères ou choisir le bon guide | [Choisir son parcours](getting-started/choose-your-path.md) |
| Créer une page, utiliser des médias ou publier | [Utiliser le CMS](user-guide/README.md) |
| Gérer des produits, des clients ou des ventes | [Catalogue, clients et ventes](business/README.md) |
| Configurer un site, une langue, un utilisateur ou un module | [Configurer et administrer](administration/README.md) |
| Installer, sauvegarder, mettre à jour ou dépanner | [Exploiter une instance](operations/README.md) |
| Connecter une application au CMS | [Comprendre les API](api/README.md) |
| Modifier le code ou créer une extension | [Développer et étendre](development/README.md) |
| Vérifier une capacité ou une limite connue | [Capacités, limites et preuves](evaluation/README.md) |

## Les six parcours

### Démarrer et utiliser

Pour les tâches quotidiennes : connexion, contexte actif, contenus, médias, menus, publication et référencement. Les guides décrivent le résultat attendu, les étapes et les contrôles à effectuer. Ouvrez le [guide utilisateur](user-guide/README.md).

### Gérer l’activité

Pour le catalogue, les variantes, les stocks, les relations clients, les offres et les commandes. La page [Catalogue, clients et ventes](business/README.md) relie les procédures qui étaient auparavant dispersées.

### Configurer le CMS

Pour les réglages qui affectent plusieurs utilisateurs : sites, langues, rôles, permissions, modèles de contenu, modules et intégrations. Commencez par [Configurer et administrer](administration/README.md) avant de modifier un réglage sensible.

### Installer et exploiter

Pour préparer un serveur, installer une release, sauvegarder les données, appliquer une mise à jour ou résoudre un incident. Les procédures signalent les prérequis, les risques et la manière de revenir en arrière. Ouvrez [Exploitation](operations/README.md).

### Intégrer et développer

Pour les API, l’architecture, les extensions, les tests et les contrats. Les explications fonctionnelles restent séparées des inventaires générés afin de ne pas transformer chaque guide en liste de code.

### Vérifier et auditer

Pour confronter une fonction annoncée à une preuve, consulter les limites connues ou reproduire un contrôle. Cet espace est détaillé par nature, mais son [point d’entrée](evaluation/README.md) explique comment lire les matrices et les résultats.

## Comment lire un guide

Les procédures utilisent autant que possible la même progression :

1. **Résultat attendu** — ce que vous devez obtenir.
2. **Avant de commencer** — droits, contexte, sauvegarde ou données nécessaires.
3. **Étapes** — actions dans l’ordre, avec le nom réel des menus.
4. **Vérification** — contrôle visible qui confirme la réussite.
5. **En cas de problème** — causes probables, précautions et page de dépannage.

Les menus peuvent varier selon les modules activés, le site courant et vos autorisations. Une action absente n’indique donc pas forcément une erreur : vérifiez d’abord le contexte affiché dans le back-office et votre rôle.

## Quelques mots utiles

- Un **site** est une vitrine ou un espace public géré par l’installation.
- Une **langue** appartient à un site et possède ses propres contenus et URLs.
- Un **contenu** est une donnée structurée par un blueprint ; une page est un type de contenu.
- Une **révision** est une version enregistrée, distincte de la version actuellement publiée.
- Un **module** ajoute un domaine fonctionnel et peut être activé indépendamment.
- Le **contexte actif** indique le site et, selon l’écran, la langue sur lesquels vous travaillez.

## Pour les responsables techniques et documentaires

Les pages de référence JSON, YAML, HTML source et texte sont consultables dans les parcours techniques. Les routes, permissions, schémas, validateurs et inventaires dérivés du code sont générés sous [`reference/generated/`](reference/generated/README.md). L’[OpenAPI publique](public-api/openapi.v1.yaml), les [contrats administratifs](reference/contracts/) et les [limites connues](evaluation/limitations.md) restent les sources de vérification détaillées.

Une procédure fonctionnelle n’est décrite qu’une fois : les autres pages la relient afin d’éviter des instructions contradictoires. Les informations techniques de chaque page sont repliées dans le lecteur pour ne pas interrompre la lecture.

## Vérifier la documentation après une modification

```bash
python3 tools/cms.py docs generate
python3 tools/cms.py docs check
```

La première commande régénère les inventaires issus du code. La seconde contrôle leur fraîcheur, les liens locaux, les métadonnées, la navigation, les commandes citées et les garde-fous du lecteur. Ce contrôle complète la relecture humaine ; il ne juge pas à lui seul la clarté d’une explication.
