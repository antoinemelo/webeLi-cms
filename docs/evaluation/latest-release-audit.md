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
  - storage/exports/dec_v06-e10a/dec_v06-e10a.release-evidence.json
  - tools/python/operations/deployment/d13_bind_release_evidence.py
owners:
  - core
generated: true
generator: tools/python/operations/deployment/d13_bind_release_evidence.py
---
# Dernier audit de release validé

Ce document est généré automatiquement pour une release **mineure ou majeure**. Les patchs ne déclenchent pas ce processus et conservent la qualification locale standard.

- Version : `dec_v06-e10a`
- Type : `minor`
- Nom : Beta
- Audit : `PASS`
- Profil : `release`
- Date UTC : `2026-06-20T16:51:07Z`
- Archive de release : `dec_v06-e10a.zip`
- SHA-256 release : `a82760539f99ccbdd3885cfc05e591386d5e57b17a1584ec836745af676a559a`
- Archive de preuves : `dec_v06-e10a-audit-evidence.zip`
- SHA-256 preuves : `e970fc4fe6880352a1a55b204257078060b21ae4895f4a983a8997b95a619728`
- Étapes démontrées : 21

## Vérification indépendante

Depuis le dossier contenant les artefacts :

```bash
sha256sum -c dec_v06-e10a.zip.sha256
sha256sum -c dec_v06-e10a-audit-evidence.zip.sha256
```

L’archive de preuves reste séparée de l’archive exécutable. Le manifeste JSON associé permet de vérifier qu’elles appartiennent au même processus de livraison.
