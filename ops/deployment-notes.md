# Notes de déploiement

## Pré-requis

- PHP 8.2+.
- Extensions PHP : `pdo`, `pdo_sqlite`, `sqlite3`, `mbstring`, `json`, `session`, `fileinfo`.
- Extension image recommandée : `gd` ou `imagick`.
- Composer/Twig selon l’installation choisie.
- Cron ou worker si les tâches asynchrones sont activées.
- Droits d’écriture PHP sur `storage/database`, `storage/cache`, `storage/cache/twig`, `storage/uploads`, `storage/logs`, `storage/exports` et `storage/backups/sqlite`.

## Déploiement minimal

1. Décompresser l’archive release ou cloner la branche Git attendue lorsque le serveur est administré par Git.
2. Copier `ops/.env.example` vers `ops/.env` ou définir les variables dans l’hébergement.
3. Garder en production :

```env
APP_ENV=production
APP_DEBUG=0
APP_AUTO_MAINTENANCE=0
APP_PUBLIC_MODULE_ROUTES=0
APP_PUBLIC_API_MODULE_ROUTES=1
APP_ADMIN_MODULE_ROUTES=1
```

4. Configurer `APP_BASE_PATH` et `APP_PUBLIC_BASE_URL`. Les routes API publiques des modules doivent rester actives pour le panier de la boutique native ; chaque canal public demeure contrôlé par son site, sa langue et son état d’activation.
5. Installer les dépendances Composer/Twig et Vue/node_modules aux emplacements déclarés si nécessaire.
6. Pointer idéalement le document root sur `backend/public` ou conserver le `index.php` racine qui relaie vers `backend/public/index.php`.
7. Donner les droits d’écriture :

```bash
mkdir -p storage/database storage/cache/twig storage/uploads storage/logs storage/exports storage/backups/sqlite
chmod -R ug+rwX storage/database storage/cache storage/uploads storage/logs storage/exports storage/backups
```

8. Lancer :

```bash
APP_ENV=production APP_PUBLIC_BASE_URL=https://example.org python3 tools/python/operations/deployment/d1_preflight_local.py --target production
python3 tools/cms.py qualify --profile release
```

## Option Git sur Hostpoint

Pour `webe.li/cms`, le serveur peut suivre la branche `staging` si SSH, Git et l'accès GitHub sont configurés :

```bash
cd ~/www/webe.li
git clone --branch staging --single-branch git@github.com:antoinemelo/webeLi-cms.git mod
cd mod
cp ops/.env.example ops/.env
python3 tools/python/operations/deployment/d1_preflight_local.py --target staging
```

Les mises à jour suivantes se font par :

```bash
cd ~/www/webe.li/cms
git pull --ff-only
python3 tools/python/operations/deployment/d1_preflight_local.py --target staging
```

Git synchronise le code. Les bases SQLite, médias, secrets, logs, sauvegardes et exports restent propres à chaque environnement.

## Option FTP/FTPS pilotée par Python

Lorsque Git n'est pas utilisé sur le serveur, préparez l'artefact localement puis transférez le staging de release :

```bash
python3 tools/admin.py
python3 tools/cms.py release --package --verify-archive
cp ops/ftp.deploy.example.json ops/ftp.deploy.json
python3 tools/python/operations/deployment/d_deploy.py ftp-dry-run
python3 tools/python/operations/deployment/d_deploy.py ftp-deploy
```

Pour Hostpoint `/cms`, `ops/ftp.deploy.json` doit pointer vers le répertoire distant `/www/webe.li/cms`. Ce fichier contient des identifiants et doit rester hors Git.

Depuis le menu interactif `python3 tools/admin.py`, un FTP réussi affiche l'état Git. Le push optionnel n'est proposé que si le worktree est propre et si la branche contient des commits locaux. Le menu ne crée jamais de commit : les fichiers doivent être sélectionnés et revus séparément.

## Clone local d'instance

Pour préparer un dossier temporaire en séparant le nom du dossier et la configuration publique :

```bash
python3 tools/cms.py --dry-run instance clone --destination ../mod2 --new-base-path /cms
python3 tools/cms.py instance clone --destination ../mod2 --new-base-path /cms
```

Le clone copie aussi les bases SQLite et médias locaux. Pour préparer une future instance `/eve` dans un dossier `eve2`, utilisez `--destination ../eve2 --new-base-path /eve`.

`--new-base-path` est obligatoire et doit contenir le chemin public final exact, indépendamment du nom du dossier local : `/cms/main`, `/new/main`, `/cms2`, etc. Le chemin public source est lu depuis `ops/.env`, puis réécrit dans les fichiers texte et les cellules SQLite du clone. L’option 9 de `tools/admin.py` demande explicitement cette valeur et propose une URL publique cohérente, qui reste modifiable.

Les dossiers `vendor/`, `backend/vendor/` et `node_modules/` ne sont jamais copiés dans un clone. Le `.env` cloné conserve les chemins configurés, mais le runtime ne dépend pas du nom de l’instance. Il vérifie, dans cet ordre : `backend/vendor`, `vendor`, le `vendor` du parent, le dossier partagé conventionnel `cms/vendor` à la racine web, puis le `vendor` situé deux niveaux au-dessus de l’instance. Pour `/cms/edu/edu1`, la dernière option rejoint ainsi `/cms/vendor`; pour `/eve`, l’option conventionnelle peut rejoindre `/cms/vendor` même si l’instance n’est pas rangée sous `cms`. `APP_TWIG_VENDOR_PATH` reste un emplacement personnalisé supplémentaire.

## Release v1

Une release v1 standard inclut les bases SQLite seedées sous `storage/database/*.sqlite`. Le packaging exclut d’abord les bases locales du parcours générique, puis injecte explicitement les quatre bases attendues (`core`, `iam`, `forms`, `cookies`) après contrôle d’intégrité. Cela évite d’embarquer silencieusement des bases locales accidentelles tout en conservant une installation propre sans migration obligatoire.

Packaging et vérification :

```bash
python3 tools/python/operations/deployment/d_deploy.py
python3 tools/python/operations/deployment/d_deploy.py verify
```

Archive source sans bases, uniquement si nécessaire :

```bash
python3 tools/python/operations/deployment/d_deploy.py package --exclude-databases
python3 tools/python/operations/deployment/d4_verify_release_archive.py --allow-without-databases
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

Les chemins extérieurs au projet peuvent être utilisés localement, mais ne sont jamais inclus dans les releases. La recherche automatique permet de partager Twig depuis un parent proche, depuis le `cms/vendor` conventionnel ou depuis un parent plus haut. Cette recherche n'importe pas l'autoload `App\\` d'une autre instance : seul `backend/vendor/autoload.php` peut être l'autoload Composer canonique de l'application, tandis que Twig est détecté séparément dans les cinq emplacements pris en charge.

## Préflight conseillé

```bash
python3 tools/python/operations/deployment/d_deploy.py preflight
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
python3 tools/python/operations/deployment/d_deploy.py backup
```

Restauration :

```bash
python3 tools/python/operations/deployment/d_deploy.py restore --archive storage/backups/sqlite/<backup>.zip --yes
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
DocumentRoot /chemin/vers/cms/backend/public
AllowOverride All
```

Si le document root reste à la racine du package, vérifier que `.htaccess` est actif et que les dossiers sensibles ne sont pas téléchargeables.

## Nginx

Exemple minimal :

```nginx
root /chemin/vers/cms/backend/public;
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
