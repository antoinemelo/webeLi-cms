---
title: Matrice fonctionnelle
document_type: evaluation
audience:
  - evaluator
  - ai-evaluator
status: stable
last_verified: 2026-06-14
source_of_truth: manual
source_paths:
  - tools/python/qualification/run_all.py
  - tools/python/validation
  - docs/evaluation/machine-readable

generated: false
owners:
  - core
evidence_scope:
  - code
  - tests
  - validators
  - documentation
---

# Matrice fonctionnelle

La référence exhaustive et extractible est [`machine-readable/features.json`](machine-readable/features.json). Le tableau ci-dessous synthétise les capacités principales ; « preuve » désigne un point de départ, pas une couverture complète.

| Domaine | Fonctionnalité | État | Public / permission | Interface / API / commande | Preuve principale | Limite |
|---|---|---|---|---|---|---|
| IAM | comptes et authentification | `supported` | utilisateurs admin | écran de connexion, API admin | `AdminAuthController.php`, schéma IAM | flux réels à tester |
| IAM | double authentification TOTP | `supported` | utilisateur autorisé | sécurité du profil | migration IAM 0004, validateurs c41/c45 | récupération et mail à tester |
| IAM | 2FA par e-mail / passwordless | `partial` | selon configuration | back-office | validateurs c46/c47 | dépend du transport mail |
| contenu | pages, articles, types | `supported` | permissions contenu | back-office/API admin | contrôleurs contenu, schéma core | scénarios UX manuels |
| modèle | blueprints, champs, fieldsets, blocs | `supported` | admin/modélisateur | back-office/API | registres, schéma, validateurs | compatibilité extensions à qualifier |
| workflow | brouillon, révision, prévisualisation | `supported` | éditeur/publicateur | admin + preview | révisions, Preview API | concurrence à tester |
| workflow | publication/dépublication/archivage | `supported` | permissions dédiées | admin/API | pipeline et validateurs | planification complète à vérifier |
| sites | multisite et langues | `supported` | admin | admin/API/public | schéma, seeds, c4 | cas extrêmes non exhaustifs |
| médias | upload, métadonnées, variantes | `supported` | media.* | admin/API | services Media | charge et antivirus non démontrés |
| médias | stockage S3 | `partial` | configuration opérateur | configuration | `S3StorageDriver.php`, c55 | fournisseur externe non testé ici |
| navigation | menus et taxonomies | `supported` | permissions dédiées | admin/public/API | contrôleurs et repositories | UX manuelle |
| SEO | métadonnées, canonical, robots, hreflang | `supported` | SEO/content | admin + SSR | schéma SEO, runtime, c9 | rendu à vérifier par site |
| SEO | sitemap, redirections, JSON-LD, audit | `supported` | SEO/admin | public/admin | routes, services, validateurs | qualité sémantique dépend des données |
| recherche | recherche publique native | `supported` | public | SSR/API | module Search, c21 | pas un moteur distribué |
| formulaires | modèles et soumissions | `supported` | module/permissions | public/admin/API | module Forms, g2/c73 | anti-spam externe à qualifier |
| cookies | consentement | `supported` | admin/public | module/API | module Cookies, g3 | conformité juridique non certifiée |
| import/export | paquet éditorial | `supported` | permissions import/export | admin/API | services EditorialPackage, c88 | compatibilité interversions à tester |
| export statique | génération | `partial` | permission export | CLI/admin | validateurs static export | hébergement cible non testé |
| assistant IA | suggestions éditoriales/SEO/traduction | `experimental` | permissions ai.* | module admin | schéma AI, contrôleurs, c85-c87 | fournisseur, coût, confidentialité |
| headless | API publique v1 et OpenAPI | `supported` | public/token selon endpoint | HTTP | PublicApiKernel, OpenAPI, c41/c43 | SLA/version future non établi |
| exploitation | sauvegarde/restauration | `supported` | opérateur | CLI | commande backup | restauration à tester sur copie |
| exploitation | packaging/release/FTP/SFTP | `partial` | opérateur | CLI/CI | scripts deployment, workflow | infra réelle non testée |
| observabilité | journaux applicatifs | `partial` | opérateur | fichiers/logs | `Core/Logger.php` | métriques/traces centralisées absentes |
