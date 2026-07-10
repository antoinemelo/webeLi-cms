---
title: Politique des adresses de démonstration
audience:
  - developer
  - installer
  - evaluator
status: stable
last_verified: 2026-06-24
source_of_truth: code
source_paths:
  - tools/python/validation/security/pii.py
  - database/seeds
  - storage/database
owners:
  - core
document_type: reference
generated: false
---
# Politique des adresses de démonstration

Les données livrées avec le CMS ne doivent pas contenir d’adresse personnelle ou d’adresse réellement exploitable par défaut. Les seeds, fixtures, bases SQLite générées, contrats documentaires, exports et exemples opérationnels doivent utiliser des domaines réservés à la démonstration.

## Domaines autorisés

| Domaine | Usage accepté |
|---|---|
| `example.test` | Comptes de démonstration, expéditeurs techniques, destinataires de formulaire, liens `mailto:` de contenu exemple. |
| `example.org` | Valeurs de saisie ou placeholders documentaires. |
| `example.com` | Identifiants d’exemple pour services externes fictifs. |

Adresses recommandées : `admin@example.test`, `demo.user@example.test`, `contact@example.test`, `no-reply@example.test`, `editor.sitea@example.test`, `editor.siteb@example.test`.

## Adresses interdites dans les données livrées

Les adresses personnelles et les domaines réels utilisés comme données de démonstration sont interdits, notamment les domaines `gmail.com`, `ge.ch` et `webe.li` lorsqu’ils apparaissent dans les seeds, les bases générées, les contrats, les exports ou la documentation de démonstration.

## Contrôle automatisé

Le validateur `PII_EMAIL_GUARD` vérifie les surfaces de démonstration et de publication. Deux emplacements locaux sont volontairement exclus du contrôle :

- `ops/ftp.deploy.json` : configuration privée de déploiement FTP/SFTP. Elle peut contenir un identifiant réel, mais elle est ignorée par Git et exclue des releases. Le fichier distribuable reste `ops/ftp.deploy.example.json`.
- `storage/exports/release_stage/` : staging généré par la chaîne de release. Il est supprimable à tout moment et ne doit pas être considéré comme source canonique.

Les anciens exports ou anciens stagings contenant des données obsolètes doivent être supprimés puis régénérés depuis les seeds et bases corrigés.

Le validateur `PII_EMAIL_GUARD` s’exécute ainsi :

```bash
python3 tools/cms.py validate --validator PII_EMAIL_GUARD
```

Après une reconstruction from scratch, le contrôle doit rester vert :

```bash
python3 tools/cms.py rebuild
python3 tools/cms.py validate --validator PII_EMAIL_GUARD
```


## Nettoyage des anciens artefacts

Les répertoires et archives sous `storage/exports/` sont des artefacts générés. Pour éviter qu’une ancienne release locale ne réapparaisse dans les contrôles ou dans un transfert FTP, supprimer avant un nouveau packaging :

```bash
rm -rf storage/exports/release_stage
rm -rf storage/exports/dec_v* storage/exports/*_release storage/exports/*.zip
```

La commande de packaging recrée ensuite `storage/exports/release_stage/` à partir de l’état courant du dépôt.
