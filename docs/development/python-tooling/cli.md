---
title: Utiliser la CLI et les outils Python
audience:
  - developer
  - operator
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: code
source_paths:
  - backend/src
  - frontend/admin-vue/src
  - tools/python

owners:
  - core
  - operations
document_type: guide
permissions:
source_paths:
  - tools/cms.py
  - tools/python/README.md
  - tools/python/tool-manifest.json
  - tools/python/generators/generate_documentation.py
generated: false
---
# Utiliser la CLI et les outils Python

La façade de maintenance stable du CMS est :

```bash
python3 tools/cms.py
```

Utilisez-la pour les opérations courantes. Les scripts rangés sous `tools/python/operations/`, `tools/python/generators/` et `tools/python/validators/` restent utiles au développement, mais leur chemin et leurs paramètres peuvent évoluer. Une procédure d’exploitation ne doit appeler directement un script interne que lorsqu’aucune commande de la façade ne couvre l’opération.

## Prérequis

- Python 3 disponible sous la commande `python3` ;
- exécution depuis la racine du projet, celle qui contient `tools/cms.py` ;
- droits d’écriture sur `storage/` pour les commandes qui créent des bases, sauvegardes, exports ou releases ;
- copie de sauvegarde avant une reconstruction ou une restauration.

Les chemins contenant des espaces sont acceptés lorsque vous utilisez les options prévues et que vous placez le chemin entre guillemets.

## Découvrir les commandes disponibles

```bash
python3 tools/cms.py --help
```

La CLI expose les familles suivantes :

- `init` : créer les structures SQLite sans données métier ;
- `rebuild` : reconstruire les bases et charger les seeds natifs ;
- `validate` : exécuter les validateurs ;
- `test` : exécuter les tests Python ;
- `export` : générer ou simuler un export statique ;
- `backup` : créer ou restaurer une sauvegarde ;
- `migrate` : planifier ou appliquer les migrations SQLite natives ;
- `release` : préparer ou vérifier une release ;
- `docs` : générer ou contrôler les références documentaires.

Pour connaître les paramètres d’une famille :

```bash
python3 tools/cms.py validate --help
python3 tools/cms.py backup --help
python3 tools/cms.py release --help
python3 tools/cms.py docs --help
```

La référence exhaustive, générée depuis le parseur de commandes, se trouve dans `docs/reference/generated/cli-commands.md`.

## Options globales

Placez les options globales avant la sous-commande :

```bash
python3 tools/cms.py --json validate
python3 tools/cms.py --dry-run release build
python3 tools/cms.py --root "/srv/Mon CMS" docs check
python3 tools/cms.py --database-dir "/srv/Mon CMS/storage/database" validate
```

- `--root` désigne explicitement la racine du CMS ;
- `--database-dir` remplace le répertoire SQLite par défaut ;
- `--json` produit une sortie structurée destinée à l’automatisation ;
- `--dry-run` simule l’opération lorsqu’elle le prend en charge.

Ne supposez pas qu’une option globale placée après la sous-commande sera comprise : suivez l’ordre affiché par `--help`.

## Initialiser ou reconstruire les bases

Pour créer les structures sans données métier :

```bash
python3 tools/cms.py init
```

Pour repartir des schémas et seeds natifs :

```bash
python3 tools/cms.py rebuild
```

`rebuild` est destructif pour les bases ciblées. Utilisez-le sur un environnement de développement ou après une sauvegarde vérifiée. Pour une instance contenant des données à conserver, suivez la procédure de mise à jour ou de restauration documentée au lieu de reconstruire sans contrôle.

## Lancer les validateurs et les tests

```bash
python3 tools/cms.py validate
python3 tools/cms.py test
```

Pour cibler ou comprendre les sélections disponibles, consultez l’aide de la sous-commande. En CI, préférez la sortie JSON afin de distinguer proprement succès, avertissements et erreurs.

Le validateur documentaire canonique peut aussi être exécuté directement lorsqu’un diagnostic précis est nécessaire :

```bash
python3 tools/cms.py validate
```

Le garde de régression fonctionnel est :

```bash
python3 tools/cms.py validate
```

Ces appels directs sont des points d’entrée de validation identifiés par le registre ; ils ne doivent pas être généralisés à tous les scripts internes.

## Générer et contrôler la documentation

Après une modification de routes, permissions, commandes, contrats ou schémas :

```bash
python3 tools/cms.py docs generate
python3 tools/cms.py docs check
```

`docs generate` met à jour les références dérivées du code. `docs check` ne doit pas modifier les fichiers : il vérifie que les sorties générées sont à jour et que la gouvernance documentaire est respectée.

Une contribution n’est pas terminée lorsque le code a changé mais que les références générées sont encore anciennes.


## Chaîne headless v1 à exécuter

La génération et la validation du contrat headless suivent une chaîne explicite. Exécutez les scripts depuis la racine du projet, dans cet ordre :

```bash
python3 tools/python/generators/c40_generate_openapi_headless_v1.py
python3 tools/python/generators/c44_generate_sdk_types_from_openapi.py
python3 tools/cms.py validate
python3 tools/cms.py validate
python3 tools/cms.py validate
python3 tools/cms.py validate
python3 tools/cms.py validate
python3 tools/cms.py validate
python3 tools/cms.py validate
```

Les noms de fichiers suivis par la chaîne sont :

- `c40_generate_openapi_headless_v1.py` ;
- `c44_generate_sdk_types_from_openapi.py` ;
- `python3 tools/cms.py validate --validator API_SPEC` ;
- `c42_validate_sdk_headless_v1.py` ;
- `c43_validate_public_api_docs.py` ;
- `c62_validate_headless_examples.py` ;
- `c63_validate_admin_headless_api_ux.py` ;
- `c64_validate_public_api_docs_serving.py` ;
- `c65_validate_headless_frontend_integration.py`.

Les deux premières commandes régénèrent les artefacts dérivés. Les suivantes contrôlent successivement le contrat OpenAPI, le SDK, le portail public, les exemples, l’écran d’administration et le service de documentation. La dernière consolide l’ensemble. Ne modifiez pas manuellement les fichiers générés pour contourner une erreur : corrigez la source puis relancez la chaîne.

## Créer et vérifier une release

Consultez d’abord l’aide, car le nom des actions et les paramètres de destination font partie du contrat de la commande :

```bash
python3 tools/cms.py release --help
```

Le flux attendu est toujours le même :

1. valider le projet ;
2. exécuter les tests ;
3. contrôler la documentation ;
4. construire la release ;
5. vérifier l’archive produite ;
6. tester son extraction dans un répertoire distinct.

Utilisez `--dry-run` lorsqu’il est proposé pour contrôler la sélection de fichiers avant de produire une archive distribuable.

## Sauvegarder et restaurer

Consultez les actions disponibles :

```bash
python3 tools/cms.py backup --help
```

Une sauvegarde n’est considérée comme exploitable qu’après un test de restauration dans un répertoire séparé. N’écrasez pas une instance en production pour vérifier une archive.

## Planifier et appliquer les migrations SQLite

Les migrations locales passent par la façade stable :

```bash
python3 tools/cms.py migrate --plan
python3 tools/cms.py migrate --apply --backup
python3 tools/cms.py migrate --database ai --plan
```

Les bases natives migratables sont déclarées dans `tools/python/lib/database_inventory.py`. Chaque base possède son propre registre `schema_migrations`. Le retour arrière recommandé reste la restauration d’une sauvegarde SQLite vérifiée, pas une down migration SQL.

Procédure courte :

1. `python3 tools/cms.py backup` ;
2. `python3 tools/cms.py migrate --plan` ;
3. `python3 tools/cms.py migrate --apply --backup` ;
4. `python3 tools/cms.py validate --category database` ;
5. restaurer le backup si un contrôle échoue.

## Bon usage dans les scripts et la CI

- utilisez `python3 tools/cms.py` plutôt qu’un chemin interne ;
- fixez la racine avec `--root` lorsque le répertoire courant n’est pas garanti ;
- utilisez `--json` pour une consommation machine ;
- contrôlez le code de sortie ;
- protégez tous les chemins externes par des guillemets ;
- n’analysez pas le texte destiné aux humains lorsqu’une sortie JSON existe ;
- ne masquez pas un avertissement ou une erreur avec `|| true` ;
- conservez les journaux de validation et de packaging comme preuves de release.

Exemple robuste :

```bash
python3 tools/cms.py --root "$CMS_ROOT" --json docs check
```

## Quand appeler un script interne

Un script interne peut être appelé directement pour :

- développer ou diagnostiquer son propre comportement ;
- exécuter un validateur canonique explicitement référencé ;
- maintenir le générateur documentaire ;
- intervenir sur une opération qui n’est pas encore exposée par la façade.

Dans ce cas :

1. vérifiez son `--help` ou son point d’entrée ;
2. indiquez dans la procédure qu’il s’agit d’un outil interne ;
3. ajoutez un test ;
4. envisagez de l’exposer dans `tools/cms.py` si l’usage devient régulier.

## Erreurs fréquentes

### `tools/cms.py` introuvable

Vous n’êtes probablement pas à la racine du projet. Revenez dans le répertoire du CMS ou utilisez `--root` depuis une copie qui contient bien les outils.

### Module Python introuvable

Lancez la commande depuis la racine du projet. Pour un validateur direct, utilisez la forme `python3 -m ...` afin que le paquet `tools` soit résolu correctement.

### Permission refusée dans `storage/`

Corrigez les droits du compte qui exécute la commande. N’utilisez pas systématiquement `sudo`, car les fichiers produits pourraient ensuite appartenir au mauvais utilisateur.

### La commande fonctionne localement mais pas en CI

Vérifiez la version de Python, le répertoire courant, les variables d’environnement, les droits d’écriture et l’ordre des options globales. Utilisez `--json` et conservez la sortie complète.

### `docs check` signale des références obsolètes

Exécutez `python3 tools/cms.py docs generate`, examinez les différences, puis relancez le contrôle. Ne modifiez pas manuellement un fichier marqué comme généré.

## Sources de vérité

- la syntaxe des commandes vient de `tools/cms.py` ;
- l’inventaire détaillé est généré dans `docs/reference/generated/cli-commands.md` ;
- le manifeste des outils décrit les scripts suivis par le projet ;
- les procédures d’exploitation complètent ces références sans recopier tous les paramètres.
