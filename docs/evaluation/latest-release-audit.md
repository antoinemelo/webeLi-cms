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
  - storage/exports/dec_v06-e06a/dec_v06-e06a.release-evidence.json
  - tools/python/operations/deployment/d13_bind_release_evidence.py
owners:
  - core
generated: true
generator: tools/python/operations/deployment/d13_bind_release_evidence.py
---
# Dernier audit de release validé

Ce document est généré automatiquement pour une release **mineure ou majeure**. Les patchs ne déclenchent pas ce processus et conservent la qualification locale standard.

- Version : `dec_v06-e06a`
- Type : `minor`
- Nom : Beta
- Audit : `PASS`
- Profil : `release`
- Date UTC : `2026-06-20T13:42:16Z`
- Archive de release : `dec_v06-e06a.zip`
- SHA-256 release : `2cafb66e9670d417e996ef1130b3d911dee5b9cd59eb3f93a4b41f98a956ad39`
- Archive de preuves : `dec_v06-e06a-audit-evidence.zip`
- SHA-256 preuves : `986090736e991ee42df2c1b54821bd348720ea392c21ca09ffdf53c19c159385`
- Étapes démontrées : 21

## Vérification indépendante

Depuis le dossier contenant les artefacts :

```bash
sha256sum -c dec_v06-e06a.zip.sha256
sha256sum -c dec_v06-e06a-audit-evidence.zip.sha256
```

L’archive de preuves reste séparée de l’archive exécutable. Le manifeste JSON associé permet de vérifier qu’elles appartiennent au même processus de livraison.
