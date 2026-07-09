---
title: Stratégie PIM-lite Opérations / Catalogue+
audience:
  - developer
  - administrator
  - evaluator
status: draft
last_verified: 2026-07-08
source_of_truth: analysis
source_paths:
  - docs/development/architecture/business-pim-lite-audit.md
  - database/modules/business.sql
  - database/modules/sale.sql
  - backend/src/Modules/Business/Services/BusinessCatalogSellableReadService.php
  - backend/src/Modules/Sale/Services/SaleCatalogSnapshotService.php
  - frontend/admin-vue/src/views/modules/BusinessCatalogView.vue
owners:
  - business
  - sale
document_type: strategy
generated: false
---
# Stratégie PIM-lite Opérations / Catalogue+

Cette stratégie fixe le cap avant d'ajouter des tables, APIs ou ecrans PIM-lite. Le developpement du module **Vente** reste temporairement en pause afin de solidifier la qualite des donnees produit dans **Opérations**.

## Principes

- Le PIM-lite vit dans le module `business`, dans `business.sqlite`.
- Il etend le catalogue existant ; il ne cree pas un nouveau module autonome.
- Il qualifie les produits avant vente ; il ne remplace pas Vente, le POS ou l'e-commerce.
- Vente continue de consommer le catalogue via snapshots stables et conserve ses transactions dans `sale.sqlite`.
- La variante reste l'objet vendable.
- Les prix d'achat restent proteges et absents des payloads publics.
- L'UI utilise du vocabulaire metier simple : produit, variante, image principale, medias, informations manquantes, pret pour la vente, visible en ligne, visible POS.
- Les termes techniques comme `snapshot`, `attribute values`, `blueprint resource` ou `renditions` restent reserves a la documentation developpeur.

## Positionnement

Le PIM-lite n'est pas :

- un DAM complet ;
- une copie d'AtroPIM, Plytix ou Crystallize ;
- un moteur de promotions avancees ;
- un moteur de vente ;
- une duplication du catalogue dans `sale.sqlite`.

Le PIM-lite est une couche native de qualite catalogue pour Opérations. Il doit aider l'utilisateur a savoir rapidement si un produit peut etre vendu en POS, en ligne ou dans un futur canal, et pourquoi il ne le peut pas encore.

## Périmètre v0.1

Le v0.1 doit rester pragmatique :

- identifier les produits incomplets ;
- identifier les variantes non vendables ;
- exposer les informations manquantes ;
- normaliser une image principale exploitable par POS/e-commerce ;
- clarifier les medias produit et variante ;
- poser une base d'attributs simples si necessaire au catalogue ;
- afficher les produits sans prix, sans image ou sans canal active ;
- conserver une importation/exportation CSV compatible avec le catalogue existant ;
- preparer une lecture machine-readable utile a l'IA et aux schemas admin.

## Structure cible dans Opérations

L'espace catalogue d'Opérations doit progressivement s'organiser en :

| Section | Objectif |
|---|---|
| Produits | Gestion produit, variantes, prix, stock simple et canaux. |
| Médias produit | Image principale, galerie, documents et medias de variante. |
| Qualité catalogue | Completude, informations manquantes, vendabilite POS/e-commerce. |
| Imports / Exports | CSV, rapports d'import, exports sans donnees sensibles. |
| Réglages catalogue | Marques, categories, options, taxes, valeurs partagees. |

Cette structure ne doit pas etre implementee d'un coup si elle augmente trop le risque. Les prochains prompts doivent la construire par increments.

## Qualité catalogue

La qualite catalogue doit repondre a des questions simples :

- le produit a-t-il un nom, un slug et un statut coherent ?
- au moins une variante active existe-t-elle ?
- une variante active a-t-elle un SKU ?
- un prix de vente existe-t-il ?
- une image principale ou un media utile existe-t-il ?
- le canal POS ou e-commerce est-il active ?
- le stock bloque-t-il la vente ?
- le produit est-il pret pour la vente ?

Les sorties attendues sont :

- `is_sellable` pour le verdict vendable ;
- `missing_requirements` pour expliquer les blocages ;
- `completeness_score` pour prioriser les corrections ;
- `main_asset` pour fournir une image principale normalisee.

## Impacts sur Vente

Vente reste proprietaire de :

- canaux de vente ;
- paniers ;
- commandes ;
- paiements ;
- POS ;
- reservations et mouvements transactionnels ;
- retours et recus ;
- snapshots de lignes au moment de la vente.

Opérations reste proprietaire de :

- produits ;
- variantes ;
- marques ;
- categories ;
- medias produit ;
- prix catalogue ;
- remises catalogue simples ;
- stock catalogue courant ;
- qualite et completude catalogue.

Le contrat entre les deux domaines doit rester `BusinessCatalogSellableReadService` puis `SaleCatalogSnapshotService`. Vente ne doit pas multiplier les lectures directes dans les tables `business_*`.

## Hors périmètre

Le PIM-lite v0.1 ne couvre pas :

- workflow editorial produit avance ;
- DAM complet avec transformations avancees ;
- marketplace ;
- multi-entrepots avance ;
- tax engine international ;
- promotions conditionnelles complexes ;
- bundles configurables complexes ; le modèle minimal d'offres composées est couvert par l'onglet Offres et reste descriptif côté catalogue ;
- abonnements ;
- moteur de recherche externe ;
- refonte du POS ;
- refonte du module Vente.

## Critères de reprise de Vente au point 16

Le module Vente peut reprendre son developpement au point 16 lorsque :

- le snapshot vendable expose un diagnostic clair de vendabilite ;
- `missing_requirements` permet d'expliquer les variantes bloquees ;
- `main_asset` ou un equivalent stable est disponible pour POS/e-commerce ;
- les prix achat restent separes et proteges ;
- les tests prouvent qu'une variante active et complete est vendable ;
- les tests prouvent qu'une variante incomplete est refusee ou signalee proprement ;
- la documentation developpeur explique la frontiere `business.sqlite` / `sale.sqlite`.

## Décision

La prochaine etape peut ajouter le schema PIM-lite minimal dans `business.sqlite`, mais uniquement pour completer le catalogue existant. Aucune table transactionnelle Vente ne doit etre ajoutee dans ce cycle PIM-lite, sauf si un prompt ulterieur l'exige explicitement et justifie la frontiere de domaine.
