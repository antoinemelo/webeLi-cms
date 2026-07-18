---
title: Gate release Shop opérationnel — point 48
description: Preuve bloquante du parcours Shop, de son activation à son exploitation omnicanale.
audience:
  - evaluator
  - developer
  - administrator
status: active
last_verified: 2026-07-17
source_of_truth: code
source_paths:
  - docs/evaluation/machine-readable/shop-operational-release-48.json
  - frontend/admin-vue/tests/e2e/shop-operational-release-gate-48.spec.ts
  - tools/python/qualification/shop_operational_gate.py
owners:
  - core
  - business
  - sale
document_type: evaluation
generated: false
---

# Gate release Shop opérationnel — point 48

## Décision

Le point 48 est une gate unique de qualification et non une nouvelle implémentation Commerce. Elle étend les parcours 38a à 47 et ne peut réussir que dans `python3 tools/cms.py qualify --profile release` : reconstruction canonique des bases, build, tests Playwright, contrôles de performance, conditionnement et installation neuve restent dans la même chaîne.

Le fichier statique [`machine-readable/shop-operational-release-48.json`](machine-readable/shop-operational-release-48.json) décrit la couverture avec le statut `defined`. Il ne prouve aucune exécution. Seul `storage/qualification/shop-operational/latest.json`, généré sur l’instance jetable puis validé par le harnais, peut porter le statut `passed`.

## Préparation bloquante

Le harnais Playwright :

- reconstruit toutes les bases depuis les schémas et seeds canoniques avec `a_db_init.py --seed-skip-projections` ;
- déclare explicitement qu’aucune migration n’a été exécutée ;
- crée deux sites sur des chemins distincts (`/` et `/campus`) et deux langues actives (`fr`, `en`) ;
- crée une matrice de rôles administrateur, éditeur, opérations, finance et sans permission ;
- conserve une activation Shop sélective : le site principal est exploitable, le site secondaire reste inactif ;
- enregistre un brouillon inactif du site secondaire et vérifie que sa route Shop et son API storefront répondent `404` avant toute activation.

Les fixtures catalogue complètes sont produites et exercées par les scénarios canoniques déjà présents, notamment la gate omnicanale. Leur seule présence en base n’est pas considérée comme une preuve de fonctionnement.

## Scénarios agrégés

| Groupe | Parcours démonstratifs réutilisés |
|---|---|
| A — activation et Studio | Shop système 39, blocs Studio 43, redirection de l’ancien chemin Commerce |
| B — découverte | catalogue/facettes 40, merchandising 41, parité SSR/headless |
| C — fiche et panier | cartes/variantes/relations 42, résilience panier 44, ajout Studio |
| D — achats | invité et compte, providers, idempotence, cartes cadeaux 45, différé et POS |
| E — exploitation | logistique 46, facturation/ventes/inventaire 47, CRM, permissions avancées |

`--shop-operational-only` exécute cette sélection canonique de specs ainsi que l’agrégateur 48. Le profil `release` exécute la suite Playwright complète. L’agrégateur ne remplace ni ne duplique les scénarios métier.

## Contrôles négatifs et qualité UX

La validation exige une preuve bloquante pour chaque contrôle négatif : périmètres site, commande, métrique et document ; permissions ; produits privés/incomplets ou indisponibles ; concurrence sur la dernière unité ; identifiants expirés ; webhooks invalides, dupliqués ou désordonnés ; abus de carte cadeau ; indisponibilité d’une dépendance ; projection périmée ; immutabilité fiscale ; facture différée interdite ; API avancée interdite ; absence d’un module Commerce autonome.

Les preuves UX agrègent les contrôles mobile, tablette et bureau, clavier/focus, noms accessibles, contrastes non portés par la seule couleur, français/anglais, états chargement/vide/erreur/interdit, conservation des saisies et absence d’impasse. Les budgets de performance restent une étape bloquante séparée du même profil de release.

## Rapports et confidentialité

Le rapport runtime conserve uniquement des états, identifiants techniques non nominatifs et empreintes SHA-256. Il interdit les emails, noms de client, adresses, téléphones, secrets, jetons, numéros ou codes de carte cadeau et charges utiles de prestataire. Les traces, captures et vidéos restent dans les artefacts Playwright et ne sont pas recopiées dans le rapport.

Commandes de contrôle ciblées :

```bash
python3 tools/python/qualification/shop_operational_gate.py --static-only
python3 tools/cms.py e2e --use-built-assets --shop-operational-only
python3 tools/python/qualification/shop_operational_gate.py
```

Commande de décision release :

```bash
python3 tools/cms.py qualify --profile release
```

## Limites

- Une exécution ciblée ne démontre pas le conditionnement ni l’installation neuve ; seule la qualification `release` les couvre.
- La conformité fiscale dépend toujours des règles et de la juridiction configurées.
- Une preuve statique ou une classe présente dans le dépôt ne vaut jamais réussite runtime.
