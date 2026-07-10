---
title: Export statique éditorial
audience:
  - administrator
status: stable
last_verified: 2026-06-24
source_of_truth: procedure
source_paths:
  - backend/src/StaticExport
  - tools/python/commands/export.py
  - tools/python/tests/test_static_export_multisite.py
owners:
  - operations
  - documentation
document_type: guide
generated: false
---

# Export statique éditorial

L’export statique se prépare depuis **Production éditoriale > Imports / Exports** ou via la commande :

```bash
python3 tools/cms.py export --all-languages --output storage/exports/check
```

Le répertoire `storage/exports/` ne doit jamais être publié directement ; seul le contenu de `public/` dans la release d’export explicitement vérifiée peut être déployé.

## Isolation multisite

Un export pouvant contenir plusieurs sites ne doit jamais écrire deux routes vers le même fichier. Les routes HTML sont donc préfixées par le domaine et le `base_path` primaire du site, avec fallback sur `site_key` si aucun domaine n’est disponible.

Exemple de sortie attendue :

```text
public/webe.li/mod/index.html
public/webe.li/mod/en/index.html
public/webe.li/mod/site-a/index.html
public/webe.li/mod/site-a/en/news/index.html
public/webe.li/mod/site-b/index.html
public/webe.li/mod/site-b/de/nachrichten/index.html
```

Les fichiers techniques propres au site, comme `sitemap.xml` et `robots.txt`, sont écrits dans le préfixe de sortie du site concerné.

## Rapport de contrôle

Chaque export écrit `static-export-report.json`. Le bloc `output_plan` doit être contrôlé avant publication :

```json
{
  "strategy": "domain_base_path_then_site_key",
  "collision_count": 0,
  "invalid_output_path_count": 0,
  "output_paths_total": 62,
  "unique_output_paths": 62,
  "sites": []
}
```

Critères minimaux :

- `collision_count` vaut `0` ;
- `invalid_output_path_count` vaut `0` ;
- `output_paths_total` est égal à `unique_output_paths` ;
- chaque site a un `output_prefix` non vide.

Si une collision non résolue est détectée, l’export échoue avant l’écriture des pages HTML et le rapport liste les routes concernées dans `output_plan.collisions`.
