---
title: Specification fonctionnelle Business Catalogue
audience:
  - developer
  - administrator
status: draft
last_verified: 2026-06-27
source_of_truth: analysis
source_paths:
  - /home/amelo/Documents/DEV/Ecol_WebeLi/web/_CATALOGUE/03_SPEC_FONCTIONNELLE_CATALOGUE.md
  - backend/src/Modules/Business/Catalog/BusinessCatalogDefinitions.php
  - backend/src/Modules/Business/Catalog/BusinessCatalogValidator.php
  - backend/src/Modules/Business/Catalog/CatalogPricingService.php
  - backend/src/Modules/Business/Catalog/BusinessCatalogDemoData.php
owners:
  - business
document_type: specification
generated: false
---
# Specification fonctionnelle Business Catalogue

Cette specification fixe le perimetre fonctionnel v1 du catalogue produits/services dans le module `business`. Le catalogue prolonge `business.sqlite` sans creer de base separee et sans transformer le CMS en ERP, PIM enterprise ou moteur promotionnel complexe.

## Principes

- La variante est l'objet vendable.
- Les options sont generiques : aucune colonne fixe `taille`, `couleur`, `modele`, `duree` ou `formule`.
- Les prix d'achat et de vente sont separes.
- Les ajustements de variante peuvent etre exprimes en montant, pourcentage ou prix fixe.
- Les offres s'appliquent uniquement au prix de vente calcule, jamais au prix d'achat.
- Les APIs publiques ne doivent jamais exposer le prix d'achat ni ses ajustements.
- Les modules futurs commandes/factures copieront les prix historiques au moment de la vente ou de la facturation.

## Entites v1

### Marques

Une marque regroupe des produits ou services sous une identite commerciale. Elle peut etre liee a une entreprise CRM lorsque la marque appartient a une organisation suivie dans le module Business.

Champs fonctionnels :

- nom, slug, description ;
- site web ;
- logo ou media optionnel ;
- statut actif/archive ;
- visibilite publique optionnelle ;
- rattachement `site_id`.

### Categories

Les categories sont hierarchiques simples, par site.

Champs fonctionnels :

- nom, slug, description ;
- parent optionnel ;
- ordre d'affichage ;
- visibilite publique.

### Produits et services

La fiche produit est une fiche mere. Elle peut etre vendable directement seulement si une variante par defaut existe ou si les prochains prompts choisissent explicitement ce raccourci.

Types autorises :

- `physical` : produit physique ;
- `service` : prestation ;
- `gift_card` : bon cadeau simple.

Statuts autorises :

- `draft` ;
- `active` ;
- `archived`.

Canaux autorises :

- `public` ;
- `ecommerce` ;
- `pos` ;
- `internal`.

Champs fonctionnels :

- nom, slug, type, statut ;
- marque et categorie optionnelles ;
- descriptions courte et longue ;
- canaux ;
- unite ;
- classe fiscale ;
- stock active ou non ;
- prix de base achat et vente ;
- devise ;
- medias et tags.

### Options et valeurs

Les options modelisent les dimensions configurables sans colonnes specialisees.

Exemples : modele, taille, couleur, duree, formule, niveau, version, matiere.

Modele logique attendu :

- option : nom, cle, ordre, obligatoire ou non ;
- valeur d'option : libelle, cle, ordre ;
- liaison variante/valeur d'option.

### Variantes

Une variante correspond a une combinaison de valeurs d'options. C'est l'objet vendu par le POS, l'e-commerce et les futurs modules commandes/factures.

Champs fonctionnels :

- SKU ;
- code-barres optionnel ;
- statut ;
- valeurs d'options ;
- ajustement achat optionnel ;
- ajustement vente optionnel ;
- stock simple ;
- media specifique optionnel.

## Prix

### Base produit

Un produit peut definir :

- prix d'achat de base ;
- prix de vente de base ;
- devise ;
- classe fiscale.

### Ajustements variante

Types autorises :

- `none` : reprendre le prix de base ;
- `amount_delta` : ajouter ou retirer un montant ;
- `percent_delta` : ajouter ou retirer un pourcentage ;
- `fixed_override` : forcer un prix absolu.

En base SQL, l'absence de ligne d'ajustement represente `none`. Les ajustements achat et vente sont separes. Une variante peut donc augmenter le cout d'achat sans changer le prix de vente, ou inversement.

La formule canonique est detaillee dans [Modele prix et variantes](business-catalog-pricing.md).

## Offres simples

Types autorises :

- `percent` ;
- `amount`.

Portee v1 :

- produit ;
- variante ;
- categorie ;
- marque.

Canaux v1 :

- `all` ;
- `ecommerce` ;
- `pos`.

Une offre ne modifie que le prix de vente calcule. Elle ne modifie jamais le prix d'achat, ni les prix historiques futurs.

## Validations preparees

Les constantes et validations pures sont preparees dans :

- `BusinessCatalogDefinitions` ;
- `BusinessCatalogValidator` ;
- `BusinessCatalogDemoData`.

Ces classes servent de source metier pour le schema, les repositories, API et tests.

Regles deja verrouillees :

- types, statuts, canaux, devises et classes fiscales limites aux valeurs canoniques ;
- produit actif sur canal e-commerce impossible sans prix de vente ;
- schema catalogue canonique dans `business.sqlite` avec marques, categories, produits, options, variantes, prix de base, ajustements, reductions, taxes, stock, medias et tags ;
- ajustement `percent_delta` borne ;
- ajustement `fixed_override` non negatif ;
- offre en pourcentage limitee a `0 < value <= 100` ;
- payload public nettoye des prix d'achat et ajustements achat.

## Jeu de demonstration minimal

La fixture `BusinessCatalogDemoData::minimal()` decrit :

- une marque `Demo Outdoor` ;
- une categorie `Experiences` ;
- un service `Vol decouverte` ;
- deux options generiques : `Formule` et `Duree` ;
- deux variantes vendables ;
- une offre POS de lancement sur prix de vente.

Cette fixture ne doit pas etre consideree comme seed SQL tant que le schema catalogue n'est pas cree.

## Hors perimetre v1

- promotions conditionnelles complexes ;
- coupons avances ;
- bundles complexes ;
- abonnements ;
- multi-entrepots avance ;
- synchronisation fournisseurs ;
- marketplace ;
- fiscalite multi-pays avancee ;
- exposition publique du prix d'achat.
