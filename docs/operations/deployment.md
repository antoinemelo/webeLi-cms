---
title: Déployer le CMS
audience:
  - operator
status: stable
version: 1.0
last_verified: 2026-06-14
source_of_truth: procedure
source_paths:
  - tools/python/operations
  - tools/python/qualification/run_all.py

owners:
  - operations
  - documentation
document_type: guide
generated: false
---

# Déployer le CMS

Une release vérifiée peut être transférée par SFTP, rsync, Git ou un pipeline adapté, mais le dépôt source ne doit pas être confondu avec l’artefact de production.

## Configuration minimale

- `APP_ENV=production` ;
- `APP_DEBUG=0` ;
- `APP_PUBLIC_BASE_URL` correspondant au domaine et au sous-répertoire éventuel ;
- extensions PHP et permissions filesystem contrôlées par le préflight.

Le serveur web doit pointer vers le point d’entrée public, par exemple :

```apache
DocumentRoot /chemin/vers/mod/backend/public
```

Pour un hébergement mutualisé, préservez les bases et médias, empêchez l’accès web aux fichiers sensibles et testez explicitement l’installation dans un sous-répertoire.

## Mise à jour et rollback

1. créez une sauvegarde SQLite ;
2. vérifiez la release ;
3. appliquez la mise à jour sans écraser les données protégées ;
4. exécutez les migrations prévues ;
5. contrôlez la connexion, les routes publiques et les journaux ;
6. utilisez le rollback documenté en cas d’échec.

Ne publiez jamais directement `storage/exports/`. Les exports statiques doivent être préparés et vérifiés séparément.

## En-têtes de sécurité et export statique

En production, configurez une politique `Content-Security-Policy` adaptée au thème, aux médias, aux scripts et aux intégrations réellement autorisés. La politique doit être définie au niveau du serveur web ou du frontal HTTP, puis vérifiée après chaque changement de thème ou d’intégration externe.

Les fichiers produits par l’export statique sont préparés sous `storage/exports/static`. Un **manifest d’export statique** doit accompagner l’artefact afin d’identifier les fichiers générés, leur contexte et les contrôles effectués.

Ne jamais publier directement `storage/exports/`. Seul l’artefact statique explicitement préparé et vérifié doit être transféré vers la destination publique.
