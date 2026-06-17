---
title: Architecture du back-office
audience:
  - developer
status: experimental
version: 1.0
last_verified: 2026-06-14
source_of_truth: manual
owners:
  - documentation
document_type: guide
permissions: []
source_paths:
  - docs
generated: false
---

# Architecture du back-office

Le back-office distribué est un bundle JavaScript compilé dans `admin-app/assets`. Les endpoints, capacités et contrats côté PHP restent vérifiables. En l’absence des sources Vue/TypeScript complètes dans cette archive, toute procédure de modification de composant ou store doit être marquée non vérifiée pour cette distribution.
