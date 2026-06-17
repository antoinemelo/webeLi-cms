---
title: Dernier audit de release validé
document_type: evaluation
audience:
  - evaluator
  - administrator
  - developer
status: stable
version: 1.0
last_verified: 2026-06-15
source_of_truth: generated
source_paths:
  - storage/exports/dec_v06-e04a/dec_v06-e04a.release-evidence.json
  - tools/python/operations/deployment/d13_bind_release_evidence.py
owners:
  - core
generated: true
generator: tools/python/operations/deployment/d13_bind_release_evidence.py
---
# Dernier audit de release validé

Ce document est généré automatiquement pour une release **mineure ou majeure**. Les patchs ne déclenchent pas ce processus et conservent la qualification locale standard.

- Version : `dec_v06-e04a`
- Type : `minor`
- Nom : Beta
- Audit : `PASS`
- Profil : `release`
- Date UTC : `2026-06-15T20:54:33Z`
- Archive de release : `dec_v06-e04a.zip`
- SHA-256 release : `efdb327a79e4b437e8a01b12231bd29155b5d72faafae93e0625ae8ae6c99c00`
- Archive de preuves : `dec_v06-e04a-audit-evidence.zip`
- SHA-256 preuves : `91618cf4ac8925541f4ad45e62d630adc30cf5b3df1586ff1ac4408e3a4c18a2`
- Étapes démontrées : 21

## Vérification indépendante

Depuis le dossier contenant les artefacts :

```bash
sha256sum -c dec_v06-e04a.zip.sha256
sha256sum -c dec_v06-e04a-audit-evidence.zip.sha256
```

L’archive de preuves reste séparée de l’archive exécutable. Le manifeste JSON associé permet de vérifier qu’elles appartiennent au même processus de livraison.
