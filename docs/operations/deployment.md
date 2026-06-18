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
  - tools/admin.py

owners:
  - operations
  - documentation
document_type: guide
generated: false
---

# Déployer le CMS

Une release vérifiée peut être transférée par SFTP, rsync, Git, FTP/FTPS ou un pipeline adapté, mais le dépôt source ne doit pas être confondu avec l’artefact de production.

## Workflow GitHub recommandé

Pour les environnements `webe.li/mod` et `webe.li/eve`, le dépôt GitHub peut servir à synchroniser le code lorsque l'hébergement donne accès à SSH et Git. Lorsque l'hébergement ne doit pas exécuter Git, les scripts Python préparent une release et le transfert FTP/FTPS publie l'artefact vérifié. La procédure Hostpoint détaillée est documentée dans [Déployer sur Hostpoint avec Git ou FTP](hostpoint-git-ftp.md).

Le modèle recommandé est :

```text
staging -> webe.li/mod
main    -> webe.li/eve
```

Avant de pousser une modification vers GitHub, exécutez les contrôles depuis la racine du projet, idéalement via le menu officiel :

```bash
python3 tools/admin.py
```

Utilisez ensuite les actions de qualification, préparation de release et sauvegarde selon l'intervention. La façade `tools/admin.py` appelle `tools/cms.py`, qui reste le point d'entrée stable des contrôles automatisables.

Sur l'environnement de test local :

```bash
git checkout staging
git pull --ff-only
python3 tools/admin.py
git push origin staging
```

Sur le serveur `webe.li/mod`, si Git est disponible :

```bash
git fetch origin
git checkout staging
git pull --ff-only
python3 tools/python/operations/deployment/d1_preflight_local.py --target staging
```

Pour promouvoir une version testée vers la production, créez une pull request `staging -> main`, validez-la, puis mettez à jour `webe.li/eve` depuis `main`.

Git versionne le code, les scripts, les templates, les assets compilés, les migrations et les seeds. Il ne doit pas versionner l'état runtime local :

```text
ops/.env
ops/ftp.deploy.json
storage/database/*.sqlite
storage/media/
storage/uploads/
storage/security/
storage/logs/
storage/backups/
storage/exports/
```

Un retour à un ancien commit restaure le code, mais pas automatiquement les bases SQLite ni les médias. Pour un rollback complet, restaurez aussi la sauvegarde SQLite et les fichiers runtime associés.

## Déploiement FTP/FTPS par les scripts Python

Le workflow FTP/FTPS part d'une release préparée localement, pas d'une copie manuelle du répertoire de travail. Il est piloté par `tools/python/operations/deployment/d_deploy.py` et par la configuration locale ignorée `ops/ftp.deploy.json`.

Préparer et vérifier l'artefact :

```bash
python3 tools/admin.py
python3 tools/cms.py release --package --verify-archive
```

Configurer le transfert :

```bash
cp ops/ftp.deploy.example.json ops/ftp.deploy.json
```

Le fichier `ops/ftp.deploy.json` doit contenir le serveur FTP/FTPS, l'utilisateur, le mot de passe, le mode TLS et le `remote_root`, par exemple `/www/webe.li/mod` pour l'environnement de test.

Simuler puis appliquer :

```bash
python3 tools/python/operations/deployment/d_deploy.py ftp-dry-run
python3 tools/python/operations/deployment/d_deploy.py ftp-deploy
```

Le transfert FTP publie le staging de release préparé sous `storage/exports/release_stage`. Il ne doit pas écraser les fichiers runtime protégés sans sauvegarde et vérification explicites.

Depuis `python3 tools/admin.py`, un déploiement FTP réussi déclenche ensuite une proposition Git optionnelle : commit des changements locaux, puis push si la branche suit déjà une upstream. Cette étape permet de garder GitHub cohérent avec ce qui vient d'être validé et transféré, sans imposer Git aux environnements qui utilisent uniquement FTP.

## Clonage local d'une instance

Pour préparer une copie locale exploitable sous un autre dossier, utilisez `tools/cms.py instance clone`. Cette commande sépare le dossier créé du chemin public configuré :

```bash
python3 tools/cms.py instance clone --destination ../mod2 --new-base-path /mod
```

L'exemple crée `../mod2`, mais conserve la configuration publique `/mod`. Cette séparation est utile pour préparer un dossier temporaire qui sera renommé plus tard. Voir [Cloner une instance locale](instance-clone.md).

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
