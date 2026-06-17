---
title: Contrôles reproductibles
document_type: evaluation
audience:
  - evaluator
  - ai-evaluator
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: procedure
source_paths:
  - tools/python/qualification/run_all.py
  - tools/python/validation
  - docs/evaluation/machine-readable

generated: false
owners:
  - core
evidence_scope:
  - code
  - tests
  - validators
  - documentation
---

# Contrôles reproductibles

Exécuter depuis la racine du dépôt, dans une copie de travail. Installer les dépendances requises et sauvegarder toute donnée utile.

| Contrôle | Commande exacte | Produit / attendu | Interprétation d’un échec |
|---|---|---|---|
| aide CLI | `python3 tools/cms.py --help` | liste des commandes stables | façade ou Python indisponible |
| inventaire git | `git status --short` | état local explicite | dépôt non Git possible |
| reconstruction | `python3 tools/cms.py rebuild` | bases et seeds reconstruits | schéma, droits ou dépendance en défaut |
| premier administrateur | suivre `docs/installation/` et la sortie de rebuild | compte initial utilisable | ne pas inventer d’identifiants |
| tests | `python3 tools/cms.py test` | code retour 0 | lire le premier test en échec |
| validateurs | `python3 tools/cms.py validate` | code retour 0 | corriger, ne pas ignorer en release |
| documentation | `python3 tools/cms.py docs generate && python3 tools/cms.py docs check` | références fraîches | divergence source/référence |
| évaluation | `python3 tools/cms.py docs evaluation-generate && python3 tools/cms.py docs evaluation-check` | JSON et preuves cohérents | preuve manquante ou statut invalide |
| build admin | `cd frontend/admin-vue && npm ci && npm run build` | assets compilés | version Node ou TypeScript |
| sauvegarde | `python3 tools/cms.py backup --output storage/backups/evaluation.zip` | archive créée | droits/espace/base absente |
| restauration | `python3 tools/cms.py backup --restore storage/backups/evaluation.zip --yes` | bases restaurées | tester uniquement sur copie |
| export statique | `python3 tools/cms.py export` | sortie statique selon config | données ou destination incorrectes |
| audit release | `python3 tools/cms.py audit --profile release --build` | archive de preuves, résumé et journaux | environnement ou contrôle en défaut |
| release mineure/majeure | `python3 tools/cms.py release --interactive-prepare` | archive vérifiée, preuves liées, SHA-256 et manifeste | la chaîne s’interrompt avant livraison |
| patch | `make patch RELEASE_NAME="..."` | archive vérifiée sans audit reproductible automatique | qualification, préflight ou packaging en défaut |

## Scénarios manuels minimaux

Créer un contenu dans une langue, ajouter une traduction, prévisualiser, publier, vérifier l’URL publique, le canonical, les hreflang, le sitemap et l’API. Répéter avec un rôle autorisé puis interdit. Tester un média, une redirection, une recherche, un formulaire, une sauvegarde/restauration et le déploiement dans un sous-répertoire dont le chemin local contient des espaces.
