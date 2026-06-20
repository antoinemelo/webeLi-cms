---
title: Dépanner les preuves d’audit
audience:
  - evaluator
  - developer
status: stable
version: 1.1
last_verified: 2026-06-15
source_of_truth: manual
source_paths:
  - backend/bootstrap/runtime.php
  - tools/audit/run-audit.sh
  - tools/python/operations/audit/a1_collect_evidence.py
owners:
  - core
document_type: evaluation
generated: false
---
# Dépanner les preuves d’audit

## Double déclaration d’un provider PHP

Le runtime charge exactement un autoloader Composer pour l’espace `App\\`.
L’autoloader Composer du backend est prioritaire lorsqu’il déclare les classes du
CMS. Le chargeur PSR-4 local n’est enregistré qu’en absence d’un mapping
Composer `App\\`.

Cette règle évite qu’un chemin Twig charge un premier `vendor/autoload.php`, puis
que le bootstrap charge un second autoloader déclarant les mêmes classes du
CMS. Un symptôme typique était :

```text
Cannot redeclare class App\Modules\Forms\FormsModuleProvider
```

Contrôle conseillé :

```bash
composer dump-autoload --working-dir backend --optimize --strict-psr
python3 tools/cms.py rebuild
```

Cette commande recrée les bases de la copie de test utilisée pour l’audit. Elle ne correspond pas à une procédure de mise à jour d’une instance de production avec contenu.

## Preuve de sauvegarde et restauration

La comparaison SHA-256 porte uniquement sur les bases actives de
`storage/database/`. Les sauvegardes techniques créées dans
`storage/backups/sqlite/pre-restore-*` sont volontairement exclues : elles ne
font pas partie de l’état runtime à comparer.

Les fichiers produits sont :

```text
artifacts/database-hashes-before.txt
artifacts/database-hashes-after.txt
```

Le contrôle réussit uniquement lorsque leur contenu est identique.


## Archive de preuves anormalement volumineuse

Une preuve récente ne doit jamais contenir une ancienne preuve, une release ou une sauvegarde complète. Vérifiez son contenu avec :

```bash
unzip -l storage/exports/<version>/<version>-audit-evidence.zip
```

Les payloads lourds doivent apparaître uniquement dans :

```text
artifacts/evidence-manifest.json
```

avec `embedded: false`, leur taille et leur SHA-256. La collecte est volontairement fondée sur une liste blanche. Il ne faut pas réintroduire un `find storage ... -name '*.zip'` générique dans `tools/audit/run-audit.sh`, car cette approche imbrique les anciennes archives et fait croître chaque nouvelle preuve.

Pour contrôler automatiquement le contrat :

```bash
python3 -m unittest tools.python.tests.test_audit_evidence_collection
```
