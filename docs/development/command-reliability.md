---
title: Fiabilité des commandes opérationnelles
audience:
  - developer
  - administrator
  - evaluator
status: stable
last_verified: 2026-06-15
source_of_truth: code
source_paths:
  - tools/python/cms/runtime.py
  - tools/python/cms/evidence.py
owners:
  - core
document_type: guide
generated: false
---

# Fiabilité des commandes opérationnelles

La façade stable est `python3 tools/cms.py`. Les commandes d'automatisation doivent l'utiliser plutôt que lancer directement un script historique.

## Bornes et dépendances

Chaque sous-processus est borné par `--command-timeout` (600 secondes par défaut). Lors d'un timeout, le groupe de processus complet est terminé et la commande retourne `124`. Une dépendance absente retourne un code non nul avant toute écriture. Le binaire PHP peut être fixé avec `CMS_PHP_BINARY`.

```bash
CMS_PHP_BINARY=/chemin/php python3 tools/cms.py --command-timeout 300 validate
```

## Preuves de commande

L'option globale `--evidence-dir` crée une preuve JSON atomique et son fichier SHA-256 :

```bash
python3 tools/cms.py \
  --evidence-dir storage/qualification/command-evidence \
  --command-timeout 600 \
  validate --full
```

Chaque preuve contient la commande, la date UTC, la version, l'environnement, la durée, le code de sortie, le résumé et les checksums des artefacts explicitement collectés. Un message de succès ne remplace jamais le code de sortie.

## Règles d'automatisation

- Ne pas utiliser les commandes persistantes (`dev`, `preview`, `log:tail`) sans wrapper borné.
- Ne pas utiliser `--continue-on-failure` dans une quality gate ou une release.
- Exécuter les commandes destructives uniquement dans une copie isolée ou après sauvegarde vérifiée.
- Une preuve n'est valide que si son checksum et les checksums des fichiers embarqués sont vérifiés.
