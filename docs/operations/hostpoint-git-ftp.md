---
title: Déployer sur Hostpoint avec Git ou FTP
audience:
  - operator
  - installer
  - superadministrator
status: stable
version: 1.0
last_verified: 2026-06-18
source_of_truth: procedure
source_paths:
  - tools/admin.py
  - tools/cms.py
  - tools/python/operations/deployment/d_deploy.py
  - tools/python/operations/deployment/d3_deploy_ftp.py
  - ops/ftp.deploy.example.json

owners:
  - operations
  - documentation
document_type: guide
generated: false
---

# Déployer sur Hostpoint avec Git ou FTP

Cette procédure décrit les deux modes supportés pour publier le CMS sur l'hébergement Hostpoint `webe.li` :

- synchronisation Git via SSH lorsque le shell Hostpoint dispose de `git` ;
- release préparée par les scripts Python, puis transfert FTP/FTPS lorsque Git n'est pas utilisé sur le serveur.

Dans les deux cas, les scripts Python restent la porte de validation. Git synchronise le code ; FTP synchronise un artefact préparé. Aucun des deux ne remplace les sauvegardes SQLite, la configuration locale ni les contrôles de préflight.

## Cibles recommandées

```text
staging -> https://webe.li/mod -> branche Git staging
main    -> https://webe.li/eve -> branche Git main
```

`/mod` est l'environnement de test public. `/eve` est l'environnement de production. Les deux environnements doivent avoir chacun leur fichier `ops/.env`, leurs bases SQLite, leurs médias, leurs logs et leurs secrets.

## Fichiers locaux non versionnés

Ces fichiers et dossiers sont propres à chaque environnement et ne doivent pas être pris comme source de vérité Git :

```text
ops/.env
ops/ftp.deploy.json
storage/database/*.sqlite
storage/database/*-wal
storage/database/*-shm
storage/media/
storage/uploads/
storage/security/
storage/logs/
storage/backups/
storage/exports/
```

Pour `/mod`, la configuration minimale attendue dans `ops/.env` est :

```env
APP_ENV=staging
APP_DEBUG=0
APP_BASE_PATH=/mod
APP_PUBLIC_BASE_URL=https://webe.li/mod
APP_SESSION_NAME=amcms_mod
```

Générez aussi une valeur stable pour `APP_PREVIEW_SIGNING_KEY` :

```bash
openssl rand -hex 32
```

## Accès SSH Hostpoint

L'accès SSH Hostpoint utilise le compte principal d'hébergement. Pour l'hébergement `amelos8`, la connexion prend la forme :

```bash
ssh amelos8@amelos8.ssh.cloud.hostpoint.ch
```

La première connexion demande de confirmer l'empreinte du serveur. La clé publique de votre poste local peut être enregistrée dans le Control Panel Hostpoint pour éviter l'usage du mot de passe.

## Option A : Git sur Hostpoint

Cette option est recommandée lorsque le shell Hostpoint permet `git clone`, `git pull` et SSH vers GitHub.

### Première installation de `/mod`

Sur Hostpoint :

```bash
cd ~/www/webe.li
git clone --branch staging --single-branch git@github.com:antoinemelo/webeLi-cms.git mod
cd mod
cp ops/.env.example ops/.env
```

Adaptez ensuite `ops/.env`, créez les dossiers d'écriture et initialisez ou transférez les bases :

```bash
mkdir -p storage/database storage/cache/twig storage/uploads storage/logs storage/exports storage/backups/sqlite storage/security
chmod -R ug+rwX storage/database storage/cache storage/uploads storage/logs storage/exports storage/backups storage/security
python3 tools/python/operations/database/a_db_init.py --with-seed
python3 tools/python/operations/deployment/d1_preflight_local.py --target staging
```

Si l'environnement doit reprendre un contenu existant, restaurez les bases et médias avant le préflight au lieu de relancer un seed de démonstration.

### Mise à jour de `/mod`

Sur le poste de développement :

```bash
git checkout staging
git pull --ff-only
python3 tools/admin.py
git push origin staging
```

Sur Hostpoint :

```bash
cd ~/www/webe.li/mod
git pull --ff-only
python3 tools/python/operations/deployment/d1_preflight_local.py --target staging
```

Si des migrations SQLite sont prévues, créez d'abord une sauvegarde puis appliquez-les explicitement :

```bash
python3 tools/python/operations/deployment/d_deploy.py backup
python3 tools/python/operations/deployment/d_deploy.py migrate-plan
python3 tools/python/operations/deployment/d_deploy.py migrate --backup --yes
```

### Promotion vers `/eve`

Après validation sur `/mod`, ouvrez et validez une pull request `staging -> main`. Sur Hostpoint, `/eve` doit suivre `main` :

```bash
cd ~/www/webe.li/eve
git pull --ff-only
python3 tools/python/operations/deployment/d1_preflight_local.py --target production
```

Une promotion de code ne restaure pas automatiquement les bases ni les médias. Pour revenir à un état fonctionnel complet, utilisez Git pour le code et les sauvegardes pour les données runtime.

## Option B : scripts Python puis FTP/FTPS

Cette option est utile lorsque le shell distant ne doit pas utiliser Git, ou lorsque l'on veut publier uniquement un artefact préparé.

Sur le poste de développement, préparez et vérifiez la release :

```bash
git checkout staging
git pull --ff-only
python3 tools/admin.py
python3 tools/cms.py release --package --verify-archive
```

Configurez ensuite le transfert FTP localement. Le fichier réel `ops/ftp.deploy.json` est ignoré par Git :

```bash
cp ops/ftp.deploy.example.json ops/ftp.deploy.json
```

Exemple pour `/mod` :

```json
{
  "host": "sl95.web.hostpoint.ch",
  "port": 21,
  "username": "VOTRE_UTILISATEUR_FTP",
  "password": "CHANGE_ME",
  "tls": true,
  "timeout": 30,
  "passive": true,
  "remote_root": "/www/webe.li/mod"
}
```

Simulez d'abord le transfert :

```bash
python3 tools/python/operations/deployment/d_deploy.py ftp-dry-run
```

Puis publiez :

```bash
python3 tools/python/operations/deployment/d_deploy.py ftp-deploy
```

Le déploiement FTP envoie le staging de release préparé sous `storage/exports/release_stage` et écrit les marqueurs de déploiement dans `storage/deployments/`. Il ne doit pas être remplacé par une copie manuelle du répertoire de développement.

Lorsque le transfert FTP est lancé depuis le menu interactif :

```bash
python3 tools/admin.py
```

et que l'opération réussit, le menu propose aussi une synchronisation Git optionnelle :

1. afficher l'état Git local ;
2. créer un commit avec les changements actuels si le dépôt contient des modifications ;
3. pousser la branche si une upstream est configurée et que des commits locaux sont en avance.

Cette étape ne remplace pas le transfert FTP. Elle sert à garder GitHub aligné avec les changements validés localement, afin que le serveur Git ou les autres postes puissent les récupérer ensuite.

## Choisir entre Git et FTP

Utilisez Git sur Hostpoint lorsque vous voulez une synchronisation simple du code et des rollbacks de code rapides. C'est le meilleur choix pour `/mod` tant que les préflights passent sur le serveur.

Utilisez FTP/FTPS lorsque vous voulez publier un paquet explicitement préparé, ou lorsque Git n'est pas disponible côté serveur. C'est aussi le mode adapté si l'accès serveur est limité aux identifiants FTP.

Dans les deux workflows :

1. sauvegardez avant une opération sensible ;
2. exécutez les contrôles Python ;
3. ne versionnez pas les secrets ni les bases vivantes ;
4. vérifiez `https://webe.li/mod`, `/admin` et `/api/v1/health` après publication ;
5. documentez la version déployée et la sauvegarde associée.
