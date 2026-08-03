# Benchmark comparatif local/distant — `test_perf.py`

`test_perf.py` produit un benchmark **à faible concurrence**, reproductible et vérifiable, entre une cible locale et une cible distante de webeLi.

Il est volontairement séparé de `test_load.py` :

- `test_perf.py` mesure des temps de réponse répétés en lecture, route par route, avec preuves et seuils p95 ;
- `test_load.py` mesure la montée en charge, les utilisateurs virtuels, les 429, les limites d’infrastructure et la saturation.

Le script est non destructif par défaut : il ne crée pas de contenu, ne publie rien, ne soumet pas de formulaire, ne modifie pas les bases et ne génère pas de gros jeu de données artificiel.

## Ce que le run par défaut démontre

```bash
python3 test_perf.py --yes
```

Valeurs par défaut :

```text
remote-url : https://webe.li/cms/
local-url  : http://127.0.0.1:8080/cms/
mode       : all
iterations : 30
warmup     : 5
```

Le mode `all` mesure, quand les prérequis sont disponibles :

```text
public SSR
API anonyme
API Bearer avec token.csv ou --bearer-token
admin lecture avec users.csv ou --credentials-file
profil de données SQLite local en lecture seule
```

Le rapport déclare aussi explicitement ce qui reste hors périmètre :

```text
charge concurrente        -> utiliser test_load.py
écriture / publication    -> scénario volontaire séparé
base volumineuse générée  -> non créée par défaut
```

## Installation

```bash
python3 -m venv .venv
source .venv/bin/activate
python3 -m pip install requests
```

## Fichiers CSV optionnels

### `users.csv` pour l’admin

Si `users.csv` est placé à côté de `test_perf.py`, il est utilisé automatiquement.

Format recommandé :

```csv
email,password,role,comment
admin@example.test,MotDePasseTemporaire,admin,compte de test
```

Colonnes acceptées pour l’identifiant :

```text
email, username, login, user, nom, name
```

Colonnes acceptées pour le mot de passe :

```text
password, mot_de_passe, motdepasse, pass
```

Le script tente les comptes dans l’ordre jusqu’à obtenir une session valide pour chaque cible. Les mots de passe et cookies ne sont jamais écrits dans les preuves. Les identifiants sont seulement représentés par une empreinte courte non réversible.

Exécution explicite :

```bash
python3 test_perf.py --credentials-file users.csv --yes
```

### `token.csv` pour l’API Bearer

Si `token.csv` est placé à côté de `test_perf.py`, il est utilisé automatiquement.

Format recommandé :

```csv
url,nom,token
http://127.0.0.1:8080/cms/,perf,TOKEN_LOCAL
https://webe.li/cms/,perf,TOKEN_DISTANT
```

Le champ `url` associe chaque token à la bonne cible. Le script accepte aussi les colonnes `base_url`, `target`, `cible`, `nom`, `name`, `env` ou `environment` selon les cas.

Les tokens ne sont jamais écrits dans les preuves. Le rapport indique seulement :

```text
token disponible : oui/non
empreinte courte : sha256(token)[:12]
préflight Bearer : ok/auth_failed/http_error/no_token
```

Le script accepte les valeurs déjà préfixées par `Bearer ` et normalise l’envoi pour éviter `Authorization: Bearer Bearer ...`.

Exécution explicite :

```bash
python3 test_perf.py --tokens-file token.csv --yes
```

Alternative avec un token unique pour local et distant :

```bash
python3 test_perf.py --bearer-token "$WEBELI_API_TOKEN" --yes
```

## Préflight Bearer

Avant de mesurer les routes API protégées, le script exécute un préflight Bearer par cible.

Endpoint par défaut :

```text
/api/v1/search?q=cms
```

Changer l’endpoint :

```bash
python3 test_perf.py \
  --api-bearer-preflight-path api/v1/languages \
  --yes
```

Interprétation :

```text
préflight OK + route 200            -> route mesurée normalement
préflight OK + route 401/403        -> FAIL, token accepté mais route refusée
préflight 401/403                   -> INCONCLUSIVE, token non mesurable sur cette cible
pas de token                        -> INCONCLUSIVE pour les routes Bearer
```

Les requêtes API, y compris le préflight, utilisent `Accept: application/json`.

## Routes mesurées par défaut

### Public SSR

```text
/
/articles
/search?q=cms
/sitemap.xml
/robots.txt
```

### API

```text
/api/v1/health                 anonymous
/api/v1/cookies/config         anonymous
/api/v1/forms/contact          anonymous
/api/v1/media                  anonymous
/api/v1/languages              bearer
/api/v1/menus                  bearer
/api/v1/content                bearer
/api/v1/search?q=cms           bearer
```

Les routes spécifiques dépendantes du jeu de données, par exemple `/api/v1/menus/main` ou `/api/v1/content/pages`, ne sont pas dans le jeu par défaut. Ajoute-les via `--routes-file` si tu veux les contrôler explicitement.

### Admin

```text
/admin/login                   anonymous
/admin/app                     admin-session
/admin/api/context             admin-session
/admin/api/entries             admin-session
/admin/api/media               admin-session
```

Les routes `admin-session` ne sont mesurées que si une authentification admin valide a été obtenue sur la cible concernée.

## Ajouter des routes

```json
{
  "routes": [
    {"area": "public", "name": "custom-page", "method": "GET", "path": "ma-page"},
    {"area": "api", "name": "articles", "method": "GET", "path": "api/v1/content/articles", "auth_policy": "bearer"}
  ]
}
```

Puis :

```bash
python3 test_perf.py --routes-file perf_routes.json --yes
```

Valeurs possibles pour `auth_policy` :

```text
anonymous
bearer
admin-session
```

## Profil de données

Par défaut, le script tente de mesurer le profil de données local en ouvrant les bases SQLite en lecture seule.

Détection automatique, notamment :

```text
../storage/database
storage/database
mod/storage/database
```

Forcer le chemin :

```bash
python3 test_perf.py --db-root ../storage/database --yes
```

Désactiver :

```bash
python3 test_perf.py --no-measure-dataset --yes
```

Compteurs principaux :

```text
sites, languages, content_entries, content_entry_localizations
public_content_snapshots, search_documents, routes, seo_metadata
media_assets, media_asset_variants, menus, menu_items, revisions
iam_users, api_tokens, forms, form_submissions, cookie_consent_logs
```

Profils :

```text
small       : <100 contenus, <250 médias/routes, <500 révisions et <25 MB
medium      : >=100 contenus ou >=250 médias/routes ou >=500 révisions ou >=25 MB
large       : >=1'000 contenus ou >=2'000 médias/routes ou >=5'000 révisions ou >=250 MB
very_large  : >=10'000 contenus ou >=20'000 médias/routes ou >=50'000 révisions ou >=2 GB
unknown     : bases non détectées ou compteurs indisponibles
```

La performance est démontrée sur le profil mesuré, sans extrapolation automatique vers un profil plus gros. Si le profil est `large` ou `very_large`, le rapport peut conclure sur une base volumineuse **uniquement pour les lectures testées**.

## Seuils par défaut

```text
public local p95  : 300 ms
public remote p95 : 900 ms
API local p95     : 300 ms
API remote p95    : 700 ms
admin local p95   : 500 ms
admin remote p95  : 1200 ms
```

Les seuils sont volontairement stricts côté local pour faire remonter les lenteurs du moteur ou de l’environnement de développement. Un `WARN` n’est pas un échec : il indique que la route répond correctement mais dépasse un seuil à surveiller.

Exemple :

```bash
python3 test_perf.py \
  --max-p95-api-local 500 \
  --max-p95-admin-local 700 \
  --yes
```

## Cas particulier du local sous domaine canonique

Quand le CMS local est lancé sur `127.0.0.1`, il peut avoir besoin du domaine canonique pour résoudre le bon site.

Par défaut, si la cible locale est `127.0.0.1`, `localhost` ou `::1`, le script ajoute :

```text
Host: webe.li
X-Forwarded-Proto: https
```

Le transport reste local : `http://127.0.0.1:8080/cms/`. Le rapport vérifie `final_url` et `redirect_count`. Si le local redirige réellement vers une cible externe, la comparaison est marquée non concluante.

Désactiver :

```bash
python3 test_perf.py --no-auto-local-canonical-headers --yes
```

Forcer manuellement :

```bash
python3 test_perf.py \
  --local-host-header webe.li \
  --local-forwarded-proto https \
  --yes
```

## Fichiers de preuve

Chaque run crée :

```text
performance-compare-YYYYMMDD-HHMMSS-xxxxxxxx/
```

Contenu :

```text
comparison.md           rapport lisible
comparison.json         comparaison structurée par route
summary.json            statistiques agrégées
raw_results.csv         une ligne par requête
raw_results.json        données brutes complètes
dataset_profile.json    profil de données local
scope_verdict.json      portée démontrée / non démontrée
metadata.json           paramètres, seuils, préflights, sources CSV
environment.json        environnement Python et machine
routes.json             routes demandées
targets.json            probes local/distant
executed_script.py      copie du script réellement exécuté
manifest.sha256         manifeste d’intégrité
```

Vérification :

```bash
cd performance-compare-YYYYMMDD-HHMMSS-xxxxxxxx
sha256sum -c manifest.sha256
```

## Lecture du rapport

### Statuts route par route

```text
PASS          route mesurée, 100 % succès, seuils respectés
WARN          route mesurée, 100 % succès, mais seuil ou point à surveiller
INCONCLUSIVE  comparaison non valable : token absent/refusé, session absente, réseau, redirection externe
FAIL          erreur forte : 5xx, 401 sur route anonyme, 404 avec auth valide, 0 % succès applicatif
```

### Portée de démonstration

Le rapport distingue :

```text
entièrement démontrée
 démontrée avec avertissements
non concluante
échec
non testée
```

Un run avec des `WARN` peut être exploitable : il démontre que les routes fonctionnent, mais identifie les chemins à optimiser ou les seuils à discuter.

### Ratios distant/local

Un ratio distant/local élevé n’est pas bloquant par défaut si les seuils absolus et les statuts HTTP sont bons. C’est fréquent sur `robots.txt` ou `sitemap.xml`, où le temps local est très faible et le coût réseau/TLS distant devient proportionnellement dominant.

Pour rendre les ratios stricts :

```bash
python3 test_perf.py --strict-ratio-warnings --yes
```

## Commandes utiles

Public seul :

```bash
python3 test_perf.py --mode public --yes
```

API seule :

```bash
python3 test_perf.py --mode api --tokens-file token.csv --yes
```

Admin seul :

```bash
python3 test_perf.py --mode admin --credentials-file users.csv --yes
```

Run complet avec chemins explicites :

```bash
python3 test_perf.py \
  --remote-url https://webe.li/cms/ \
  --local-url http://127.0.0.1:8080/cms/ \
  --credentials-file users.csv \
  --tokens-file token.csv \
  --db-root ../storage/database \
  --iterations 30 \
  --warmup 5 \
  --yes
```

## Limites assumées

`test_perf.py` ne prouve pas :

```text
la tenue en forte charge
le comportement sous pics simultanés
les écritures concurrentes
la publication/dépublication
la performance d’un dataset plus grand que celui mesuré
la robustesse d’un hébergement tiers non testé
```

Pour la charge concurrente, utiliser `test_load.py`. Pour l’écriture/publication, créer un scénario dédié et volontairement destructif ou exécuté sur une base jetable.
