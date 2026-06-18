---
title: Commandes CLI
audience:
  - developer
  - installer
  - evaluator
status: stable
version: 1.0
source_of_truth: generated
generator: tools/python/generators/generate_documentation.py
owners:
  - core
document_type: reference
generated: true
---
# Commandes CLI

> Fichier généré. Ne pas modifier directement.

## Aide générale

```text
usage: tools/cms.py [-h] [--root ROOT] [--database-dir DATABASE_DIR] [--json]
                    [--dry-run] [--command-timeout COMMAND_TIMEOUT]
                    [--evidence-dir EVIDENCE_DIR]
                    {init,rebuild,validate,qualify,audit,test,export,backup,release,docs,instance}
                    ...

Façade stable des outils de maintenance DEC CMS.

positional arguments:
  {init,rebuild,validate,qualify,audit,test,export,backup,release,docs,instance}
    init                Créer les structures SQLite sans données métier.
    rebuild             Reconstruire les bases et appliquer les seeds natifs.
    validate            Exécuter les validateurs du CMS.
    qualify             Qualifier globalement le CMS selon un profil.
    audit               Produire des preuves reproductibles dans le conteneur
                        d audit.
    test                Exécuter les tests automatisés Python.
    export              Générer ou simuler un export statique.
    backup              Créer ou restaurer une sauvegarde SQLite.
    release             Préparer et vérifier une release.
    docs                Générer ou vérifier la documentation de référence.
    instance            Préparer ou cloner une instance locale.

options:
  -h, --help            show this help message and exit
  --root ROOT           Racine du projet CMS (défaut: répertoire courant).
  --database-dir DATABASE_DIR
                        Répertoire des bases SQLite (défaut:
                        storage/database).
  --json                Produit une enveloppe JSON stable.
  --dry-run             Affiche les actions sans modifier le projet.
  --command-timeout COMMAND_TIMEOUT
                        Borne maximale par sous-processus en secondes (défaut:
                        600).
  --evidence-dir EVIDENCE_DIR
                        Écrit une preuve JSON signée par SHA-256 pour la
                        commande complète.
```

## `tools/cms.py init`

```text
usage: tools/cms.py init [-h] [--with-reference-seed]

options:
  -h, --help            show this help message and exit
  --with-reference-seed
```

## `tools/cms.py rebuild`

```text
usage: tools/cms.py rebuild [-h] [--skip-projections]

options:
  -h, --help          show this help message and exit
  --skip-projections
```

## `tools/cms.py validate`

```text
usage: tools/cms.py validate [-h]
                             [--category {configuration,database,content,permissions,api,operations,security,documentation,shared,qualification}]
                             [--validator VALIDATOR] [--full] [--with-slow]
                             [--list] [--no-fail-fast] [--plan-only]
                             [--require-vue-build]

options:
  -h, --help            show this help message and exit
  --category {configuration,database,content,permissions,api,operations,security,documentation,shared,qualification}
                        Domaine à exécuter; répétable.
  --validator VALIDATOR
                        Identifiant stable du validateur; répétable.
  --full                Ajoute les qualifications déterministes plus
                        coûteuses, notamment la reconstruction temporaire des
                        bases.
  --with-slow           Ajoute aux contrôles complets les qualifications
                        longues, notamment le round-trip
                        sauvegarde/restauration.
  --list                Lister le registre sans exécuter.
  --no-fail-fast        Exécuter toute la sélection malgré les erreurs.
  --plan-only           Afficher le plan déterministe.
  --require-vue-build   Option dépréciée, sans effet.
```

## `tools/cms.py qualify`

```text
usage: tools/cms.py qualify [-h] [--profile {quick,complete,release}]
                            [--json-report JSON_REPORT]
                            [--markdown-report MARKDOWN_REPORT] [--no-reports]
                            [--continue-on-failure] [--list]

options:
  -h, --help            show this help message and exit
  --profile {quick,complete,release}
  --json-report JSON_REPORT
  --markdown-report MARKDOWN_REPORT
  --no-reports
  --continue-on-failure
  --list
```

## `tools/cms.py audit`

```text
usage: tools/cms.py audit [-h] [--profile {quick,full,release}] [--build]
                          [--pull] [--engine {auto,podman,docker}]
                          [--results-dir RESULTS_DIR]

options:
  -h, --help            show this help message and exit
  --profile {quick,full,release}
                        Niveau d'audit reproductible à exécuter dans le
                        conteneur.
  --build               Force la reconstruction de l'image d'audit avant
                        l'exécution.
  --pull                Actualise les images de base pendant la
                        reconstruction.
  --engine {auto,podman,docker}
                        Moteur de conteneurs à utiliser (défaut : détection
                        automatique).
  --results-dir RESULTS_DIR
                        Répertoire des preuves, relatif à la racine du CMS ou
                        absolu.
```

## `tools/cms.py test`

```text
usage: tools/cms.py test [-h] [--pattern PATTERN] [--verbose] [--e2e]
                         [--timeout TIMEOUT]
                         [--target-duration TARGET_DURATION]

options:
  -h, --help            show this help message and exit
  --pattern PATTERN
  --verbose
  --e2e                 Exécute aussi les scénarios Playwright (serveur et
                        identifiants E2E requis).
  --timeout TIMEOUT     Durée maximale de la suite Python en secondes (défaut
                        : 300).
  --target-duration TARGET_DURATION
                        Durée cible en secondes ; son dépassement produit un
                        avertissement (défaut : 120).
```

## `tools/cms.py export`

```text
usage: tools/cms.py export [-h] [--site SITE] [--lang LANG] [--all-languages]
                           [--route ROUTE] [--output OUTPUT]

options:
  -h, --help       show this help message and exit
  --site SITE
  --lang LANG
  --all-languages
  --route ROUTE
  --output OUTPUT
```

## `tools/cms.py backup`

```text
usage: tools/cms.py backup [-h] [--output OUTPUT] [--restore RESTORE] [--yes]
                           [--no-safety-copy]

options:
  -h, --help         show this help message and exit
  --output OUTPUT
  --restore RESTORE
  --yes
  --no-safety-copy
```

## `tools/cms.py release`

```text
usage: tools/cms.py release [-h] [--ci] [--interactive-prepare]
                            [--build-admin] [--run-essential-validators]
                            [--package] [--verify-archive]
                            [--deploy {ftp,sftp}] [--skip-preflight]
                            [--exclude-databases] [--include-vendor]
                            [--no-zip] [--clean-stage]

options:
  -h, --help            show this help message and exit
  --ci                  Exécuter la chaîne CI stable.
  --interactive-prepare
                        Lance d0_prepare_release.py, puis poursuit
                        automatiquement avec le préflight et le packaging.
  --build-admin
  --run-essential-validators
  --package
  --verify-archive
  --deploy {ftp,sftp}
  --skip-preflight
  --exclude-databases
  --include-vendor
  --no-zip
  --clean-stage
```

## `tools/cms.py docs`

```text
usage: tools/cms.py docs [-h]
                         {generate,check,evaluation-generate,evaluation-check}
                         ...

positional arguments:
  {generate,check,evaluation-generate,evaluation-check}
    generate            Régénérer les références, OpenAPI et types SDK
                        publics.
    check               Vérifier la fraîcheur des références, OpenAPI, types
                        SDK et gouvernance documentaire.
    evaluation-generate
                        Régénérer les données machine-readable de l’espace
                        d’évaluation.
    evaluation-check    Vérifier les données et preuves de l’espace
                        d’évaluation.

options:
  -h, --help            show this help message and exit
```

