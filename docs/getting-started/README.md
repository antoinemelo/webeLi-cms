---
title: Prise en main
audience:
  - editor
  - administrator
  - installer
  - developer
status: stable
last_verified: 2026-06-14
source_of_truth: manual
owners:
  - core
document_type: guide
generated: false
---
# Prise en main

## Utiliser une release existante

1. Vérifiez les [prérequis](../installation/requirements.md).
2. Suivez [l’installation d’une release](../installation/install-release.md).
3. Connectez-vous au back-office avec [connexion, profil et contexte](../user-guide/getting-started/sign-in-profile-context.md).
4. Créez un premier contenu avec [créer et modifier](../user-guide/content/create-edit.md).
5. Exécutez la validation locale :

```bash
python3 tools/cms.py validate --full
python3 tools/cms.py test
```

## Mettre à jour une installation existante

```bash
python3 tools/cms.py migrate --plan
python3 tools/cms.py migrate --apply --backup --yes
python3 tools/cms.py validate
```

Pour une mise à jour complète de release, utilisez `tools/cms.py instance update`. La procédure détaillée se trouve dans [mettre à jour une base existante](../operations/existing-database-update.md).

## Repartir de zéro pour développer

```bash
python3 tools/cms.py rebuild
python3 tools/cms.py docs generate
python3 tools/cms.py validate --full
python3 tools/cms.py test
```

La reconstruction supprime et recrée les bases SQLite natives, applique les seeds et reconstruit les projections. Elle est réservée au développement, aux tests ou à une récupération contrôlée après sauvegarde. Elle ne remplace pas une mise à jour d’une instance contenant des données utiles.

## Lire ensuite

- [Guide utilisateur](../user-guide/README.md)
- [Administration](../administration/README.md)
- [Architecture et développement](../development/README.md)
- [Exploitation](../operations/README.md)
