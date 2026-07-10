---
title: Qualite et vendabilite Catalogue+ Opérations
audience:
  - administrator
  - superadministrator
  - publisher
status: draft
last_verified: 2026-07-09
source_of_truth: manual
source_paths:
  - frontend/admin-vue/src/views/modules/BusinessCatalogView.vue
  - backend/src/Application/Api/Admin/BusinessPimApiController.php
  - backend/src/Modules/Business/Services/BusinessProductCompletenessService.php
  - backend/src/Modules/Business/Services/BusinessCatalogSellableReadService.php
owners:
  - business
document_type: guide
generated: false
---
# Qualite et vendabilite Catalogue+ Opérations

La qualite catalogue indique si une fiche produit est complete. La vendabilite indique si une variante peut etre utilisee par un canal comme le POS ou l'e-commerce.

Un produit peut etre propre mais pas encore vendable, par exemple si le canal POS n'est pas actif. A l'inverse, un produit peut etre actif mais incomplet si une image ou un attribut requis manque.

## Lire les indicateurs

Dans la liste des produits :

- **Actif** signifie que le produit est disponible cote back-office ;
- **Brouillon** signifie qu'il est en preparation ;
- **Archive** signifie qu'il n'est plus modifiable hors restauration ou suppression ;
- **Image manquante** signale qu'aucune image principale exploitable n'est presente ;
- **Prix manquant** signale qu'aucun prix de vente exploitable n'est renseigne ;
- **Variante inactive** signale que la variante visible n'est pas vendable ;
- les tags **Public**, **E-commerce** et **POS** montrent les canaux actifs ou non.

La completude en pourcentage aide a prioriser les corrections. Elle n'est pas un feu vert commercial a elle seule.

## Ce qui rend un produit complet

La completude prend en compte les informations attendues pour la fiche produit et ses variantes :

- nom ;
- slug si le produit doit etre public ;
- SKU ;
- prix de vente ;
- image principale ;
- canaux ;
- attributs requis par les groupes selectionnes ;
- variantes et attributs de variantes lorsque le produit en depend.

Si un groupe d'attributs rend **Couleur** ou **Poids** obligatoire, le produit ne doit pas etre considere complet tant que cette information manque.

## Ce qui rend une variante vendable

Une variante est vendable lorsque les conditions utiles au canal sont remplies :

- produit actif ;
- variante active ;
- SKU present ;
- prix de vente present ;
- taxe ou classe de taxe presente si requise ;
- canal active : POS ou e-commerce ;
- visibilite publique si le canal public l'exige ;
- stock disponible si le stock est suivi et que le retour en stock n'est pas autorise.

Le snapshot vendable explique les exigences manquantes. Il sert aux futures lectures Vente, POS et e-commerce.

## Corriger un produit non vendable

1. Verifiez le statut du produit.
2. Verifiez les canaux : Public, E-commerce, POS.
3. Renseignez le schema des prix.
4. Verifiez qu'au moins une variante est active.
5. Ajoutez un SKU sur le produit et les variantes utiles.
6. Completez les attributs requis.
7. Ajoutez une image principale publique si le produit sort en ligne.
8. Verifiez le stock si le suivi de stock est actif.

Apres correction, revenez a la liste et controlez que les tags se mettent a jour.

## Stock et disponibilite

Le stock dans Opérations reste un stock catalogue simple. Il aide a savoir si une variante peut etre vendue, mais il ne remplace pas les reservations, commandes, retours ou mouvements transactionnels du module Vente.

Pour un produit physique :

- activez le suivi de stock si vous voulez bloquer la vente quand le stock est vide ;
- renseignez une quantite coherente ;
- autorisez le retour en stock seulement si le processus metier l'accepte.

## POS et e-commerce

Pour le POS :

- activez le canal POS ;
- gardez un SKU ou code-barres exploitable ;
- verifiez le prix de vente ;
- gardez au moins une variante active.

Pour l'e-commerce :

- activez Public et E-commerce ;
- ajoutez un slug ;
- ajoutez une image principale publique ;
- utilisez uniquement des attributs publics si ces attributs doivent etre visibles par les clients.

## Visible publiquement ou interne

Les clients peuvent voir les informations publiques : nom, descriptif, images publiques, attributs publics, prix de vente et disponibilite generale.

Restent internes : prix d'achat, marges, notes internes, stock exact si non expose, erreurs de completude, droits media et informations reservees au back-office.

## Avant de publier

Controlez rapidement :

1. le nom et le SKU ;
2. le prix de vente ;
3. le canal attendu ;
4. l'image principale ;
5. les variantes actives ;
6. les attributs requis ;
7. le stock si applicable ;
8. l'absence de donnees internes dans les champs publics.
