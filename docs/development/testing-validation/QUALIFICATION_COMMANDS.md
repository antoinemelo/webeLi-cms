---
title: Commandes de qualification
audience:
  - developers
  - operators
  - release-managers
status: current
source_of_truth: code
source_paths:
  - tools/python/qualification/run_all.py
  - tools/python/commands/qualify.py
  - tools/admin.py
owners:
  - core-team
document_type: guide
---
# Commandes de qualification

La commande canonique pour une vérification approfondie est :

```bash
python3 tools/cms.py qualify --profile complete
```

Trois profils seulement sont proposés. **Aucun profil de qualification ne reconstruit les bases de données.** La reconstruction est une opération destructive et indépendante, accessible uniquement par `python3 tools/cms.py rebuild` ou par le point 1 du menu d’administration.

## Profils

| Profil | Usage | Contenu principal |
|---|---|---|
| `quick` | boucle locale courte | environnement, syntaxe Python et validateurs structurels rapides |
| `complete` | avant intégration ou après une modification importante | lint Python/PHP, tests, validateurs complets, intégrité runtime, sauvegarde/restauration, build frontend, documentation et export statique à blanc |
| `release` | avant livraison ou déploiement | profil complet, tests Playwright, préflight de production, création du package, vérification de l’archive et installation neuve |

Le profil `complete` ne crée pas de package de release. Le profil `release` exige `E2E_BASE_URL`, `E2E_ADMIN_EMAIL` et `E2E_ADMIN_PASSWORD`. Si ces variables sont absentes, les tests Playwright sont marqués `skipped`, la qualification devient incomplète et la commande retourne le code `2` ; ce code ne représente jamais un succès.

## Reconstruction des bases

La reconstruction ne fait partie d’aucun validateur ni d’aucun profil de qualification :

```bash
python3 tools/cms.py rebuild
```

Elle doit être déclenchée explicitement, après vérification de la cible et sauvegarde si nécessaire.

## Codes de retour

- `0` : toutes les étapes requises ont réussi ;
- `1` : au moins une étape a échoué ;
- `2` : qualification incomplète, car une étape requise n'a pas pu être exécutée ;
- `3` : erreur interne de l'orchestrateur.

## Rapports

Par défaut, la commande écrit :

- `storage/qualification/latest.json` ;
- `storage/qualification/latest.md`.

Les chemins peuvent être remplacés avec `--json-report` et `--markdown-report`. `--continue-on-failure` permet de collecter toutes les erreurs dans une seule exécution sans modifier le code de retour final.

## Compatibilité

`tools/python/operations/deployment/d12_quality_gate.py` reste temporairement disponible, mais délègue au profil `release`. Les procédures et workflows nouveaux ne doivent plus appeler ce fichier directement.
