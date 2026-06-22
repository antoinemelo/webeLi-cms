---
title: Tests de charge traçables
audience:
  - developer
  - operator
  - evaluator
status: stable
last_verified: 2026-06-18
source_of_truth: code
source_paths:
  - tools/tests/load_test.py
  - tools/tests/README_LOAD-TEST.md
owners:
  - operations
  - core
document_type: guide
generated: false
---
# Tests de charge traçables

Les tests de charge manuels et reproductibles sont rangés sous `tools/tests/`. Ils ne font pas partie de `python3 tools/cms.py test`, qui reste réservé aux suites automatisées Python sous `tools/python/tests` et aux tests Playwright optionnels du back-office.

Le point d'entrée du test de charge est :

```bash
cd tools/tests
python3 load_test.py --help
```

Le guide détaillé est maintenu avec le script dans `tools/tests/README_LOAD-TEST.md`. Cette page CMS documente seulement son statut, son emplacement et les règles de versionnement.

## Usage prévu

Le script `tools/tests/load_test.py` exécute des campagnes HTTP autorisées sur une instance cible, par défaut `https://webe.li/mod/`. Il produit un dossier de preuve contenant les paramètres, mesures brutes, synthèses, empreintes SHA-256 et une copie du script exécuté.

Utilisez-le pour :

- qualifier une instance de test ou de préproduction ;
- produire une preuve technique jointe à un diagnostic ;
- distinguer les scénarios publics et administratifs ;
- mesurer une capacité minimale démontrée dans des conditions connues.

Ne l'utilisez pas pour :

- tester une infrastructure sans autorisation explicite ;
- établir une certification de performance ;
- comparer deux moteurs de base sans environnement contrôlé ;
- exécuter des écritures concurrentes sur des contenus réels de production.

## Fichiers versionnés et fichiers locaux

À versionner :

- `tools/tests/load_test.py` ;
- `tools/tests/README_LOAD-TEST.md`.

À ne pas versionner :

- `tools/tests/.venv/` ;
- `tools/tests/users.csv` ;
- `tools/tests/*_credentials.csv` ;
- `tools/tests/*.zip` ;
- `tools/tests/webeLi-evidence-*/`.

Les fichiers CSV contiennent des identifiants de test. Les dossiers de preuve et archives ZIP sont des résultats de campagne ; ils peuvent être conservés hors dépôt ou attachés à un rapport, mais ils ne doivent pas entrer dans le dépôt source.

## Exemples

Campagne publique sur l'environnement de test :

```bash
cd tools/tests
python3 load_test.py \
  --base-url https://webe.li/mod/ \
  --users 20 \
  --ramp 1,5,10,15,20 \
  --duration 60 \
  --mode public \
  --think-min 0.5 \
  --think-max 1.5 \
  --pause-between-stages 5 \
  --stop-on-429-rate 5 \
  --yes
```

Campagne administrative avec comptes dédiés :

```bash
cd tools/tests
python3 load_test.py \
  --base-url https://webe.li/mod/ \
  --credentials-file users.csv \
  --users 10 \
  --ramp 1,5,10 \
  --duration 60 \
  --mode admin \
  --think-min 1 \
  --think-max 3 \
  --pause-between-stages 15 \
  --stop-on-429-rate 5 \
  --yes
```

## Lecture des résultats

La conclusion doit distinguer :

- `PASS` : scénario exécuté et seuils respectés ;
- `FAIL` : scénario exécuté mais seuils dépassés ;
- `INCONCLUSIVE` : scénario non démontré, par exemple comptes insuffisants ou sessions non initialisées ;
- `SKIPPED` : scénario non exécuté.

Un taux de réponses `429` indique une limitation de trafic. Il ne prouve pas seul une saturation du CMS, de PHP ou de SQLite.

## Avant de publier un résultat

1. Conservez le dossier de preuve intact.
2. Vérifiez son `manifest.sha256`.
3. Supprimez tout fichier d'identifiants de test du paquet partagé.
4. Notez l'URL cible, l'heure, le réseau utilisé et la version Git testée.
5. Joignez le commit ou le tag Git correspondant à l'environnement testé.
