# Notes de déploiement

## Pré-requis

- PHP 8.2+.
- Extensions PHP : `pdo`, `pdo_sqlite`, `sqlite3`, `mbstring`, `json`, `session`, `fileinfo`.
- Extension image recommandée : `gd` ou `imagick`.
- Composer/Twig selon l’installation choisie.
- Cron ou worker si les tâches asynchrones sont activées.
- Droits d’écriture PHP sur `storage/database`, `storage/cache`, `storage/cache/twig`, `storage/uploads`, `storage/logs`, `storage/exports` et `storage/backups/sqlite`.

## Déploiement minimal

1. Décompresser l’archive release.
2. Copier `ops/_env.example` vers `ops/.env` ou définir les variables dans l’hébergement.
3. Garder en production :

```env
APP_ENV=production
APP_DEBUG=0
APP_AUTO_MAINTENANCE=0
APP_PUBLIC_MODULE_ROUTES=0
APP_PUBLIC_API_MODULE_ROUTES=0
APP_ADMIN_MODULE_ROUTES=1
```

4. Configurer `APP_BASE_PATH` et `APP_PUBLIC_BASE_URL`.
5. Installer les dépendances Composer/Twig et Vue/node_modules aux emplacements déclarés si nécessaire.
6. Pointer idéalement le document root sur `backend/public` ou conserver le `index.php` racine qui relaie vers `backend/public/index.php`.
7. Donner les droits d’écriture :

```bash
mkdir -p storage/database storage/cache/twig storage/uploads storage/logs storage/exports storage/backups/sqlite
chmod -R ug+rwX storage/database storage/cache storage/uploads storage/logs storage/exports storage/backups
```

8. Lancer :

```bash
APP_ENV=production APP_PUBLIC_BASE_URL=https://example.org python3 tools/python/d1_preflight_local.py --target production
python3 tools/cms.py qualify --profile release
```

## Release v1

Une release v1 standard inclut les bases SQLite seedées sous `storage/database/*.sqlite`. Le packaging exclut d’abord les bases locales du parcours générique, puis injecte explicitement les quatre bases attendues (`core`, `iam`, `forms`, `cookies`) après contrôle d’intégrité. Cela évite d’embarquer silencieusement des bases locales accidentelles tout en conservant une installation propre sans migration obligatoire.

Packaging et vérification :

```bash
python3 tools/python/d_deploy.py
python3 tools/python/d_deploy.py verify
```

Archive source sans bases, uniquement si nécessaire :

```bash
python3 tools/python/d_deploy.py package --exclude-databases
python3 tools/python/d4_verify_release_archive.py --allow-without-databases
```

Le packaging exclut les logs, caches, uploads locaux, exports, secrets, fichiers SQLite temporaires, `vendor/`, `backend/vendor/` et `node_modules/` sauf option explicite.

## Chemins de dépendances configurables

Les dépendances externes ne doivent pas être codées en dur dans les scripts. Les chemins suivants sont résolus depuis la racine du projet :

```env
APP_VUE_NODE_MODULES_PATH=./vendor/node_modules/
APP_TWIG_VENDOR_PATH=./vendor/twig/
```

Exemples acceptés :

```env
APP_VUE_NODE_MODULES_PATH=./frontend/admin-vue/node_modules/
APP_VUE_NODE_MODULES_PATH=../vendor/node_modules/
APP_TWIG_VENDOR_PATH=../vendor/twig/
```

Les chemins extérieurs au projet peuvent être utilisés localement, mais ne sont jamais inclus dans les releases.

## Préflight conseillé

```bash
python3 tools/python/d_deploy.py preflight
```

Le préflight vérifie notamment :

- version PHP ;
- extensions PHP critiques ;
- chargement des classes PHP ;
- droits de `storage/` ;
- accès lecture/écriture et intégrité des bases SQLite ;
- chemins publics et variables `APP_BASE_PATH`, `APP_PUBLIC_BASE_URL`, `APP_ENV`, `APP_DEBUG` ;
- présence du build Vue ;
- protections `.htaccess` des dossiers sensibles ;
- smoke test console si disponible.

## Backup / restore / rollback

Sauvegarde avant mise à jour :

```bash
python3 tools/python/d_deploy.py backup
```

Restauration :

```bash
python3 tools/python/d_deploy.py restore --archive storage/backups/sqlite/<backup>.zip --yes
```

Rollback manuel :

1. remettre l’ancienne archive applicative ;
2. restaurer la sauvegarde SQLite ;
3. vider `storage/cache/*` hors `.gitkeep` si nécessaire ;
4. relancer le préflight ;
5. tester front public, back-office et API headless.

## Sécurité serveur

Les dossiers suivants doivent rester privés et non indexables :

```text
storage/
storage/database/
storage/logs/
storage/backups/
database/
tools/
ops/
```

`storage/media/` est le seul dossier de stockage pouvant servir des fichiers publics. Il bloque les scripts, binaires, bases et la quarantaine.

## Apache

Configuration recommandée :

```apache
DocumentRoot /chemin/vers/mod/backend/public
AllowOverride All
```

Si le document root reste à la racine du package, vérifier que `.htaccess` est actif et que les dossiers sensibles ne sont pas téléchargeables.

## Nginx

Exemple minimal :

```nginx
root /chemin/vers/mod/backend/public;
index index.php;

location / {
    try_files $uri /index.php?$query_string;
}

location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
}

location ~ ^/(storage|database|tools|ops)/ {
    deny all;
}
```

## Suivi des mises à jour

Les scripts écrivent des marqueurs JSON dans `storage/deployments/` :

```text
storage/deployments/release-manifest.json
storage/deployments/current.json
storage/deployments/history.ndjson
storage/deployments/releases/<release_id>.json
```

Ces fichiers servent au diagnostic et au suivi. Ils ne doivent pas être accessibles publiquement.
