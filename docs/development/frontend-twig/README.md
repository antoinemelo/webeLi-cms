---
title: Développer le front SSR, Twig et les thèmes
audience:
  - developer
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: code
owners:
  - core
document_type: guide
permissions:
source_paths:
  - frontend
  - backend/src/Rendering
  - backend/src/Seo
generated: false
---
# Développer le front SSR, Twig et les thèmes

Le runtime résout une projection publiée et un template lié par blueprint. Les thèmes se trouvent sous le front et doivent conserver échappement, accessibilité, canonical, hreflang, métadonnées sociales et JSON-LD.

N’utilisez le HTML brut qu’avec une politique explicite et une permission dédiée. Testez desktop, mobile, sous-répertoire et langues.
