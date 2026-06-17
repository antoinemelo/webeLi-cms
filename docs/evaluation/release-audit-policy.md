---
title: Politique d’audit des releases
document_type: evaluation
audience:
  - evaluator
  - administrator
  - developer
status: stable
version: 1.1
last_verified: 2026-06-14
source_of_truth: procedure
source_paths:
  - Dockerfile.audit
  - tools/audit/audit.sh
  - tools/audit/run-audit.sh
  - tools/python/operations/deployment/d_deploy.py
  - tools/python/operations/deployment/d13_bind_release_evidence.py
generated: false
owners:
  - core
---
# Politique d’audit des releases

## Périmètre

L’audit reproductible est une condition obligatoire pour les releases **mineures** et **majeures**. Il n’est pas déclenché automatiquement pour les **patchs**, afin de conserver un flux de correction léger. Les patchs restent soumis à la qualification locale de release, au préflight, au packaging et à la vérification de l’archive.

| Type | Qualification locale | Audit reproductible | Association des preuves |
|---|---:|---:|---:|
| patch | obligatoire | non automatique | non |
| minor | incluse dans l’audit | obligatoire | obligatoire |
| major | incluse dans l’audit | obligatoire | obligatoire |

## Chaîne mineure/majeure

```text
préparation des métadonnées
→ audit reproductible dans un conteneur isolé
→ préflight local
→ création de l’archive
→ vérification de l’archive
→ copie et renommage de l’archive de preuves
→ calcul des empreintes SHA-256
→ manifeste release/preuves
→ mise à jour du dernier audit sous docs/evaluation/
```

Une étape en échec interrompt la chaîne. Un contrôle non exécuté ou non démontré ne doit pas être présenté comme réussi.

## Artefacts produits

Pour `dec_vXX-eYYz`, la livraison mineure/majeure produit séparément :

```text
storage/exports/dec_vXX-eYYz/dec_vXX-eYYz.zip
storage/exports/dec_vXX-eYYz/dec_vXX-eYYz.zip.sha256
storage/exports/dec_vXX-eYYz/dec_vXX-eYYz-audit-evidence.zip
storage/exports/dec_vXX-eYYz/dec_vXX-eYYz-audit-evidence.zip.sha256
storage/exports/dec_vXX-eYYz/dec_vXX-eYYz.release-evidence.json
```

Les preuves complètes ne sont pas intégrées à l’archive exécutable. Cette séparation évite d’embarquer des journaux, chemins locaux et artefacts d’audit dans la production. Le manifeste JSON relie les deux archives par leurs empreintes.

L’archive de preuves applique une **liste blanche**. Elle contient les journaux de l’exécution courante, les résumés, l’environnement, les résultats machine-readable et les manifestes nécessaires à l’évaluation. Elle n’incorpore jamais :

- une autre archive de preuves ;
- une archive de release ;
- une sauvegarde complète ;
- une base SQLite ;
- `vendor/`, `node_modules/` ou un export statique complet.

Ces artefacts lourds restent vérifiables : `artifacts/evidence-manifest.json` enregistre leur chemin, leur taille et leur empreinte SHA-256. La preuve conserve donc l’information d’intégrité sans dupliquer les payloads ni créer une croissance récursive entre releases.

## Commandes

Préparation interactive :

```bash
python3 tools/cms.py release --interactive-prepare
```

Préparation directe :

```bash
make release-minor RELEASE_NAME="Nom de la release"
make release-major RELEASE_NAME="Nom de la release"
```

Patch sans audit reproductible automatique :

```bash
make patch RELEASE_NAME="Nom du patch"
```

Audit release manuel :

```bash
python3 tools/cms.py audit --profile release --build
```

## Preuve documentaire

Après une mineure ou une majeure réussie, les fichiers suivants sont régénérés :

```text
docs/evaluation/latest-release-audit.md
docs/evaluation/machine-readable/latest-release-audit.json
```

Ils sont des index synthétiques. L’archive de preuves complète demeure dans `storage/exports/<technical_version>/` ou dans le canal de distribution de la release.


## Contrat de compacité et de complétude

Le collecteur officiel est :

```text
tools/python/operations/audit/a1_collect_evidence.py
```

Il produit :

```text
artifacts/evidence-manifest.json
```

Le manifeste décrit la politique active, chaque fichier embarqué, chaque artefact lourd seulement référencé, les tailles et les empreintes. Une archive est considérée conforme lorsque :

1. aucun fichier `.zip`, `.tar.gz`, `.sqlite`, `.sqlite3` ou `.db` n’est embarqué dans `artifacts/` ;
2. tous les journaux des étapes se trouvent sous `logs/` ;
3. `summary.tsv`, `summary.json`, `summary.txt` et `environment.txt` sont présents ;
4. les documents d’évaluation JSON sont présents lorsqu’ils ont été générés ;
5. les releases, sauvegardes et anciennes preuves sont référencées par SHA-256 dans le manifeste, sans être imbriquées.
