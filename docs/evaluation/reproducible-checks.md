---
title: Contrôles reproductibles
document_type: evaluation
audience:
  - evaluator
status: stable
last_verified: 2026-06-20
source_of_truth: procedure
source_paths:
  - tools/cms.py
  - tools/python/qualification/run_all.py
  - tools/python/validation
  - docs/evaluation/machine-readable
owners:
  - core
  - documentation
generated: false
evidence_scope:
  - code
  - tests
  - validators
  - documentation
---
# Contrôles reproductibles

Exécuter depuis la racine du dépôt, dans une copie de travail. Sauvegarder toute donnée utile avant les commandes destructives. Pour une archive release installée, utiliser le bloc de contrôles release ci-dessous : il ne dépend pas des tests source exclus du package.

| Contrôle | Commande exacte | Produit / attendu | Interprétation d’un échec |
|---|---|---|---|
| aide CLI | `python3 tools/cms.py --help` | liste des commandes stables | façade ou Python indisponible |
| smoke release | `python3 tools/cms.py smoke` | code retour 0 | archive ou installation incomplète |
| validateurs rapides | `python3 tools/cms.py validate` | code retour 0 | contrat local incohérent |
| documentation release | `python3 tools/cms.py docs check` | code retour 0 | documentation générée obsolète ou package incomplet |
| tests source | `python3 tools/cms.py test` | code retour 0 dans le dépôt source complet ; code 2 explicite si tests absents | lire le premier test en échec ou utiliser les contrôles release si l’on est dans une archive |
| qualification rapide | `python3 tools/cms.py qualify --profile quick` | résumé OK | blocage avant release |
| documentation générée | `python3 tools/cms.py docs check` | références fraîches | générer ou corriger le générateur |
| migrations non mutatives | `python3 tools/cms.py migrate --plan` | plan lisible, aucun fichier SQLite modifié | migrateur trop dangereux |
| module système | `python3 tools/cms.py migrate --module forms --plan` | plan du module Forms | inventaire module incohérent |
| sauvegarde | `python3 tools/cms.py backup --output storage/backups/final-check.zip` | archive avec manifeste | base absente ou droits insuffisants |
| mise à jour instance | `python3 tools/cms.py instance update --help` | aide publique disponible | façade instance incomplète |
| docs évaluation | `python3 tools/cms.py docs evaluation-check` | preuves cohérentes | preuve manquante ou statut invalide |

## Vérifications spécifiques modules clients

1. Les modules clients doivent rester sous `local/modules/`.
2. `ops/modules.local.json` doit déclarer explicitement les modules activés.
3. Les bases de modules clients doivent rester sous `storage/database/`.
4. `python3 tools/cms.py backup` doit inclure les bases déclarées par les modules activés.
5. `python3 tools/cms.py instance update --plan` ne doit jamais écraser `local/modules/` ni `ops/modules.local.json`.

## Limites volontaires

Le socle ne fournit pas de marketplace, pas de téléchargement distant de modules, pas de down migrations automatiques et pas de résolution automatique de conflits applicatifs. L’objectif est un contrôle local reproductible.
