---
title: Sauvegarder, restaurer et revenir en arrière
audience:
  - installer
  - superadministrator
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: procedure
source_paths:
  - tools/python/operations
  - tools/python/qualification/run_all.py

owners:
  - operations
document_type: procedure
permissions:
source_paths:
  - tools/cms.py
  - tools/python/operations/backup
generated: false
---
# Sauvegarder, restaurer et revenir en arrière

## Sauvegarde

Exécutez `python3 tools/cms.py backup --output "/chemin/sauvegardes"`. Le dry-run de la façade a été vérifié dans l’environnement d’audit.

## Restauration

> **Avertissement :** une restauration remplace les bases ciblées.

1. Placez l’instance en maintenance ou bloquez les écritures.
2. Créez une sauvegarde de sécurité.
3. Exécutez `python3 tools/cms.py backup --restore "/chemin/archive" --yes`.
4. Validez les schémas, l’IAM et les projections.
5. Réactivez le trafic.

`--no-safety-copy` supprime une protection ; ne l’utilisez que dans une procédure automatisée disposant d’une autre copie vérifiée.

## Rollback applicatif

Restaurez ensemble le code compatible, la configuration et les bases. Un rollback de code seul peut être incompatible avec un schéma plus récent.


## Avant une migration locale

Avant d’appliquer des migrations SQLite, créez ou laissez créer une sauvegarde :

```bash
python3 tools/cms.py backup
python3 tools/cms.py migrate --plan
python3 tools/cms.py migrate --apply --backup
```

Les bases natives couvertes par cette procédure sont celles de l’inventaire central : `core.sqlite`, `iam.sqlite`, `forms.sqlite`, `cookies.sqlite` et `ai.sqlite`.
