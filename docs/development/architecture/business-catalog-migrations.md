---
title: Migrations Business Catalogue
audience:
  - developer
  - administrator
status: draft
last_verified: 2026-06-27
source_of_truth: analysis
source_paths:
  - /home/amelo/Documents/DEV/Ecol_WebeLi/web/_CATALOGUE/06_MIGRATIONS_MODULE_BUSINESS.md
  - database/migrations/business/0003_catalog_schema.sql
  - database/migrations/business/0004_catalog_demo_seed.sql
  - database/modules/business.sql
owners:
  - business
document_type: guide
generated: false
---
# Migrations Business Catalogue

Le catalogue est installe dans la base existante `business.sqlite`. Les migrations Business ne recreent pas la base si elle existe deja et ne suppriment pas les tables CRM.

## Fichiers

- `0001_init.sql` : socle CRM Business.
- `0002_catalog_pricing.sql` : compatibilite du modele de pricing minimal introduit avant le schema canonique.
- `0003_catalog_schema.sql` : schema catalogue canonique, avec marques, categories, taxes, produits, options, variantes, prix, ajustements, reductions, stock, medias et tags.
- `0004_catalog_demo_seed.sql` : donnees de demonstration catalogue idempotentes.

Le schema natif `database/modules/business.sql` contient le resultat from scratch attendu apres application de ces migrations.

## Donnees de demonstration

`0004_catalog_demo_seed.sql` ajoute :

- marque `NOUVELLE MARQUE` ;
- categories `Services` et `Marchandises` ;
- marchandise `T-shirt Demo` ;
- options `model`, `size`, `color` ;
- valeurs `Classic`, `Premium`, `S`, `M`, `L`, `Blue`, `Black` ;
- variantes `Classic / M / Blue`, `Classic / L / Blue`, `Premium / M / Black` ;
- service `Consultation` ;
- bon cadeau `Bon cadeau simple`.

Les ajustements de variantes demonstrent :

- `Classic / L / Blue` : +2 CHF achat, +5 CHF vente ;
- `Premium / M / Black` : +15 % achat, +25 % vente.

## Commandes

Plan non mutatif :

```bash
python3 tools/cms.py migrate --module business --plan
```

Application avec sauvegarde :

```bash
python3 tools/cms.py migrate --module business --apply --backup --yes
```

Validation :

```bash
python3 tools/cms.py validate
sqlite3 storage/database/business.sqlite 'PRAGMA integrity_check;'
```

## Rollback

Le migrateur SQLite actuel ne fournit pas de migration descendante. Le rollback operationnel consiste a restaurer la sauvegarde SQLite creee par `--backup`. En developpement local, `python3 tools/cms.py rebuild` recree une base from scratch depuis `database/modules/business.sql`.

## Preuve CRM

Les migrations catalogue utilisent uniquement des tables `business_product*`, `business_catalog_discounts`, `business_stock_movements` et les tables de compatibilite `business_catalog_*` deja existantes. Elles ne contiennent pas de `DROP TABLE` et ne modifient pas les tables CRM `business_companies`, `business_contacts`, `crm_memos`, `crm_consents`, `crm_mailing_*` ou `crm_messaging_*`.
