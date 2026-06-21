---
title: Dernier audit de release validé
document_type: evaluation
audience:
  - evaluator
  - administrator
  - developer
status: stable
version: 1.0
last_verified: 2026-06-21
source_of_truth: generated
source_paths:
  - storage/exports/dec_v07-e02a/dec_v07-e02a.release-evidence.json
  - tools/python/operations/deployment/d13_bind_release_evidence.py
owners:
  - core
generated: true
generator: tools/python/operations/deployment/d13_bind_release_evidence.py
---
# Dernier audit de release validé

Ce document est généré automatiquement pour une release **mineure ou majeure**. Les patchs ne déclenchent pas ce processus et conservent la qualification locale standard.

- Version : `dec_v07-e02a`
- Type : `minor`
- Nom : Beta
- Audit : `PASS`
- Profil : `release`
- Date UTC : `2026-06-21T21:44:00Z`
- Archive de release : `dec_v07-e02a.zip`
- SHA-256 release : `78ab845729d21a072209f03e963caf4f511348d16c4ff4f27ca1b4bd414c4b7f`
- Archive de preuves : `dec_v07-e02a-audit-evidence.zip`
- SHA-256 preuves : `48d50d83ba9902439a851d3a6f3673d990be44f45f3dab20ee91d18562ca38b9`
- Étapes démontrées : 21

## Vérification indépendante

Depuis le dossier contenant les artefacts :

```bash
sha256sum -c dec_v07-e02a.zip.sha256
sha256sum -c dec_v07-e02a-audit-evidence.zip.sha256
```

L’archive de preuves reste séparée de l’archive exécutable. Le manifeste JSON associé permet de vérifier qu’elles appartiennent au même processus de livraison.
