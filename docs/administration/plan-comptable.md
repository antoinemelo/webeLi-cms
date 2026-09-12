---
title: Plan comptable
audience:
  - administrator
  - accountant
status: stable
source_of_truth: backend/src/Modules/Accounting
owners:
  - accounting
document_type: guide
---
# Plan comptable

Le module Comptabilité conserve sa structure dans `accounting.sqlite`, séparément
des contenus, des identités et des ventes. Chaque site possède un plan comptable.

## Sens débit/crédit

Le comportement par défaut est « débit = augmentation, crédit = diminution ».
Les règles par préfixe permettent d'inverser ce comportement. Le plan suisse
initial contient les préfixes `2` et `3` en « crédit = augmentation » :

- le compte 1000 Caisse augmente au débit et diminue au crédit ;
- le compte 2000 Fournisseurs augmente au crédit et diminue au débit ;
- le compte 3000 Ventes augmente au crédit et diminue au débit.

Si plusieurs règles correspondent, le préfixe le plus long est prioritaire. Une
règle `20` peut donc surcharger la règle générale `2`. Sans règle correspondante,
le comportement par défaut du plan s'applique.

## Rubriques

Les rubriques sont également résolues par le préfixe le plus long. Un compte 1020
correspond à la fois à `1` Actifs et `10` Liquidités ; sa rubrique effective est
Liquidités. Les rubriques ne sont pas des clés étrangères des comptes : leur
ajout, modification ou retrait reclasse immédiatement le plan sans réécrire les
comptes.

## Comptes et renumérotation

Le numéro de compte est unique dans un plan et ne contient que des chiffres.
Chaque compte possède en plus un identifiant interne immuable. Une renumérotation
de 1001 vers 1002 conserve donc les soldes d'ouverture et les futures lignes de
journal.

Un compte inutilisé peut être supprimé. Dès qu'il est référencé par un solde
d'ouverture ou une écriture, son retrait l'archive afin de préserver l'historique.

## Exercices et soldes d'ouverture

Les montants sont stockés en unités mineures entières (centimes pour CHF), sans
arrondi binaire. Un montant positif représente le côté normal du compte :

- `100.00` sur 1000 Caisse est un solde débiteur ;
- `100.00` sur 2000 Fournisseurs est un solde créditeur.

Un montant négatif représente exceptionnellement un solde du côté opposé. Un
exercice clôturé n'accepte plus de modification de ses ouvertures.

## Administration

L'écran **Modules → Comptabilité** comprend :

1. les préfixes et leur sens d'augmentation ;
2. les rubriques et leurs préfixes ;
3. les comptes, numéros et libellés ;
4. les exercices et soldes d'ouverture.

Les permissions sont séparées : `accounting.read`,
`accounting.chart.manage` et `accounting.opening.manage`.
