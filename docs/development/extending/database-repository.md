---
title: Ajouter une table ou un repository
audience:
  - developer
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: manual
owners:
  - documentation
document_type: guide
permissions: []
source_paths:
  - backend/src
  - admin-app/src
  - database
  - tools
generated: false
---

# Ajouter une table ou un repository

Ajoutez le schéma de référence pour les installations neuves et une migration incrémentale numérotée pour les installations existantes; définissez contraintes et index; encapsulez le SQL dans un repository; testez transaction, rollback et données existantes; régénérez la référence des bases. Ne modifiez jamais une migration déjà publiée : ajoutez une migration corrective.
