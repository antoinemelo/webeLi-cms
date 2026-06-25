---
title: Résultats de validation CI
audience:
  - developer
  - administrator
  - evaluator
status: current
last_verified: 2026-06-25
source_of_truth: .github/workflows/quality.yml; .github/workflows/release.yml
owners:
  - core-team
document_type: report
---

# Résultats de validation CI

## Vérifications du patch

Les contrôles suivants ont été exécutés sur l’archive source :

- profil `quick` : réussi, code `0` ;
- génération documentaire : réussie ;
- `tools/cms.py docs check` : réussi ;
- tests unitaires propres à l’orchestrateur : 2 réussis ;
- compilation Python des fichiers ajoutés ou modifiés : réussie ;
- recherche des appels directs aux anciens validateurs numérotés : aucune référence active restante.

Le profil `complete` remplace l’ancien libellé `standard` dans la CI. L’orchestrateur rapporte les situations incomplètes comme `skipped`, `failed` ou `timeout`; il ne les transforme jamais en succès.

## Comportement attendu en CI

- `.github/workflows/quality.yml` installe Composer et npm, reconstruit les bases SQLite temporaires, puis exécute le profil `complete` ;
- `.github/workflows/release.yml` installe également Chromium pour Playwright, reconstruit les bases SQLite temporaires et exécute le profil `release` ;
- les rapports `storage/qualification/latest.json` et `latest.md` sont publiés comme artefacts, même en cas d’échec ;
- un code `2` signifie « qualification incomplète » et fait échouer le job comme un code `1`.

Les résultats propres à chaque exécution sont conservés dans les artefacts CI et ne sont pas recopiés manuellement dans cette page.
