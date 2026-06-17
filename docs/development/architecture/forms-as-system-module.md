---
title: Formulaires comme module système
audience:
  - developer
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: code
source_paths:
  - backend/src
  - frontend/admin-vue/src
  - tools/python

owners:
  - core
  - documentation
document_type: guide
permissions: []
source_paths:
  - backend/src
  - frontend
  - admin-app/src
  - database
generated: false
---

# Formulaires comme module système

Le domaine Forms est traité comme un module système : définitions, champs, soumissions, export CSV, contrats administratifs et endpoints publics restent séparés du noyau éditorial tout en utilisant les mécanismes communs de permissions, contexte et validation.

Les données de formulaire sont stockées dans leur base dédiée. Toute extension doit préserver la validation serveur, la protection contre les soumissions automatisées, la politique de conservation et l’export contrôlé.
