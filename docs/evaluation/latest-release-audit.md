---
title: Dernier audit de release validé
document_type: evaluation
audience:
  - evaluator
  - administrator
  - developer
status: stable
version: 1.0
last_verified: 2026-06-20
source_of_truth: generated
source_paths:
  - storage/exports/dec_v07-e01a/dec_v07-e01a.release-evidence.json
  - tools/python/operations/deployment/d13_bind_release_evidence.py
owners:
  - core
generated: true
generator: tools/python/operations/deployment/d13_bind_release_evidence.py
---
# Dernier audit de release validé

Ce document est généré automatiquement pour une release **mineure ou majeure**. Les patchs ne déclenchent pas ce processus et conservent la qualification locale standard.

- Version : `dec_v07-e01a`
- Type : `major`
- Nom : Beta
- Audit : `PASS`
- Profil : `release`
- Date UTC : `2026-06-20T17:01:03Z`
- Archive de release : `dec_v07-e01a.zip`
- SHA-256 release : `9a95e5ff478066feae3295379beeaa126696ee75936f701fb8814607c5c44b6e`
- Archive de preuves : `dec_v07-e01a-audit-evidence.zip`
- SHA-256 preuves : `042beb360e8aabf24bcac8104c3178340fd627ea191552e10a9027ea27efd824`
- Étapes démontrées : 21

## Vérification indépendante

Depuis le dossier contenant les artefacts :

```bash
sha256sum -c dec_v07-e01a.zip.sha256
sha256sum -c dec_v07-e01a-audit-evidence.zip.sha256
```

L’archive de preuves reste séparée de l’archive exécutable. Le manifeste JSON associé permet de vérifier qu’elles appartiennent au même processus de livraison.
