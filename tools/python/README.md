# Outils Python — DEC CMS

## Interface publique

Le seul point d’entrée public est :

```bash
python3 tools/cms.py <commande>
```

Commandes : `init`, `rebuild`, `validate`, `test`, `export`, `backup`, `release`, `docs`, `instance`.

## Architecture interne

- `cms/` : parseur CLI et contexte d’exécution ;
- `commands/` : adaptateurs des commandes publiques ;
- `operations/database/` : initialisation, seeds, projections et maintenance SQLite ;
- `operations/deployment/` : préflight, packaging, vérification et déploiement ;
- `operations/backup/` : sauvegarde et restauration des cinq bases natives ;
- `operations/ai/` : création et remise à zéro de la base IA ;
- `operations/maintenance/` : audits et tâches d’exploitation spécialisées ;
- `validators/` : validateurs actifs, exécutés via le registre déclaratif ;
- `generators/` : documentation, OpenAPI, SDK et schémas ;
- `lib/` : bibliothèques partagées ;
- `diagnostics/` : consultation du manifeste des outils ;
- `tests/` : tests de la CLI et du packaging ;
- `archive/` : scripts historiques, non utilisables en production.

Les modules internes ne constituent pas des points d’entrée publics. Leur chemin peut évoluer ; la CLI est le contrat stable.

## Procédures courantes

### Reconstruire les bases from scratch

La reconstruction complète des cinq bases SQLite natives, des seeds et des projections s'effectue exclusivement avec la CLI publique :

```bash
python3 tools/cms.py rebuild
```

Cette commande est destructive pour les bases locales existantes. Utilisez le mode dry-run pour inspecter les opérations sans les exécuter :

```bash
python3 tools/cms.py --dry-run rebuild
```

### Valider le CMS

La validation standard s'exécute avec :

```bash
python3 tools/cms.py validate
```

Pour exécuter l'ensemble des catégories actives avant une release :

```bash
python3 tools/cms.py validate --full
```

Le validateur de garde de régression est enregistré dans le registre déclaratif sous le module interne `c17_validate_regression_guard.py`. Il ne constitue pas un point d'entrée public et doit normalement être lancé via `python3 tools/cms.py validate`.

Pour un diagnostic ciblé exceptionnel, son module interne peut être exécuté directement :

```bash
python3 tools/cms.py validate
```


### Valider la sécurité de l’API headless v1

La validation de la sécurité de l’API publique headless v1 est enregistrée dans le registre déclaratif. Elle contrôle notamment les routes publiques, les snapshots publiés, les contrats JSON, les permissions critiques, le garde admin, les téléversements médias et l’absence de lecture depuis les brouillons.

Exécution recommandée :

```bash
python3 tools/cms.py validate --category security
```

Elle est également incluse dans la validation complète :

```bash
python3 tools/cms.py validate --full
```

Le module interne correspondant est `tools.python.validators.c28_validate_security_headless_v1`. Il ne constitue pas un point d’entrée public. Pour un diagnostic ciblé exceptionnel :

```bash
python3 tools/cms.py validate
```

## Modules internes de production contrôlés

La commande publique reste exclusivement `python3 tools/cms.py ...`. Les modules suivants sont toutefois actifs et contrôlés par le validateur de release ; ils ne doivent ni être déplacés dans `archive/`, ni être invoqués comme interface utilisateur :

- `operations/deployment/d1_preflight_local.py` : préflight local, staging et production ;
- `operations/deployment/d2_package_release.py` : construction du paquet de release ;
- `operations/deployment/d4_verify_release_archive.py` : vérification de l’archive produite ;
- `operations/deployment/d5_smoke_test_release_structure.py` : smoke test de structure ;
- `operations/backup/d6_backup_sqlite.py` : sauvegarde cohérente des bases SQLite ;
- `operations/backup/d7_restore_sqlite.py` : restauration contrôlée des bases SQLite ;
- `operations/deployment/d8_deploy_web_update.py` : planification, application et rollback d’une mise à jour web ;
- `operations/database/d9_migrate_sqlite.py` : migrations incrémentales pour les installations existantes ;
- `operations/database/d10_rebase_sqlite_data.py` : rebase de données vers une structure native propre ;
- `validators/validate_release_security_packaging.py` : contrôle sécurité du packaging.

Le scénario d’installation native et de reconstruction from scratch n’utilise pas `d9_migrate_sqlite.py` : les migrations restent réservées aux installations existantes.


## Qualification globale

La commande canonique est `python3 tools/cms.py qualify --profile complete`. Les profils `quick`, `complete` et `release` produisent des rapports JSON et Markdown et distinguent explicitement les étapes ignorées des succès. Aucun profil ne reconstruit les bases de données ; cette opération reste réservée à `python3 tools/cms.py rebuild`. Voir `docs/development/testing-validation/QUALIFICATION_COMMANDS.md`.
