---
title: Guide utilisateur Catalogue+ Opérations
audience:
  - administrator
  - superadministrator
  - publisher
status: draft
last_verified: 2026-07-09
source_of_truth: manual
source_paths:
  - frontend/admin-vue/src/views/modules/BusinessCatalogView.vue
  - backend/src/Application/Api/Admin/BusinessCatalogApiController.php
  - backend/src/Application/Api/Admin/BusinessPimApiController.php
  - backend/src/Modules/Business/Services/BusinessPimAdminService.php
  - backend/src/Modules/Business/Services/BusinessCatalogSellableReadService.php
owners:
  - business
document_type: guide
generated: false
---
# Guide utilisateur Catalogue+ Opérations

Catalogue+ sert a preparer les produits vendables dans **Opérations > Produits**. Il aide a garder des fiches propres pour le back-office, le POS et l'e-commerce, sans utiliser de vocabulaire technique.

## Creer un produit

1. Ouvrez **Modules > Opérations**, puis l'onglet **Produits**.
2. Cliquez sur **Nouveau produit**.
3. Renseignez le SKU, le nom, le type, le statut, la marque et la categorie.
4. Ajoutez un slug lisible si le produit doit etre visible en ligne.
5. Choisissez les canaux utiles : **Public**, **E-commerce** et/ou **POS**.
6. Enregistrez, puis completez les prix, variantes, medias et attributs depuis les actions du produit.

Le SKU est l'identifiant court du produit. Gardez-le lisible, stable et en majuscules.

## Definir les prix

Dans le schema des prix, renseignez :

- **Devise** ;
- **Prix d'achat (base HT)** ;
- **Prix de vente (base HT)**.

Le prix d'achat reste interne. Il sert aux marges, aux controles et a la valorisation, mais il ne doit pas apparaitre dans le catalogue public.

Le prix de vente est le prix de base affiche ou transmis aux canaux de vente, sauf si une variante ou une offre applique une variation.

## Ajouter des variantes

Une variante represente ce qui sera vendu : une taille, une couleur, un modele, une duree ou une combinaison de choix.

1. Ouvrez le produit.
2. Ajoutez ou modifiez une variante.
3. Renseignez son SKU, son statut et ses valeurs d'attributs.
4. Si necessaire, ouvrez **Ajustements par variante** depuis les actions pour ajouter une variation positive de prix.

Une variante inactive n'est pas vendable. Elle peut rester dans la fiche pour preparer un futur choix sans l'exposer.

## Ajouter des attributs

Les attributs decrivent le produit : couleur, poids, taille, matiere, modele, duree, etc.

1. Choisissez les groupes applicables au produit.
2. Renseignez uniquement les attributs proposes pour ces groupes.
3. Utilisez les options existantes quand elles sont proposees.
4. Pour une couleur, choisissez la valeur dans le panneau de couleur si disponible.

Les attributs requis comptent dans la completude. Un produit peut donc rester incomplet meme si son nom, son SKU et son prix sont deja renseignes.

## Preparer les canaux

Les tags de canal indiquent ou le produit peut etre utilise :

- **Public** : le produit peut etre expose hors back-office ;
- **E-commerce** : le produit peut etre lu par l'API catalogue publique si les autres conditions sont remplies ;
- **POS** : le produit peut etre lu par le catalogue POS.

Un canal gris signifie que le canal n'est pas defini ou pas actif. Ce n'est pas forcement une erreur si le produit n'est pas destine a ce canal.

## Corriger les informations manquantes

La liste affiche des indicateurs comme **Image manquante**, **Prix manquant**, **Variante inactive** ou une completude non calculee.

Pour corriger :

1. ouvrez la fiche du produit ;
2. ajoutez une image principale ;
3. verifiez le prix de vente ;
4. activez au moins une variante vendable ;
5. completez les attributs requis ;
6. recalculez ou enregistrez la fiche si la completude n'est pas a jour.

## Importer et exporter

L'import/export CSV sert aux corrections groupées. Utilisez-le pour preparer ou verifier plusieurs produits a la fois.

- Faites d'abord un export pour obtenir le format attendu.
- Modifiez le fichier dans un tableur.
- Lancez une preview d'import avant application.
- Corrigez les lignes signalees avant de valider.

N'importez pas de prix negatifs, de SKU ambigus ou de valeurs d'attributs qui ne correspondent pas aux options prevues.

## Public ou interne

Visible publiquement :

- nom, slug, descriptions publiques ;
- images publiques ;
- attributs marques publics ;
- prix de vente ;
- disponibilite exploitable sans stock exact.

Interne uniquement :

- prix d'achat ;
- marges ;
- notes internes ;
- droits d'usage media ;
- completude detaillee ;
- stock exact si le canal public ne doit pas le montrer.

## Quand un produit est pret a vendre

Un produit est pret a vendre lorsqu'il a :

- un statut actif ;
- un SKU exploitable ;
- un prix de vente ;
- au moins une variante active ;
- les canaux attendus actifs ;
- les attributs requis renseignes ;
- une image principale si le canal public ou e-commerce l'exige ;
- assez de stock si le stock est suivi et que le retour en stock n'est pas autorise.

Si l'un de ces points manque, le produit peut rester en brouillon ou actif mais non vendable selon le canal.
