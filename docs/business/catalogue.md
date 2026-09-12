---
title: Catalogue Opérations
audience:
  - administrator
  - superadministrator
  - publisher
status: draft
last_verified: 2026-07-17
source_of_truth: manual
source_paths:
  - frontend/admin-vue/src/views/modules/BusinessCrmView.vue
  - frontend/admin-vue/src/views/modules/BusinessCatalogView.vue
  - backend/src/Application/Api/Admin/BusinessCatalogApiController.php
  - backend/src/Modules/Business/BusinessModuleProvider.php
owners:
  - business
document_type: guide
generated: false
---
# Catalogue Opérations

Le catalogue Opérations permet de préparer un petit catalogue produits, services ou bons cadeaux depuis le back-office. Il couvre les marques, catégories, produits, variantes, prix, réductions simples, stock et import/export CSV.

La résolution des listes de prix, offres, bundles, kits et bons cadeaux est décrite dans [Pricing, offres et compositions Business](../development/architecture/business-pricing-offers-bundles.md).

Pour un parcours utilisateur court, commencez par :

- [Guide utilisateur Catalogue+](catalogue-plus-guide-utilisateur.md) ;
- [Medias produits Catalogue+](catalogue-medias-produits.md) ;
- [Qualite et vendabilite Catalogue+](catalogue-qualite-vendabilite.md).

## Accès

Ouvrez **Modules > Opérations**, puis utilisez les onglets **Produits** et **Offres**.

Les permissions principales sont :

- `business.catalog.read` pour consulter le catalogue ;
- `business.catalog.write` pour créer ou modifier marques, catégories, produits, options et variantes ;
- `business.catalog.prices.read` et `business.catalog.prices.write` pour consulter et modifier les prix ;
- `business.catalog.purchase_prices.read` pour voir les prix d'achat et marges ;
- `business.catalog.discounts.write` pour gérer les offres ;
- `business.catalog.stock.write` pour enregistrer des mouvements de stock.

## Créer les bases du catalogue

1. Créez une marque si le produit doit être regroupé sous une identité commerciale.
2. Créez une catégorie pour organiser les produits côté back-office, e-commerce ou POS.
3. Créez un produit dans l'onglet **Produits**.
4. Choisissez le type :
   - `physical` pour un article physique avec stock possible ;
   - `service` pour une prestation ;
   - `gift_card` pour un bon cadeau.
5. Renseignez le statut. Un produit `active` devient exploitable par les APIs si ses canaux sont activés.

## Prix de base

Le produit porte les prix de base :

```text
Prix d'achat de base
+ ajustement achat variante
= prix d'achat calculé

Prix de vente de base
+ ajustement vente variante
= prix de vente régulier
- réduction active
= prix de vente final
```

Les réductions ne modifient jamais le prix d'achat. Les prix d'achat ne sont affichés qu'aux utilisateurs ayant `business.catalog.purchase_prices.read`.

## Options et variantes

Les options sont génériques : taille, couleur, modèle, durée, format, etc.

1. Créez les options nécessaires.
2. Ajoutez les valeurs possibles, par exemple `size:m`, `color:blue`.
3. Associez les options au produit.
4. Créez une variante avec un SKU unique.
5. Renseignez les valeurs d'options de la variante.

Une variante représente l'objet réellement vendu par le futur e-commerce, le POS et les futures commandes/factures.

## Ajustements de prix par variante

Chaque variante peut ajuster séparément le prix d'achat et le prix de vente :

- `none` : aucun ajustement ;
- `amount_delta` : ajout ou retrait d'un montant ;
- `percent_delta` : variation en pourcentage ;
- `fixed_override` : prix fixe pour cette variante.

Exemple : un t-shirt de base vendu 30.00 CHF peut avoir une variante premium avec `sale_adjustment_type = percent_delta` et `sale_adjustment_value = 25`, soit un prix régulier de 37.50 CHF.

## Offres et réductions simples

Dans l'onglet **Offres**, créez une réduction avec :

- un type `percent` ou `amount` ;
- une portée `brand`, `category`, `product` ou `variant` ;
- un canal `all`, `ecommerce`, `pos` ou `admin` ;
- une priorité et, si nécessaire, des dates de validité.

Le moteur v1 sélectionne une offre active selon la portée et la priorité. Il ne gère pas les coupons complexes ni les règles promotionnelles avancées.

## Stock

Pour un produit physique, activez le suivi de stock et créez les variantes. Les mouvements disponibles sont `initial`, `purchase`, `sale`, `adjustment`, `return`, `reservation` et `release`.

Le stock v1 reste simple : il ne gère pas plusieurs entrepôts, emplacements, lots ou numéros de série.

## Publier pour e-commerce

Pour qu'un produit soit lisible par l'API publique catalogue :

1. mettez le produit en statut `active` ;
2. activez le canal **Public** ;
3. activez le canal **E-commerce** ;
4. vérifiez qu'au moins une variante active dispose d'un prix de vente exploitable.

Les APIs publiques ne retournent jamais le prix d'achat, les marges ou les quantités exactes de stock.

### Groupes, attributs et facettes publiques

Un produit peut être associé à plusieurs groupes d’attributs. Un attribut peut être :

- **Public**, donc visible dans la fiche produit projetée ;
- **Recherchable**, donc ajouté à l’index textuel public ;
- **Filtrable**, donc utilisable comme facette publique.

Un attribut filtrable ou recherchable doit être public. Dans la boutique, les facettes d’attribut n’apparaissent qu’après sélection d’un seul groupe produit : cela évite de mélanger des notions homonymes appartenant à des familles différentes. Les options ou attributs déjà utilisés ne peuvent pas être archivés silencieusement ; retirez d’abord leurs valeurs des produits et variantes concernés.

Après une modification de marque, catégorie, groupe, valeur, option, prix ou disponibilité, reconstruisez les projections Storefront pour publier le nouvel index.

## Activer pour POS

Pour qu'un produit soit disponible côté POS :

1. mettez le produit en statut `active` ;
2. activez le canal **POS** ;
3. ajoutez au moins une variante active ;
4. renseignez le SKU et, si utilisé, le code-barres.

L'API POS exige un token avec le scope `pos.catalog.read`.

## Importer et exporter en CSV

L'export CSV est disponible dans l'onglet **Produits**. Il produit un fichier `;` orienté tableur avec produits, variantes, prix, ajustements et stock.

Exemple minimal :

```csv
product_name;product_slug;type;status;brand;category;is_public;is_ecommerce_enabled;is_pos_enabled;base_purchase_price;base_sale_price;currency;variant_sku;variant_name;variant_options;sale_adjustment_type;sale_adjustment_value;stock_quantity
T-shirt demo;t-shirt-demo;physical;active;NOUVELLE MARQUE;Marchandises;1;1;1;12.50;25.00;CHF;TSHIRT-DEMO-M-BLUE;T-shirt M bleu;size:m|color:blue;amount_delta;5;20
```

Avant d'appliquer un import, lancez toujours la prévisualisation. Elle retourne un rapport ligne par ligne et refuse les prix invalides ou négatifs. L'import ne crée marques, catégories ou options absentes que si les options correspondantes sont activées.

Le format courant `pim.catalog.v1` inclut le site et l'identifiant externe facultatif. La prévisualisation affiche le diff avant toute écriture, l'application est atomique et les réimports identiques sont idempotents. Les exports filtrés par canal excluent les produits hors période de visibilité.

## Limites v1

- pas de moteur de promotions avancées ;
- pas de coupons complexes ;
- pas de multi-entrepôts ;
- pas de PIM enterprise ;
- pas d'abonnements ;
- pas de bundles complexes ;
- pas encore de commandes, factures ou paiement natifs.
