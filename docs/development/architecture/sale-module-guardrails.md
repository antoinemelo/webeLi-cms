---
title: Garde-fous du module Vente
audience:
  - developer
  - administrator
status: draft
last_verified: 2026-07-08
source_of_truth: analysis
source_paths:
  - /home/amelo/Documents/DEV/Ecol_WebeLi/web/_VENTE/00_README.md
  - /home/amelo/Documents/DEV/Ecol_WebeLi/web/_VENTE/01_SUPERPROMPT_GARDE_FOUS.md
  - backend/src/Modules/Business/BusinessModuleProvider.php
  - database/modules/business.sql
  - docs/development/architecture/business-module.md
  - docs/business/catalogue.md
owners:
  - sale
  - business
document_type: architecture
generated: false
---
# Garde-fous du module Vente

Cette page cadre le futur module **Vente** avant toute implementation. Elle fixe les limites a respecter pour ajouter un domaine transactionnel a DEC / webeLi sans transformer le CMS en suite e-commerce lourde.

## Decision structurante

| Element | Decision |
|---|---|
| Libelle utilisateur | Vente |
| Cle technique | `sale` |
| Base runtime | `storage/database/sale.sqlite` |
| Schema natif | `database/modules/sale.sql` |
| Provider cible | `App\Modules\Sale\SaleModuleProvider` |
| UI admin cible | `/admin/app/sale` |
| API admin cible | `/admin/api/sale/...` |
| Dependances metier | module `business` / Opérations |

Le module `business` reste le proprietaire du CRM Opérations et du catalogue. Vente ne doit pas fusionner ses tables dans `business.sqlite`.

## Perimetre v1

La v1 doit rester simple et robuste :

- canaux de vente ;
- paniers ;
- commandes `draft` puis `placed` ;
- POS simple ;
- paiements manuels, cash ou terminal externe ;
- recu HTML imprimable ;
- reservations et mouvements de stock transactionnels ;
- evenements, outbox et idempotence.

Hors v1 :

- marketplace ;
- abonnements ;
- multi-entrepots avance ;
- moteur fiscal international ;
- shipping avance ;
- paiement online complet ;
- promotions complexes type suite e-commerce enterprise.

## Frontiere avec Opérations

Vente consomme les donnees Opérations, mais ne les duplique pas comme source canonique :

- relations CRM, entreprises et contacts ;
- produits ;
- variantes ;
- prix catalogue ;
- remises catalogue simples.

Les commandes validees doivent conserver leurs propres snapshots. Une commande placee ne doit jamais etre recalculee depuis un produit ou un prix modifie apres coup.

## Garde-fous absolus

1. Ne pas creer de tables Vente dans `business.sqlite`.
2. Ne pas dupliquer le catalogue dans `sale.sqlite`.
3. Acceder aux produits, variantes et prix via des services de snapshot, pas par des lectures SQL dispersees.
4. Stocker les montants en unites mineures (`*_minor`), jamais en `REAL`.
5. Separer panier, commande, paiement, remboursement, reservation et mouvement de stock.
6. Prevoir l'idempotence pour les operations critiques : checkout, paiement, remboursement, retour, mouvement de stock.
7. Conserver le `rebuild from scratch` depuis schemas, migrations et seeds.
8. Ne pas ouvrir d'API publique e-commerce sans activation explicite par canal.
9. Ne pas exposer de donnees CRM ou paiement sensibles dans une API publique.
10. Garder les permissions, routes, contrats, docs et validateurs alignes avec le systeme de modules existant.

## Sites et canaux

Un site ou sous-site reste un contexte de contenu : domaine, langue, SEO, pages et rendu public.

Un canal de vente suit le [contrat transversal SalesChannel](sales-channel-contract.md) et constitue un contexte commercial :

- type canonique : storefront, POS, admin ou partenaire ;
- site rattache ;
- devise ;
- taxes ;
- caisse ou point de vente ;
- activation publique eventuelle ;
- regles de disponibilite.

Cette separation evite de confondre publication de contenu et vente transactionnelle.

## Phasage cible

1. Socle module : manifeste, provider, base `sale.sqlite`, canaux, paniers, commandes draft/placed, snapshots, evenements, idempotence.
2. POS simple : caisses, sessions, panier caisse, paiement cash/manual, recu imprimable.
3. Paiements : transactions separees, annulations, remboursements simples et statuts solides.
4. Stock transactionnel : reservations, mouvements, retours.
5. E-commerce public : endpoints publics activables par canal, jamais ouverts par defaut.

## Invariants metier

- Un panier est modifiable ; une commande placee est un enregistrement transactionnel.
- Les lignes de commande conservent nom, SKU, prix, taxe, remise et devise au moment de la vente.
- Un POS doit pouvoir vendre sans client CRM.
- Un paiement ne doit pas etre confondu avec une commande.
- Un remboursement ne doit pas supprimer le paiement original.
- Un mouvement de stock doit expliquer sa cause : reservation, vente, annulation, retour ou correction.
- Un endpoint critique doit accepter une cle d'idempotence ou un mecanisme equivalent.

## Validation minimale

Selon les fichiers touches dans les prompts suivants :

```bash
php -l <fichiers PHP modifies>
python3 tools/cms.py validate
python3 tools/cms.py docs generate
python3 tools/cms.py docs check
python3 tools/cms.py smoke
python3 tools/cms.py test --timeout 400 --target-duration 200
```

La suite complete n'est obligatoire que lorsque l'environnement source est disponible et que les changements touchent le backend, les routes, les migrations, les tests ou l'UI.

## Risques a surveiller

- Transformer Vente en second catalogue au lieu de consommer Opérations.
- Recalculer des commandes historiques depuis des prix courants.
- Ouvrir trop tot une API publique e-commerce.
- Melanger retours, remboursements et annulations.
- Introduire des montants flottants.
- Ajouter des migrations avant que le modele de domaine soit stabilise.
