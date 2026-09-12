---
title: Catalogue, clients et ventes
audience:
  - editor
  - publisher
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
# Catalogue, clients et ventes

Ce parcours rassemble les fonctions du module Business qui étaient auparavant réparties entre plusieurs guides. Il suit le cycle réel de l’activité : préparer un produit, le rendre vendable, suivre un contact, enregistrer une vente puis traiter les opérations associées.

> Le module Business peut être activé indépendamment. Si ses menus ne sont pas visibles, vérifiez son activation, le site actif et les permissions du rôle avant de chercher un problème de données.

## Choisir votre point de départ

| Votre tâche | Guide principal | À consulter ensuite |
|---|---|---|
| Créer ou modifier un produit | [Guide utilisateur du catalogue](catalogue-plus-guide-utilisateur.md) | [Structure complète du catalogue](catalogue.md) |
| Comprendre pourquoi un produit n’est pas vendable | [Qualité et vendabilité](catalogue-qualite-vendabilite.md) | [Médias des produits](catalogue-medias-produits.md) |
| Ajouter images, documents ou variantes visuelles | [Médias des produits](catalogue-medias-produits.md) | [Guide utilisateur du catalogue](catalogue-plus-guide-utilisateur.md) |
| Suivre un client ou une relation | [Guide utilisateur CRM](crm-guide-utilisateur.md) | [Messagerie CRM](crm-messaging.md) |
| Configurer le CRM et ses accès | [Guide d’administration CRM](crm-guide-admin.md) | [Principes d’interface CRM](crm-ux-principles.md) |
| Créer et suivre une vente | [Guide de vente](vente.md) | [Commandes](vente-commandes.md) |
| Utiliser la caisse | [Point de vente](vente-pos.md) | [Commandes](vente-commandes.md) |
| Traiter les tâches opérationnelles | [Opérations métier](operations.md) | [Vue d’ensemble du commerce](commerce.md) |

## Comprendre les objets principaux

- Le **produit** décrit ce qui est proposé : identité, texte, médias et règles communes.
- Une **variante** représente une option réellement sélectionnable, par exemple une taille ou une couleur, avec sa disponibilité propre.
- La **vendabilité** indique si toutes les informations nécessaires à l’achat sont réunies. Un produit publié peut donc ne pas être vendable.
- Un **contact CRM** rassemble l’identité, les coordonnées, les échanges et les activités utiles à la relation.
- Une **commande** enregistre l’engagement commercial, ses lignes, ses montants et son évolution.
- Une **opération** est une action de suivi : préparation, message, paiement, remise ou autre traitement selon les modules actifs.

## Parcours recommandé : publier un produit vendable

1. Créez le produit dans le bon site et la bonne langue.
2. Complétez son identité, son classement et sa description.
3. Ajoutez ses variantes et renseignez les informations qui changent par variante.
4. Associez les médias utiles en vérifiant leur ordre et leur texte alternatif.
5. Contrôlez les prix, disponibilités et règles de livraison ou de remise à disposition.
6. Utilisez l’indicateur de vendabilité pour corriger les éléments manquants.
7. Prévisualisez la fiche et le parcours d’ajout au panier avant publication.

Le [guide utilisateur du catalogue](catalogue-plus-guide-utilisateur.md) détaille les écrans. Le guide [Qualité et vendabilité](catalogue-qualite-vendabilite.md) explique les contrôles et leurs causes.

## Parcours recommandé : suivre un client et sa vente

1. Recherchez d’abord le contact pour éviter un doublon.
2. Vérifiez ses coordonnées et les règles de communication applicables.
3. Consignez l’échange ou la tâche avec un intitulé compréhensible par une autre personne.
4. Créez l’offre ou la commande depuis le bon contexte lorsque le processus le permet.
5. Contrôlez les lignes, variantes, quantités, prix, taxes et mode de remise avant validation.
6. Suivez le statut jusqu’à la fin de l’opération et documentez les exceptions.

Ouvrez le [guide utilisateur CRM](crm-guide-utilisateur.md), puis le [guide de vente](vente.md) pour les étapes détaillées.

## Contrôles utiles

Pour chaque opération, vérifiez que le site, le client, la devise et les variantes correspondent à la transaction réelle. Une fiche publiée, une variante en stock et une commande validée représentent trois états différents : contrôlez chacun séparément.

Pour les services, contenus téléchargeables ou autres produits non physiques, la disponibilité et le mode de remise remplacent la notion de stock matériel. Le guide du catalogue précise les différences de comportement.

## Administration et intégrations

Les réglages globaux du module sont décrits dans [Configurer Business](../administration/business/configuration.md). L’[API du catalogue](catalogue-api.md) et l’[API administrative Business](../administration/business/catalog-admin-api.md) s’adressent aux intégrations ; elles ne sont pas nécessaires pour l’utilisation quotidienne.

Les documents d’audit et de conception CRM restent disponibles dans cette rubrique pour expliquer les décisions d’interface. Commencez cependant par les guides utilisateur et administrateur, plus proches des tâches réelles.
