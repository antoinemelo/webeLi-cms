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
usage: tools/cms.py [-h] [--root ROOT] [--database-dir DATABASE_DIR] [--json] [--dry-run] [--command-timeout COMMAND_TIMEOUT] [--evidence-dir EVIDENCE_DIR]
                    {init,rebuild,validate,qualify,audit,test,export,backup,migrate,instance,release,docs} ...

Façade stable des outils de maintenance DEC CMS.

positional arguments:
  {init,rebuild,validate,qualify,audit,test,export,backup,migrate,instance,release,docs}
    init                Créer les structures SQLite sans données métier.
    rebuild             Développement/test: reconstruire les bases et appliquer les seeds natifs.
    validate            Exécuter les validateurs du CMS.
    qualify             Qualifier globalement le CMS selon un profil.
    audit               Produire des preuves reproductibles dans le conteneur d audit.
    test                Exécuter les tests automatisés Python.
    export              Générer ou simuler un export statique.
    backup              Créer ou restaurer une sauvegarde SQLite.
    migrate             Planifier ou appliquer les migrations SQLite natives et de modules.
    instance            Gérer les instances locales du CMS.
    release             Préparer et vérifier une release.
    docs                Générer ou vérifier la documentation de référence.

options:
  -h, --help            show this help message and exit
  --root ROOT           Racine du projet CMS (défaut: répertoire courant).
  --database-dir DATABASE_DIR
                        Répertoire des bases SQLite (défaut: storage/database).
  --json                Produit une enveloppe JSON stable.
  --dry-run             Affiche les actions sans modifier le projet.
  --command-timeout COMMAND_TIMEOUT
                        Borne maximale par sous-processus en secondes (défaut: 600).
  --evidence-dir EVIDENCE_DIR
                        Écrit une preuve JSON signée par SHA-256 pour la commande complète.
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
usage: tools/cms.py validate [-h] [--category {configuration,database,content,permissions,api,operations,security,documentation,shared,qualification}]
                             [--validator VALIDATOR] [--full] [--with-slow] [--list] [--no-fail-fast] [--plan-only] [--require-vue-build]

options:
  -h, --help            show this help message and exit
  --category {configuration,database,content,permissions,api,operations,security,documentation,shared,qualification}
                        Domaine à exécuter; répétable.
  --validator VALIDATOR
                        Identifiant stable du validateur; répétable.
  --full                Ajoute les qualifications déterministes plus coûteuses, notamment la reconstruction temporaire des bases.
  --with-slow           Ajoute aux contrôles complets les qualifications longues, notamment le round-trip sauvegarde/restauration.
  --list                Lister le registre sans exécuter.
  --no-fail-fast        Exécuter toute la sélection malgré les erreurs.
  --plan-only           Afficher le plan déterministe.
  --require-vue-build   Option dépréciée, sans effet.
```

## `tools/cms.py qualify`

```text
usage: tools/cms.py qualify [-h] [--profile {quick,complete,release}] [--json-report JSON_REPORT] [--markdown-report MARKDOWN_REPORT] [--no-reports]
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
usage: tools/cms.py audit [-h] [--profile {quick,full,release}] [--build] [--pull] [--engine {auto,podman,docker}] [--results-dir RESULTS_DIR]

options:
  -h, --help            show this help message and exit
  --profile {quick,full,release}
                        Niveau d'audit reproductible à exécuter dans le conteneur.
  --build               Force la reconstruction de l'image d'audit avant l'exécution.
  --pull                Actualise les images de base pendant la reconstruction.
  --engine {auto,podman,docker}
                        Moteur de conteneurs à utiliser (défaut : détection automatique).
  --results-dir RESULTS_DIR
                        Répertoire des preuves, relatif à la racine du CMS ou absolu.
```

## `tools/cms.py test`

```text
usage: tools/cms.py test [-h] [--pattern PATTERN] [--verbose] [--e2e] [--timeout TIMEOUT] [--target-duration TARGET_DURATION]

options:
  -h, --help            show this help message and exit
  --pattern PATTERN
  --verbose
  --e2e                 Exécute aussi les scénarios Playwright (serveur et identifiants E2E requis).
  --timeout TIMEOUT     Durée maximale de la suite Python en secondes (défaut : 300).
  --target-duration TARGET_DURATION
                        Durée cible en secondes ; son dépassement produit un avertissement (défaut : 120).
```

## `tools/cms.py export`

```text
usage: tools/cms.py export [-h] [--site SITE] [--lang LANG] [--all-languages] [--route ROUTE] [--output OUTPUT]

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
usage: tools/cms.py backup [-h] [--output OUTPUT] [--restore RESTORE] [--yes] [--no-safety-copy]

options:
  -h, --help         show this help message and exit
  --output OUTPUT
  --restore RESTORE
  --yes
  --no-safety-copy
```

## `tools/cms.py migrate`

```text
usage: tools/cms.py migrate [-h] [--all | --database {core,iam,forms,cookies,ai} | --module {ai-assistant,forms}] [--plan | --apply] [--backup]
                            [--no-backup-i-understand-the-risk] [--yes]

options:
  -h, --help            show this help message and exit
  --all                 Traite toutes les bases SQLite migratables connues (défaut).
  --database {core,iam,forms,cookies,ai}
                        Traite une seule base par clé ou scope.
  --module {ai-assistant,forms}
                        Traite les bases déclarées par un module.
  --plan                Affiche les migrations disponibles et manquantes sans les appliquer.
  --apply               Applique les migrations manquantes après confirmation interne.
  --backup              Crée une sauvegarde SQLite avant application.
  --no-backup-i-understand-the-risk
                        Applique sans sauvegarde préalable; option volontairement explicite et déconseillée.
  --yes                 Confirme explicitement l’application des migrations.
```

## `tools/cms.py instance`

```text
usage: tools/cms.py instance [-h] {clone,update} ...

positional arguments:
  {clone,update}
    clone         Cloner une instance locale dans un autre répertoire.
    update        Mettre à jour une instance client locale depuis une release.

options:
  -h, --help      show this help message and exit
```

## `tools/cms.py instance clone`

```text
usage: tools/cms.py instance clone [-h] [--source SOURCE] --destination DESTINATION [--old-base-path OLD_BASE_PATH] [--new-base-path NEW_BASE_PATH]
                                   [--new-public-base-url NEW_PUBLIC_BASE_URL] [--force] [--include-dev-admin-vue] [--include-docs]

options:
  -h, --help            show this help message and exit
  --source SOURCE       Répertoire source. Par défaut: racine CMS courante.
  --destination DESTINATION
                        Répertoire destination à créer, par exemple ../mod2 ou ../eve.
  --old-base-path OLD_BASE_PATH
                        APP_BASE_PATH public source. Par défaut: nom du répertoire source.
  --new-base-path NEW_BASE_PATH, --target-base-path NEW_BASE_PATH
                        APP_BASE_PATH public cible. Par défaut: nom du répertoire destination.
  --new-public-base-url NEW_PUBLIC_BASE_URL
                        APP_PUBLIC_BASE_URL exact à écrire dans ops/.env.
  --force               Remplace la destination si elle existe.
  --include-dev-admin-vue
                        Inclut les sources frontend/admin-vue.
  --include-docs        Inclut docs/, README.md et TREE.txt.
```

## `tools/cms.py instance update`

```text
usage: tools/cms.py instance update [-h] --source SOURCE --target TARGET (--plan | --apply) [--backup] [--yes] [--delete-obsolete] [--maintenance-flag] [--json]

options:
  -h, --help          show this help message and exit
  --source SOURCE     Archive ZIP ou dossier racine de release à déployer.
  --target TARGET     Dossier racine de l'instance client à mettre à jour.
  --plan              Affiche le plan fichiers/migrations sans modifier la cible.
  --apply             Applique la mise à jour après plan, backup et confirmation.
  --backup            Crée un backup SQLite avant application.
  --yes               Confirme explicitement l'application.
  --delete-obsolete   Supprime les fichiers absents de la release, hors chemins protégés.
  --maintenance-flag  Crée storage/maintenance.flag pendant la copie fichiers.
  --json              Affiche un résumé JSON stable.
```

## `tools/cms.py release`

```text
usage: tools/cms.py release [-h] [--ci] [--interactive-prepare] [--build-admin] [--run-essential-validators] [--package] [--verify-archive]
                            [--deploy {ftp,sftp}] [--skip-preflight] [--exclude-databases] [--include-vendor] [--no-zip] [--clean-stage]

options:
  -h, --help            show this help message and exit
  --ci                  Exécuter la chaîne CI stable.
  --interactive-prepare
                        Lance d0_prepare_release.py, puis poursuit automatiquement avec le préflight et le packaging.
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
usage: tools/cms.py docs [-h] {generate,check,evaluation-generate,evaluation-check} ...

positional arguments:
  {generate,check,evaluation-generate,evaluation-check}
    generate            Régénérer les références, OpenAPI et types SDK publics.
    check               Vérifier la fraîcheur des références, OpenAPI, types SDK et gouvernance documentaire.
    evaluation-generate
                        Régénérer les données machine-readable de l’espace d’évaluation.
    evaluation-check    Vérifier les données et preuves de l’espace d’évaluation.

options:
  -h, --help            show this help message and exit
```

