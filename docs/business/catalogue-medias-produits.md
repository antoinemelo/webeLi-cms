---
title: Medias produits Catalogue+ Opérations
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
  - backend/src/Modules/Business/Services/BusinessProductAssetService.php
owners:
  - business
document_type: guide
generated: false
---
# Medias produits Catalogue+ Opérations

Les medias produits indiquent quelles images ou fichiers du CMS sont utilises pour un produit, une variante ou un canal. Le fichier reste gere dans la mediatheque du CMS ; Catalogue+ decrit seulement son usage commercial.

## Ajouter une image principale

1. Ouvrez **Modules > Opérations > Produits**.
2. Ouvrez le produit concerne.
3. Choisissez l'action **Medias**.
4. Ajoutez un media existant depuis la mediatheque.
5. Donnez-lui le role **Image principale**.
6. Marquez-le public si l'image doit sortir dans le catalogue public ou l'e-commerce.

La liste des produits affiche **Image manquante** tant qu'aucune image principale exploitable n'est associee.

## Associer un media a une variante

Utilisez un media de variante lorsqu'une couleur, une taille ou un modele doit avoir sa propre image.

Exemple :

- le produit porte l'image generale du t-shirt ;
- la variante bleue porte l'image bleue ;
- la variante rouge porte l'image rouge.

Le catalogue choisit d'abord l'image de la variante, puis l'image du produit si aucune image de variante n'est disponible.

## Roles utiles

| Role | Usage |
|---|---|
| Image principale | Visuel principal d'un produit ou d'une variante. |
| Galerie | Images supplementaires visibles dans une fiche. |
| Document | Fiche technique, notice, certificat ou fichier utile. |
| Interne | Fichier reserve au back-office. |

Un media interne ne doit pas etre marque public.

## Canaux

Un media peut etre reserve a un usage particulier :

- back-office ;
- public ;
- e-commerce ;
- POS ;
- variante precise.

Si une image ne doit pas etre visible publiquement, gardez-la interne.

## Ce qui est visible publiquement

Le catalogue public peut exposer uniquement les medias marques publics et compatibles avec le canal demande. Il ne doit pas exposer :

- notes internes ;
- droits d'usage internes ;
- sources de travail ;
- fichiers marques internes ;
- medias rattaches a des produits non publics.

## Bonnes pratiques

- Utilisez une image principale par produit.
- Ajoutez des images de variante seulement quand elles aident vraiment l'achat.
- Gardez les noms de fichiers compréhensibles dans la mediatheque.
- Evitez de publier une image sans droit d'utilisation clair.
- Retirez ou archivez les medias qui ne sont plus utiles.

## Corriger un media manquant

Si la liste indique **Image manquante** :

1. ouvrez les medias du produit ;
2. ajoutez une image principale ;
3. verifiez qu'elle est publique si le produit est public ;
4. enregistrez ;
5. revenez a la liste pour verifier que le compteur de medias est a jour.
