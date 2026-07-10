# Outils Python — DEC CMS

## Interface publique

Le seul point d’entrée public est :

```bash
python3 tools/cms.py <commande>
```

Commandes principales : `init`, `migrate`, `backup`, `validate`, `qualify`, `test`, `export`, `release`, `docs`, `instance`. `rebuild` existe toujours, mais uniquement pour le développement, les tests ou une récupération contrôlée après sauvegarde.

## Architecture interne

- `cms/` : parseur CLI et contexte d’exécution ;
- `commands/` : adaptateurs des commandes publiques ;
- `operations/database/` : initialisation, seeds, projections, migrations et maintenance SQLite ;
- `operations/deployment/` : préflight, packaging, vérification et déploiement ;
- `operations/backup/` : sauvegarde et restauration de l’inventaire SQLite unifié ;
- `operations/ai/` : création et remise à zéro de la base IA ;
- `operations/maintenance/` : audits et tâches d’exploitation spécialisées ;
- `validators/` : validateurs actifs, exécutés via le registre déclaratif ;
- `generators/` : documentation, OpenAPI, SDK et schémas ;
- `lib/` : bibliothèques partagées, dont l’inventaire des bases natives et de modules ;
- `diagnostics/` : consultation du manifeste des outils ;
- `tests/` : tests de la CLI et du packaging ;
- `archive/` : scripts historiques, non utilisables en production.

Les modules internes ne constituent pas des points d’entrée publics. Leur chemin peut évoluer ; la CLI est le contrat stable.

## Procédure normale sur une installation existante

Pour une instance contenant déjà du contenu, la mise à jour des bases se fait par migrations incrémentales :

```bash
python3 tools/cms.py backup --output storage/backups/manual-before-migrate.zip
python3 tools/cms.py migrate --plan
python3 tools/cms.py migrate --apply --backup --yes
python3 tools/cms.py validate
```

Le mode `--plan` est non mutatif. Le mode `--apply` exige `--backup` ou l’option volontairement longue `--no-backup-i-understand-the-risk`. Les migrations appliquées sont tracées dans `schema_migrations` dans chaque base SQLite concernée.

Pour une release complète du noyau sur une instance client, utiliser :

```bash
python3 tools/cms.py instance update --source /chemin/release.zip --target /chemin/site-client --plan
python3 tools/cms.py instance update --source /chemin/release.zip --target /chemin/site-client --apply --backup --yes
```

Ce flux conserve les chemins protégés (`storage/database/`, médias, logs, backups, `ops/.env`, `ops/modules.local.json`, `local/modules/`), applique les fichiers du noyau, puis exécute les migrations sur la cible.

## Repartir de zéro en développement

La reconstruction complète des bases SQLite natives, des seeds et des projections s'effectue exclusivement avec la CLI publique :

```bash
python3 tools/cms.py rebuild
```

Cette commande est destructive pour les bases locales existantes. Elle sert à préparer un environnement de développement, un test reproductible ou une récupération contrôlée après sauvegarde. Elle ne remplace jamais une mise à jour d’instance avec contenu. Utilisez le mode dry-run pour inspecter les opérations sans les exécuter :

```bash
python3 tools/cms.py --dry-run rebuild
```

## Valider le CMS

La validation standard s'exécute avec :

```bash
python3 tools/cms.py validate
```

Pour exécuter l'ensemble des catégories actives avant une release :

```bash
python3 tools/cms.py validate --full
```

Les validateurs internes sont enregistrés dans le registre déclaratif et doivent normalement être lancés via la façade `tools/cms.py`.

## Modules internes de production contrôlés

La commande publique reste exclusivement `python3 tools/cms.py ...`. Les modules suivants sont actifs et contrôlés par les validateurs ; ils ne doivent ni être déplacés dans `archive/`, ni être invoqués comme interface utilisateur :

- `operations/deployment/d1_preflight_local.py` : préflight local, staging et production ;
- `operations/deployment/d2_package_release.py` : construction du paquet de release ;
- `operations/deployment/d4_verify_release_archive.py` : vérification de l’archive produite ;
- `operations/deployment/d5_smoke_test_release_structure.py` : smoke test de structure ;
- `operations/backup/d6_backup_sqlite.py` : sauvegarde cohérente de l’inventaire SQLite ;
- `operations/backup/d7_restore_sqlite.py` : restauration contrôlée des bases SQLite ;
- `operations/deployment/d8_deploy_web_update.py` : planification, application et rollback d’une mise à jour web ;
- `operations/database/d9_migrate_sqlite.py` : migrations incrémentales pour les installations existantes ;
- `operations/database/d10_rebase_sqlite_data.py` : rebase de données vers une structure native propre ;
- `validators/validate_release_security_packaging.py` : contrôle sécurité du packaging.

## Qualification globale

La commande canonique est :

```bash
python3 tools/cms.py qualify --profile complete
```

Les profils `quick`, `complete` et `release` produisent des rapports JSON et Markdown et distinguent explicitement les étapes ignorées des succès. Aucun profil ne reconstruit les bases de données ; `rebuild` reste une opération indépendante et destructive. Voir `docs/development/testing-validation/QUALIFICATION_COMMANDS.md`.
