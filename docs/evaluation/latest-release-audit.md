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
  - storage/exports/dec_v06-e07a/dec_v06-e07a.release-evidence.json
  - tools/python/operations/deployment/d13_bind_release_evidence.py
owners:
  - core
generated: true
generator: tools/python/operations/deployment/d13_bind_release_evidence.py
---
# Dernier audit de release validé

Ce document est généré automatiquement pour une release **mineure ou majeure**. Les patchs ne déclenchent pas ce processus et conservent la qualification locale standard.

- Version : `dec_v06-e07a`
- Type : `minor`
- Nom : Beta
- Audit : `PASS`
- Profil : `release`
- Date UTC : `2026-06-20T15:54:43Z`
- Archive de release : `dec_v06-e07a.zip`
- SHA-256 release : `7676948b92612f559df9235c9846c2105e46d4604bddf0736d4e520ad6c2c20d`
- Archive de preuves : `dec_v06-e07a-audit-evidence.zip`
- SHA-256 preuves : `749de791fa262e2b3ee726e6905a041fdea2f3fe5b3faaab3bca5d0bbc6ae1e1`
- Étapes démontrées : 21

## Vérification indépendante

Depuis le dossier contenant les artefacts :

```bash
sha256sum -c dec_v06-e07a.zip.sha256
sha256sum -c dec_v06-e07a-audit-evidence.zip.sha256
```

L’archive de preuves reste séparée de l’archive exécutable. Le manifeste JSON associé permet de vérifier qu’elles appartiennent au même processus de livraison.
